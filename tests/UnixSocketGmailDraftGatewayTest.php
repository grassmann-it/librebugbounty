<?php

namespace App\Tests;

use App\Service\EmailPreparationException;
use App\Service\UnixSocketGmailDraftGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class UnixSocketGmailDraftGatewayTest extends TestCase
{
    public function testOnlyAnExistingAbsoluteSocketIsConfigured(): void
    {
        $path = $this->path();
        self::assertFalse((new UnixSocketGmailDraftGateway())->configured());
        self::assertFalse((new UnixSocketGmailDraftGateway('relative.sock'))->configured());
        self::assertFalse((new UnixSocketGmailDraftGateway($path))->configured());
        file_put_contents($path, 'not a socket');
        self::assertFalse((new UnixSocketGmailDraftGateway($path))->configured());
        unlink($path);
        $listener = stream_socket_server('unix://'.$path, $number, $message);
        self::assertNotFalse($listener);
        try {
            self::assertTrue((new UnixSocketGmailDraftGateway($path))->configured());
            symlink($path, $path.'.link');
            self::assertFalse((new UnixSocketGmailDraftGateway($path.'.link'))->configured());
        } finally {
            fclose($listener); @unlink($path.'.link'); @unlink($path);
        }
    }

    #[DataProvider('operations')]
    public function testRequestUsesOneNewlineDelimitedLocalExchange(string $operation): void
    {
        $request = $this->request($operation);
        $reply = ['ok' => true, 'state' => 'verified', 'attempt_id' => $request['attempt_id'],
            'account' => $request['account'], 'content_sha256' => $request['content_sha256'],
            'draft_id' => 'r1234567890123456789', 'message_id' => '18abcdef01234567'];
        $this->withBridge(json_encode($reply, JSON_THROW_ON_ERROR)."\n", function ($gateway, $received) use ($request, $reply, $operation): void {
            $result = $operation === 'create' ? $gateway->submit($request) : $gateway->reconcile($request);
            self::assertSame($reply, $result);
            $wire = file_get_contents($received);
            self::assertSame(1, substr_count($wire, "\n"));
            self::assertSame($request, json_decode($wire, true, 16, JSON_THROW_ON_ERROR));
        });
    }

    public static function operations(): iterable
    {
        yield 'create' => ['create'];
        yield 'reconcile' => ['reconcile'];
    }

    #[DataProvider('unusableReplies')]
    public function testMalformedOversizedAndDisconnectedRepliesRemainUnknown(string $reply): void
    {
        $this->withBridge($reply, function ($gateway): void {
            try { $gateway->submit($this->request()); self::fail('Unusable bridge result was accepted.'); }
            catch (EmailPreparationException $error) {
                self::assertSame('gmail_uncertain', $error->errorCode);
                self::assertStringNotContainsString('PRIVATE_BRIDGE_DETAIL', $error->getMessage());
            }
        });
    }

    public static function unusableReplies(): iterable
    {
        yield 'invalid JSON' => ["PRIVATE_BRIDGE_DETAIL\n"];
        yield 'not an object' => ["[]\n"];
        yield 'missing acknowledgement' => ["{\"state\":\"verified\"}\n"];
        yield 'unknown state' => ["{\"ok\":true,\"state\":\"sent\"}\n"];
        yield 'missing newline before EOF' => ['{"ok":true,"state":"verified"}'];
        yield 'immediate EOF' => [''];
        yield 'oversized' => [str_repeat('x', 17000)."\n"];
    }

    public function testKnownUncertainOutcomeIsReturnedForReceiptHandling(): void
    {
        $reply = ['ok' => false, 'state' => 'uncertain', 'error_code' => 'known_attempt_unresolved'];
        $this->withBridge(json_encode($reply, JSON_THROW_ON_ERROR)."\n", function ($gateway) use ($reply): void {
            self::assertSame($reply, $gateway->submit($this->request()));
        });
    }

    public function testUnsupportedOperationAndOversizedRequestAreRejectedBeforeConnecting(): void
    {
        $path = $this->path(); $listener = stream_socket_server('unix://'.$path, $number, $message);
        self::assertNotFalse($listener);
        stream_set_blocking($listener, false);
        $gateway = new UnixSocketGmailDraftGateway($path);
        try {
            foreach ([['version' => 2], ['operation' => 'send']] as $invalid) {
                try { $gateway->submit(array_replace($this->request(), $invalid)); self::fail('Unsupported operation accepted.'); }
                catch (\InvalidArgumentException) {}
                self::assertFalse(@stream_socket_accept($listener, 0));
            }
            try { $gateway->submit(array_replace($this->request(), ['body' => str_repeat('x', 163840)])); self::fail('Oversized request accepted.'); }
            catch (EmailPreparationException $error) { self::assertSame('gmail_request_too_large', $error->errorCode); }
            self::assertFalse(@stream_socket_accept($listener, 0));
        } finally { fclose($listener); @unlink($path); }
    }

    private function request(string $operation = 'create'): array
    {
        $request = ['version' => 1, 'operation' => $operation, 'account' => 'reports@example.org',
            'attempt_id' => '01900000-0000-7000-8000-000000000000', 'case_key' => str_repeat('a', 64), 'content_sha256' => str_repeat('b', 64)];
        if ($operation === 'create') $request += ['fingerprint' => str_repeat('c', 64), 'recipient' => 'security@example.org', 'subject' => 'Security report', 'body' => "Hello,\n\nA literal newline stays inside a JSON string.\nGrüße"];
        return $request;
    }

    private function path(): string { return APP_TEST_ROOT.'/gmail-'.bin2hex(random_bytes(4)).'.sock'; }

    /** A fixture process speaks one local exchange, then exits; no Gmail/network calls. */
    private function withBridge(string $reply, \Closure $assert): void
    {
        $path = $this->path(); $received = $path.'.request';
        $script = <<<'PY'
import base64, pathlib, socket, sys
server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
server.bind(sys.argv[1])
server.listen(1)
server.settimeout(5)
print('READY', flush=True)
try:
    connection, _ = server.accept()
    with connection:
        connection.settimeout(5)
        data = b''
        while not data.endswith(b'\n'):
            part = connection.recv(4096)
            if not part:
                break
            data += part
        pathlib.Path(sys.argv[2]).write_bytes(data)
        connection.sendall(base64.b64decode(sys.argv[3]))
finally:
    server.close()
PY;
        $process = new Process(['python3', '-c', $script, $path, $received, base64_encode($reply)]);
        $process->setTimeout(10); $process->start();
        try {
            self::assertTrue($process->waitUntil(static fn ($type, $output): bool => str_contains($output, 'READY')), $process->getErrorOutput());
            $assert(new UnixSocketGmailDraftGateway($path), $received);
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        } finally { $process->stop(0.1); @unlink($path); @unlink($received); }
    }
}
