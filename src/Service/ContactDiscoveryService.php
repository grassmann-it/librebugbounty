<?php
namespace App\Service;

use App\Entity\Finding;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

final class ContactDiscoveryService
{
    private array $providers = [];
    public function __construct(private readonly Connection $connection, private readonly FindingWorkPolicy $policy,
        #[AutowireIterator('app.contact_discovery_provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            if (!$provider instanceof ContactDiscoveryProviderInterface || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $provider->id()) || isset($this->providers[$provider->id()])) throw new \LogicException('Invalid or duplicate contact provider.');
            $this->providers[$provider->id()] = $provider;
        }
    }
    public function providers(): array { return array_map(static fn ($provider) => $provider->label(), $this->providers); }
    public function providerOptions(Finding $finding): array
    {
        $options = [];
        foreach ($this->providers as $id => $provider) {
            $options[$id] = ['configured' => !$provider instanceof ExplicitContactDiscoveryProviderInterface || $provider->configured(),
                'requiresConsent' => $provider instanceof ExplicitContactDiscoveryProviderInterface];
            if ($provider instanceof ExplicitContactDiscoveryProviderInterface) {
                $available = self::explicitLookupAvailable($this->connection);
                $rows = $available ? $this->connection->fetchAllAssociative('SELECT state, result, reserved_credits FROM hunter_domain_lookup WHERE domain=?', [strtolower(rtrim($finding->getDomain()->getHostname(), '.'))]) : [];
                $reserved = 0; $blocked = false; $cached = false;
                foreach ($rows as $row) {
                    $reserved += (int) $row['reserved_credits'];
                    $blocked = $blocked || in_array($row['state'], ['pending', 'unknown'], true);
                    $result = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
                    $cached = $cached || ($row['state'] === 'completed' && in_array($result['status'] ?? '', ['found', 'not_found'], true)
                        && is_string($result['expires'] ?? null) && new \DateTimeImmutable($result['expires']) > new \DateTimeImmutable());
                }
                $options[$id] += ['available' => $available, 'reserved' => $reserved, 'limit' => 4, 'blocked' => $blocked, 'cached' => $cached];
            }
        }
        return $options;
    }
    public function history(Finding $finding): array
    {
        if (!FindingWorkPolicy::available($this->connection)) return [];
        $rows = $this->connection->fetchAllAssociative('SELECT id, provider, result, fetched_at FROM contact_discovery WHERE finding_id = ? ORDER BY fetched_at DESC, rowid DESC LIMIT 10', [$finding->getId()]);
        foreach ($rows as &$row) {
            $row['result'] = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
            if (isset($row['result']['case_binding']) && !self::bindingCurrent($this->connection, $finding, $row['result'])) {
                $row['result']['status'] = 'expired';
                $row['result']['warnings'][] = 'Fall oder Domain haben sich seit der Hunter-Abfrage geändert. Die Vorschläge sind nicht mehr aktuell.';
            }
        }
        return $rows;
    }
    public function discover(Finding $finding, string $providerId, array $input = []): void
    {
        $this->policy->assertDiscoveryAllowed($finding);
        $provider = $this->providers[$providerId] ?? throw new \InvalidArgumentException('Unbekannter Kontaktanbieter.');
        if ($provider instanceof ExplicitContactDiscoveryProviderInterface) {
            $this->discoverExplicit($finding, $provider, $input);
            return;
        }
        $id = Uuid::v7()->toRfc4122();
        $pending = ['status' => 'pending', 'source' => '', 'contacts' => [], 'policies' => [], 'languages' => [], 'expires' => null, 'warnings' => []];
        // A single write statement coalesces concurrent requests without a
        // read-then-write transaction upgrade in SQLite.
        $inserted = $this->connection->executeStatement(
            'INSERT INTO contact_discovery (id, finding_id, provider, result, fetched_at) '
            .'SELECT :id, :finding, :provider, :result, :now WHERE NOT EXISTS ('
            .'SELECT 1 FROM contact_discovery WHERE finding_id = :finding AND provider = :provider AND fetched_at >= :since)',
            ['id' => $id, 'finding' => $finding->getId(), 'provider' => $providerId,
                'result' => json_encode($pending, JSON_THROW_ON_ERROR),
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'since' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')],
        );
        if ($inserted === 0) return;
        try {
            $this->policy->assertDiscoveryAllowed($finding);
            $result = $provider->discover($finding->getDomain()->getHostname())->toArray();
        } catch (\Throwable) {
            $result = ['status' => 'error', 'source' => '', 'contacts' => [], 'policies' => [], 'languages' => [], 'expires' => null, 'warnings' => ['Kontaktdaten konnten nicht abgerufen werden.']];
        }
        $this->connection->update('contact_discovery', ['result' => json_encode($result, JSON_THROW_ON_ERROR)], ['id' => $id]);
    }

    public static function explicitLookupAvailable(Connection $connection): bool
    {
        return (bool) $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='hunter_domain_lookup'");
    }

    private function discoverExplicit(Finding $finding, ExplicitContactDiscoveryProviderInterface $provider, array $input): void
    {
        if (!$provider->configured()) throw new \InvalidArgumentException('Hunter.io ist noch nicht auf dem Server konfiguriert.');
        if (!self::explicitLookupAvailable($this->connection)) throw new \InvalidArgumentException('Bitte zuerst die Datenbankmigration für Hunter-Abfragen ausführen.');
        $binding = self::bindingFor($this->connection, $finding);
        $domain = $binding['domain'];
        if (($input['consent'] ?? null) !== '1' || ($input['expected_domain'] ?? null) !== $domain) {
            throw new \InvalidArgumentException('Bestätige die angezeigte Domain und den möglichen Hunter-Creditverbrauch.');
        }
        if (!HunterContactProvider::publicHostname($domain)) throw new \InvalidArgumentException('Nur öffentliche DNS-Hostnamen werden abgefragt.');
        $id = Uuid::v7()->toRfc4122();
        $pending = ['status' => 'pending', 'source' => '', 'contacts' => [], 'policies' => [], 'languages' => [], 'expires' => null, 'warnings' => [],
            'metadata' => ['request_state' => 'pending']];
        $reservation = $this->connection->transactional(function () use ($finding, $provider, $binding, $domain, $id, $pending): array {
            // The ledger is independent of cases: deleting a case cannot erase an
            // uncertain paid request, its cache or this application's budget usage.
            $this->connection->executeStatement('UPDATE hunter_domain_lookup SET fetched_at=fetched_at WHERE domain=?', [$domain]);
            $fresh = self::bindingFor($this->connection, $finding);
            if (!hash_equals($binding['fingerprint'], $fresh['fingerprint'])) throw new \InvalidArgumentException('Fall oder Domain wurden geändert. Bitte lade die Seite neu.');
            $this->policy->assertDiscoveryAllowed($finding);
            $rows = $this->connection->fetchAllAssociative('SELECT * FROM hunter_domain_lookup WHERE domain=? ORDER BY rowid DESC', [$domain]);
            $cached = null; $reserved = 0;
            foreach ($rows as $row) {
                $reserved += (int) $row['reserved_credits'];
                if (in_array($row['state'], ['pending', 'unknown'], true)) throw new \InvalidArgumentException('Eine Hunter-Abfrage für diese Domain läuft oder hat einen unklaren Ausgang. Keine weitere Abfrage gestartet.');
                $result = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
                if ($cached === null && $row['state'] === 'completed' && in_array($result['status'] ?? '', ['found', 'not_found'], true)
                    && is_string($result['expires'] ?? null) && new \DateTimeImmutable($result['expires']) > new \DateTimeImmutable()) {
                    $result['metadata']['cached_from'] = $row['id'];
                    $result['metadata']['origin_fetched_at'] = $row['fetched_at'];
                    $cached = ['lookup_id' => $row['id'], 'result' => $result, 'cached' => true];
                }
            }
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            if ($cached === null) {
                if ($reserved >= 4) throw new \InvalidArgumentException('Das lokale Hunter-Limit von vier reservierten Abfragen für diese Domain ist erreicht.');
                $lookupId = Uuid::v7()->toRfc4122();
                $this->connection->insert('hunter_domain_lookup', ['id' => $lookupId, 'domain' => $domain, 'state' => 'pending',
                    'result' => json_encode($pending, JSON_THROW_ON_ERROR), 'reserved_credits' => 1, 'fetched_at' => $now]);
                $cached = ['lookup_id' => $lookupId, 'result' => $pending, 'cached' => false];
            }
            $result = $cached['result'];
            $result['queried_domain'] = $domain; $result['case_binding'] = $binding['fingerprint'];
            $result['metadata']['lookup_id'] = $cached['lookup_id'];
            $this->connection->insert('contact_discovery', ['id' => $id, 'finding_id' => $finding->getId(), 'provider' => $provider->id(),
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'fetched_at' => $now]);
            return $cached;
        });
        if ($reservation['cached']) return;
        $pending['queried_domain'] = $domain; $pending['case_binding'] = $binding['fingerprint'];
        try {
            $this->policy->assertDiscoveryAllowed($finding);
            if (!self::bindingCurrent($this->connection, $finding, $pending)) throw new \LogicException('stale');
        } catch (\Throwable) {
            $pending['status'] = 'invalid'; $pending['metadata']['request_state'] = 'rejected';
            $pending['warnings'][] = 'Fall oder Domain wurden geändert. Es wurde keine Hunter-Abfrage gestartet.';
            $this->connection->update('hunter_domain_lookup', ['state' => 'rejected', 'reserved_credits' => 0, 'result' => json_encode($pending, JSON_THROW_ON_ERROR)], ['id' => $reservation['lookup_id']]);
            $this->connection->update('contact_discovery', ['result' => json_encode($pending, JSON_THROW_ON_ERROR)], ['id' => $id]);
            return;
        }
        try { $result = $provider->discover($domain)->toArray(); }
        catch (\Throwable) {
            $result = $pending; $result['status'] = 'error'; $result['metadata']['request_state'] = 'unknown';
            $result['warnings'] = ['Der Ausgang der Hunter-Abfrage ist unklar. Es erfolgt keine automatische Wiederholung.'];
        }
        $state = $result['metadata']['request_state'] ?? 'unknown';
        if (!in_array($state, ['completed', 'rejected', 'unknown'], true)) $state = 'unknown';
        if ($state === 'completed' && ($result['expires'] ?? null) === null) $result['expires'] = (new \DateTimeImmutable('+7 days'))->format(DATE_ATOM);
        // Record the transport outcome before case freshness checks. A failed write
        // leaves pending in the independent ledger and never enables a silent retry.
        $this->connection->update('hunter_domain_lookup', ['state' => $state, 'result' => json_encode($result, JSON_THROW_ON_ERROR)], ['id' => $reservation['lookup_id']]);
        $result['queried_domain'] = $domain; $result['case_binding'] = $binding['fingerprint'];
        $result['metadata']['lookup_id'] = $reservation['lookup_id'];
        try {
            $this->policy->assertDiscoveryAllowed($finding);
            if (!self::bindingCurrent($this->connection, $finding, $result)) throw new \LogicException('stale');
        } catch (\Throwable) {
            $result['status'] = 'expired';
            $result['warnings'][] = 'Fall oder Domain haben sich seit der Hunter-Abfrage geändert. Die Vorschläge sind nicht mehr aktuell.';
        }
        $this->connection->update('contact_discovery', ['result' => json_encode($result, JSON_THROW_ON_ERROR)], ['id' => $id]);
    }

    /** Local provenance only; none of these case fields are sent to Hunter. */
    public static function bindingFor(Connection $connection, Finding $finding): array
    {
        $row = $connection->fetchAssociative('SELECT f.id, f.domain_id, f.url, f.poc_url, f.poc_revision, f.status, f.manual_assessment, f.discard_reason, d.hostname FROM finding f INNER JOIN domain d ON d.id=f.domain_id WHERE f.id=?', [$finding->getId()]);
        if (!$row || $row['domain_id'] !== $finding->getDomain()->getId() || strtolower(rtrim($row['hostname'], '.')) !== strtolower(rtrim($finding->getDomain()->getHostname(), '.'))) {
            throw new \InvalidArgumentException('Fall oder Domain wurden geändert. Bitte lade die Seite neu.');
        }
        if ($row['manual_assessment'] === 'discarded' || in_array($row['status'], ['duplicate', 'discarded'], true)) throw new \InvalidArgumentException('Kontaktermittlung ist für diesen Fall gesperrt.');
        $domain = strtolower(rtrim($row['hostname'], '.'));
        $work = (new FollowUpService($connection))->state($finding);
        return ['domain' => $domain, 'fingerprint' => hash('sha256', json_encode([$row, $work['revisions']], JSON_THROW_ON_ERROR))];
    }

    public static function bindingCurrent(Connection $connection, Finding $finding, array $result): bool
    {
        try {
            $binding = self::bindingFor($connection, $finding);
            return is_string($result['case_binding'] ?? null) && is_string($result['queried_domain'] ?? null)
                && $result['queried_domain'] === $binding['domain'] && hash_equals($binding['fingerprint'], $result['case_binding']);
        } catch (\Throwable) { return false; }
    }
}
