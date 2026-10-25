<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Human feedback on a rule proposal; reviewing a hint never changes the rule or its pack. */
final class CribrumRuleReviewService
{
    public function __construct(private readonly Connection $connection) {}

    public function available(): bool
    {
        return (bool) $this->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='cribrum_rule_review_hint'")
            && (bool) $this->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='cribrum_rule_review_event'");
    }

    /** Called only for an explicitly deselected, initially checked, current proposal. */
    public function raise(string $findingId, string $claim, string $ruleId, string $observationId, string $classifierVersion, string $packVersion, array $evidenceIds = []): void
    {
        if (!$this->available()) throw new \UnexpectedValueException('Die Regelprüfung ist nach der Datenbankaktualisierung verfügbar.');
        if (!Uuid::isValid($findingId) || !Uuid::isValid($observationId)
            || preg_match('/^[a-z0-9_:.-]{1,48}$/D', $claim) !== 1
            || preg_match('~^[A-Za-z0-9_.:/-]{1,128}$~D', $ruleId) !== 1
            || $classifierVersion === '' || strlen($classifierVersion) > 128 || $packVersion === '' || strlen($packVersion) > 128) {
            throw new \InvalidArgumentException('Ungültige Regelprüfungsdaten.');
        }
        foreach ($evidenceIds as $evidenceId) {
            if (!is_string($evidenceId) || preg_match('/^[a-f0-9]{64}$/D', $evidenceId) !== 1) throw new \InvalidArgumentException('Ungültige Regelprüfungsdaten.');
        }
        $evidenceIds = array_values(array_unique($evidenceIds));
        $id = Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->transactional(function () use ($id, $findingId, $claim, $ruleId, $observationId, $classifierVersion, $packVersion, $evidenceIds, $now): void {
            $inserted = $this->connection->executeStatement(
                "INSERT INTO cribrum_rule_review_hint (id,finding_id,claim,rule_id,observation_id,classifier_version,pack_version,evidence_ids,status,revision,created_at) VALUES (?,?,?,?,?,?,?,?, 'pending',1,?) ON CONFLICT(finding_id,claim,rule_id,observation_id,classifier_version,pack_version) DO NOTHING",
                [$id, $findingId, $claim, $ruleId, $observationId, $classifierVersion, $packVersion, json_encode($evidenceIds, JSON_THROW_ON_ERROR), $now],
            );
            if ($inserted === 1) $this->event($id, 'raised', 1, null, $now);
        });
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function list(string $status = 'pending', int $limit = 50, int $offset = 0): array
    {
        if (!in_array($status, ['pending', 'reviewed'], true) || $limit < 1 || $limit > 100 || $offset < 0) throw new \InvalidArgumentException('Ungültige Regelprüfungs-Auswahl.');
        if (!$this->available()) return ['rows' => [], 'total' => 0];
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_hint WHERE status=?', [$status]);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT h.*, f.poc_url, f.url FROM cribrum_rule_review_hint h JOIN finding f ON f.id=h.finding_id WHERE h.status=? ORDER BY h.created_at DESC,h.id DESC LIMIT '.$limit.' OFFSET '.$offset,
            [$status],
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public function get(string $id): ?array
    {
        if (!Uuid::isValid($id) || !$this->available()) return null;
        return $this->connection->fetchAssociative('SELECT h.*, f.poc_url, f.url FROM cribrum_rule_review_hint h JOIN finding f ON f.id=h.finding_id WHERE h.id=?', [$id]) ?: null;
    }

    public function resolve(string $id, int $expectedRevision, ?string $note = null): void
    {
        if (!Uuid::isValid($id) || $expectedRevision < 1 || ($note !== null && mb_strlen($note) > 2000)) throw new \InvalidArgumentException('Ungültige Regelprüfungs-Angaben.');
        if (!$this->available()) throw new \UnexpectedValueException('Die Regelprüfung ist nach der Datenbankaktualisierung verfügbar.');
        $this->connection->transactional(function () use ($id, $expectedRevision, $note): void {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $count = $this->connection->executeStatement("UPDATE cribrum_rule_review_hint SET status='reviewed',revision=revision+1,reviewed_at=?,note=? WHERE id=? AND status='pending' AND revision=?", [$now, $note, $id, $expectedRevision]);
            if ($count !== 1) throw new \UnexpectedValueException('Der Regelhinweis hat sich geändert. Bitte neu laden.');
            $this->event($id, 'reviewed', $expectedRevision + 1, $note, $now);
        });
    }

    private function event(string $hintId, string $action, int $revision, ?string $note, string $now): void
    {
        $this->connection->insert('cribrum_rule_review_event', ['id' => Uuid::v7()->toRfc4122(), 'hint_id' => $hintId, 'action' => $action, 'revision' => $revision, 'note' => $note, 'created_at' => $now]);
    }
}
