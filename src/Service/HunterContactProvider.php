<?php
namespace App\Service;

use App\Dto\ContactDiscoveryResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** One bounded Domain Search; no enrichment, verification, pagination or message tools. */
final class HunterContactProvider implements ExplicitContactDiscoveryProviderInterface
{
    private const LIMIT_BYTES = 1048576;
    private const ENDPOINT = 'https://api.hunter.io/v2/domain-search';

    public function __construct(private readonly HttpClientInterface $client, private readonly string $apiKey = '') {}
    public function id(): string { return 'hunter'; }
    public function label(): string { return 'Hunter.io'; }
    public function configured(): bool { return $this->apiKey !== '' && strlen($this->apiKey) <= 512 && !preg_match('/[\s\x00-\x1f\x7f]/', $this->apiKey); }

    public function discover(string $hostname): ContactDiscoveryResult
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        if (!self::publicHostname($hostname)) return $this->failure('', 'rejected', 'Nur öffentliche DNS-Hostnamen werden abgefragt.');
        $source = 'https://hunter.io/search/'.rawurlencode($hostname);
        if (!$this->configured()) return $this->failure($source, 'rejected', 'Hunter.io ist noch nicht auf dem Server konfiguriert.');
        $response = null;
        try {
            $response = $this->client->request('GET', self::ENDPOINT, [
                'query' => ['domain' => $hostname, 'limit' => 10, 'offset' => 0],
                'headers' => ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json'],
                'timeout' => 10, 'max_duration' => 25, 'max_redirects' => 0, 'buffer' => false,
                'on_progress' => static function (int $downloaded, int $size): void {
                    if (max($downloaded, $size) > self::LIMIT_BYTES) throw new \RuntimeException('Response too large.');
                },
            ]);
            $status = $response->getStatusCode();
            if (in_array($status, [400, 401, 403, 404, 422, 429, 451], true)) {
                return $this->failure($source, 'rejected', match ($status) {
                    401 => 'Hunter.io hat den API-Schlüssel abgelehnt.',
                    403, 429 => 'Hunter.io hat die Abfrage wegen eines Kontingent- oder Ratenlimits abgelehnt.',
                    default => 'Hunter.io hat die Abfrage abgelehnt. Es wurde kein Kontakt übernommen.',
                });
            }
            if ($status !== 200) return $this->failure($source, 'unknown', 'Der Ausgang der Hunter-Abfrage ist unklar. Es erfolgt keine automatische Wiederholung.');
            $headers = $response->getHeaders(false);
            if (strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0])) !== 'application/json') {
                return $this->failure($source, 'unknown', 'Die Hunter-Antwort konnte nicht ausgewertet werden. Es erfolgt keine automatische Wiederholung.');
            }
            $raw = '';
            foreach ($this->client->stream($response, 10) as $chunk) {
                if ($chunk->isTimeout()) throw new \RuntimeException('Timeout');
                $raw .= $chunk->getContent();
                if (strlen($raw) > self::LIMIT_BYTES) throw new \RuntimeException('Response too large.');
            }
            // Never persist a key echoed by a proxy/provider in otherwise accepted fields.
            if (str_contains($raw, $this->apiKey)) throw new \RuntimeException('Unexpected credential echo');
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($payload)) {
                array_walk_recursive($payload, function (mixed $value): void {
                    if (is_string($value) && str_contains($value, $this->apiKey)) throw new \RuntimeException('Unexpected credential echo');
                });
            }
            $data = is_array($payload) ? ($payload['data'] ?? null) : null;
            if (!is_array($data) || !array_key_exists('domain', $data) || !empty($payload['errors']) || !is_array($data['emails'] ?? null)
                || !array_is_list($data['emails']) || count($data['emails']) > 10) throw new \RuntimeException('Invalid response');
            $metadata = ['request_state' => 'completed'];
            $credits = $headers['x-credits-used'][0] ?? null;
            $unit = $headers['x-credits-unit'][0] ?? null;
            if (is_string($credits) && preg_match('/^\d{1,5}(?:\.\d{1,3})?$/D', $credits) && in_array($unit, ['credit', 'search', 'verification'], true)) {
                $metadata['credits_used'] = $credits; $metadata['credits_unit'] = $unit;
            }
            if (($data['domain'] ?? null) === null && $data['emails'] === []) {
                return new ContactDiscoveryResult('not_found', $source, expires: (new \DateTimeImmutable('+7 days'))->format(DATE_ATOM), metadata: $metadata);
            }
            if (!is_string($data['domain'] ?? null) || strtolower(rtrim($data['domain'], '.')) !== $hostname) throw new \RuntimeException('Domain mismatch');
            $contacts = [];
            foreach ($data['emails'] as $email) {
                if (!is_array($email) || !is_string($email['value'] ?? null)) continue;
                $value = trim($email['value']);
                if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)
                    || strtolower(substr(strrchr($value, '@') ?: '', 1)) !== $hostname) continue;
                $sources = [];
                foreach (is_array($email['sources'] ?? null) ? $email['sources'] : [] as $entry) {
                    if (!is_array($entry) || !self::sourceUrl($entry['uri'] ?? null)) continue;
                    $sources[] = ['url' => $entry['uri'], 'last_seen_on' => self::date($entry['last_seen_on'] ?? null)];
                    if (count($sources) === 3) break;
                }
                $confidence = $email['confidence'] ?? null;
                $hunter = [
                    'type' => in_array($email['type'] ?? null, ['personal', 'generic'], true) ? $email['type'] : 'unknown',
                    'provenance' => in_array($email['provenance'] ?? null, ['found', 'inferred', 'previously_found'], true) ? $email['provenance'] : 'unknown',
                    'confidence' => is_int($confidence) && $confidence >= 0 && $confidence <= 100 ? $confidence : null,
                    'department' => self::text($email['department'] ?? null, 100),
                    'position' => self::text($email['position'] ?? null, 200),
                    'sources' => $sources,
                    'verification' => in_array($email['verification']['status'] ?? null, ['valid', 'invalid', 'accept_all', 'webmail', 'disposable', 'unknown'], true) ? $email['verification']['status'] : 'unknown',
                ];
                $contacts[strtolower($value)] = ['channel' => 'email', 'value' => $value, 'hunter' => $hunter];
            }
            return new ContactDiscoveryResult($contacts === [] ? 'not_found' : 'found', $source, array_values($contacts),
                expires: (new \DateTimeImmutable('+7 days'))->format(DATE_ATOM),
                warnings: ['Hunter-Kontakte sind Vorschläge. Zustellbarkeit, Anbieter-Konfidenz und Security-Zuständigkeit sind unterschiedliche Angaben.'], metadata: $metadata);
        } catch (\Throwable) {
            return $this->failure($source, 'unknown', 'Die Hunter-Antwort konnte nicht ausgewertet werden. Es erfolgt keine automatische Wiederholung.');
        } finally { $response?->cancel(); }
    }

    public static function publicHostname(string $hostname): bool
    {
        return strlen($hostname) <= 253 && (bool) filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && str_contains($hostname, '.') && !filter_var($hostname, FILTER_VALIDATE_IP)
            && !preg_match('/\.(?:localhost|local|internal|invalid|test)$/D', $hostname);
    }
    private function failure(string $source, string $state, string $warning): ContactDiscoveryResult
    { return new ContactDiscoveryResult('error', $source, warnings: [$warning], metadata: ['request_state' => $state]); }
    private static function text(mixed $value, int $max): ?string
    { return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $max && !preg_match('/[\x00-\x1f\x7f]/u', $value) ? trim($value) : null; }
    private static function date(mixed $value): ?string
    { return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? $value : null; }
    private static function sourceUrl(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f]/', $value)) return false;
        $parts = parse_url($value);
        return is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && !isset($parts['user']) && !isset($parts['pass']);
    }
}
