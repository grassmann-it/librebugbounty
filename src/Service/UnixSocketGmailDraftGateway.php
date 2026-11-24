<?php

declare(strict_types=1);
namespace App\Service;

/** Fixed local protocol; OAuth stays with the host's existing report-gen client. */
final class UnixSocketGmailDraftGateway implements GmailDraftGatewayInterface
{
    public function __construct(private readonly string $socketPath = '') {}

    public function configured(): bool
    {
        return str_starts_with($this->socketPath, '/') && strlen($this->socketPath) <= 107
            && !str_contains($this->socketPath, "\0") && !is_link($this->socketPath)
            && @filetype($this->socketPath) === 'socket';
    }

    public function submit(array $request): array { return $this->exchange($request, 'create'); }
    public function reconcile(array $request): array { return $this->exchange($request, 'reconcile'); }

    private function exchange(array $request, string $operation): array
    {
        if (!$this->configured()) throw new EmailPreparationException('gmail_unavailable', 'The local Gmail connection is unavailable.', 503);
        if (($request['version'] ?? null) !== 1 || ($request['operation'] ?? null) !== $operation) throw new \InvalidArgumentException('Invalid Gmail bridge operation.');
        $line = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        if (strlen($line) > 163840) throw new EmailPreparationException('gmail_request_too_large', 'The Gmail draft is too large.', 400);
        $socket = @stream_socket_client('unix://'.$this->socketPath, $errorNumber, $errorMessage, 3, STREAM_CLIENT_CONNECT);
        if ($socket === false) throw new EmailPreparationException('gmail_unavailable', 'The local Gmail connection is unavailable.', 503);
        $deadline = microtime(true) + 100;
        try {
            $offset = 0;
            while ($offset < strlen($line)) {
                $this->timeout($socket, $deadline);
                $written = @fwrite($socket, substr($line, $offset));
                if ($written === false || $written === 0) throw new \RuntimeException();
                $offset += $written;
            }
            $reply = '';
            while (!str_ends_with($reply, "\n")) {
                $this->timeout($socket, $deadline);
                $chunk = @fgets($socket, 16386 - strlen($reply));
                if ($chunk === false) throw new \RuntimeException();
                $reply .= $chunk;
                if (strlen($reply) > 16384 || stream_get_meta_data($socket)['timed_out']) throw new \RuntimeException();
            }
            $result = json_decode($reply, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($result) || array_is_list($result) || !is_bool($result['ok'] ?? null)
                || !in_array($result['state'] ?? null, ['verified', 'pending', 'uncertain', 'verification_failed', 'rejected', 'busy'], true)) throw new \RuntimeException();
            return $result;
        } catch (\Throwable) {
            throw new EmailPreparationException('gmail_uncertain', 'The Gmail result could not be confirmed. Check this attempt; do not create another draft.', 503);
        } finally { fclose($socket); }
    }

    private function timeout($socket, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) throw new \RuntimeException();
        stream_set_timeout($socket, (int)$remaining, (int)(($remaining - (int)$remaining) * 1000000));
    }
}
