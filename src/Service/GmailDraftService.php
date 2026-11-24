<?php

declare(strict_types=1);
namespace App\Service;

use App\Entity\Finding;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/** Durable local receipts fence remote creates; neither draft creation nor readback marks a report sent. */
final class GmailDraftService
{
    private readonly string $account;
    public function __construct(private readonly Connection $connection, private readonly EmailPreparationService $emails,
        private readonly GmailDraftGatewayInterface $gateway, string $account = '')
    {
        $this->account = strtolower(trim($account));
    }

    public function state(Finding $finding): array
    {
        $available = $this->available();
        $configured = $available && $this->configured();
        $row = $available ? $this->latest($finding) : null;
        $source = $this->emails->initialState($finding, 'en');
        $eligible = $source['eligible'] && ($source['contact']['channel'] ?? null) === 'email';
        $canCreate = $configured && $eligible && ($row === null || (bool)$row['safe_to_retry']);
        return ['configured' => $configured, 'account' => $this->account, 'canCreate' => $canCreate,
            'receipt' => $row === null ? null : $this->receipt($finding, $row, false, $canCreate),
            'code' => !$available ? 'gmail_migration_required' : (!$configured ? 'gmail_not_configured' : null)];
    }

    public function create(Finding $finding, array $input): array
    {
        if (!$this->available()) throw new EmailPreparationException('gmail_migration_required', 'Apply the Gmail receipt migration before creating a draft.', 503);
        foreach (['attemptId', 'language', 'fingerprint', 'subject', 'body', 'expectedAccount'] as $field) {
            if (!is_string($input[$field] ?? null)) throw new EmailPreparationException('gmail_invalid_input', 'Check the draft and review confirmation.', 400);
        }
        if (($input['reviewed'] ?? null) !== true || !Uuid::isValid($input['attemptId'])) throw new EmailPreparationException('gmail_review_required', 'Review the complete email and confirm it before creating a Gmail draft.', 400);
        if ($input['expectedAccount'] !== $this->account) throw new EmailPreparationException('gmail_account_changed', 'The configured Gmail account changed. Reload the page and review the account before creating a draft.', 409);
        // A replay is answered from its receipt even if the original source has since changed.
        $prior = $this->byAttempt($input['attemptId']);
        if ($prior !== null) {
            if ($prior['finding_id'] !== $finding->getId() || $prior['account'] !== $this->account
                || $prior['fingerprint'] !== $input['fingerprint'] || $prior['language'] !== $input['language']
                || !hash_equals($prior['content_sha256'], $this->digest($prior['recipient'], $input['subject'], $input['body']))) throw $this->conflict();
            return $this->receipt($finding, $prior, true);
        }
        if (!$this->configured()) throw new EmailPreparationException('gmail_not_configured', 'Configure the local Gmail connection and expected account first.', 503);
        $submission = $this->emails->gmailSubmission($finding, $input['language'], $input['fingerprint'], $input['subject'], $input['body']);
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $row = ['attempt_id' => $input['attemptId'], 'case_key' => $submission['caseKey'], 'finding_id' => $finding->getId(),
            'account' => $this->account, 'fingerprint' => $input['fingerprint'], 'language' => $input['language'],
            'content_sha256' => $this->digest($submission['recipient'], $input['subject'], $input['body']),
            'recipient' => $submission['recipient'], 'subject' => $input['subject'], 'state' => 'pending',
            'draft_id' => null, 'message_id' => null, 'stale' => 0, 'safe_to_retry' => 0, 'receipt_revision' => 0, 'error_code' => null, 'created_at' => $now, 'updated_at' => $now];
        try {
            $this->connection->transactional(function () use ($finding, $row): void {
                // Acquire a write lock before the per-case check and reserve before any provider call.
                $this->connection->executeStatement('UPDATE gmail_draft_attempt SET stale=stale WHERE finding_id=? AND account=?', [$finding->getId(), $this->account]);
                if ($this->connection->fetchOne('SELECT 1 FROM gmail_draft_attempt WHERE finding_id=? AND account=? AND safe_to_retry=0', [$finding->getId(), $this->account])) throw $this->conflict();
                $this->connection->insert('gmail_draft_attempt', $row);
            });
        } catch (UniqueConstraintViolationException) { throw $this->conflict(); }
        if (!$this->emails->draftBasisCurrent($finding, $row['language'], $row['fingerprint'])) {
            $this->update($row, ['state' => 'rejected', 'safe_to_retry' => 1, 'stale' => 1, 'error_code' => 'stale_preview']);
            return $this->receipt($finding, $this->byAttempt($row['attempt_id']));
        }
        $request = $this->request($row, 'create') + ['fingerprint' => $row['fingerprint'], 'recipient' => $row['recipient'], 'subject' => $input['subject'], 'body' => $input['body']];
        try { $result = $this->gateway->submit($request); }
        catch (\Throwable $error) {
            // A connect failure occurs before writing request bytes. Everything else is an unknown outcome.
            $safe = $error instanceof EmailPreparationException && $error->errorCode === 'gmail_unavailable';
            $result = $this->failure($row, $safe ? 'rejected' : 'uncertain', $safe ? 'gmail_unavailable' : 'gmail_uncertain', $safe);
        }
        return $this->acceptResult($finding, $row, $result, false);
    }

    public function reconcile(Finding $finding, string $attemptId): array
    {
        $row = $this->available() && Uuid::isValid($attemptId) ? $this->byAttempt($attemptId) : null;
        if ($row === null || $row['finding_id'] !== $finding->getId() || $row['account'] !== $this->account) throw new EmailPreparationException('gmail_attempt_missing', 'No matching Gmail attempt is recorded.', 404);
        if ((bool)$row['safe_to_retry']) return $this->receipt($finding, $row, true);
        if (!$this->configured()) return $this->receipt($finding, $row, true);
        try { $result = $this->gateway->reconcile($this->request($row, 'reconcile')); }
        catch (\Throwable) { $result = $this->failure($row, $row['draft_id'] !== null ? 'verification_failed' : 'uncertain', 'gmail_readback_unavailable'); }
        return $this->acceptResult($finding, $row, $result, true);
    }

    private function acceptResult(Finding $finding, array $row, array $result, bool $reconcile): array
    {
        $matched = ($result['attempt_id'] ?? null) === $row['attempt_id'] && ($result['account'] ?? null) === $row['account']
            && ($result['content_sha256'] ?? null) === $row['content_sha256'];
        $state = $result['state'] ?? '';
        $validId = static fn (mixed $value): bool => is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) === 1;
        if (!$matched || !in_array($state, ['verified', 'pending', 'uncertain', 'verification_failed', 'rejected', 'busy'], true)
            || ($state === 'verified' && (($result['ok'] ?? null) !== true || !$validId($result['draft_id'] ?? null) || !$validId($result['message_id'] ?? null)))
            || (isset($result['draft_id']) && !$validId($result['draft_id'])) || (isset($result['message_id']) && !$validId($result['message_id']))) {
            $result = $this->failure($row, $row['draft_id'] !== null ? 'verification_failed' : 'uncertain', 'gmail_invalid_receipt');
            $state = $result['state'];
        }
        if ($reconcile && $row['draft_id'] !== null && isset($result['draft_id']) && $row['draft_id'] !== $result['draft_id']) {
            $result = $this->failure($row, 'verification_failed', 'gmail_invalid_receipt');
            $state = 'verification_failed';
        }
        $safe = !$reconcile && in_array($state, ['rejected', 'busy'], true) && ($result['safe_to_retry'] ?? false) === true
            && !isset($result['draft_id']) && !isset($result['message_id']) && $row['draft_id'] === null;
        if ($reconcile && in_array($state, ['rejected', 'busy', 'pending'], true)) $state = $row['draft_id'] !== null ? 'verification_failed' : 'uncertain';
        if ($state === 'busy') $state = $safe ? 'rejected' : 'uncertain';
        $error = $state === 'verified' ? null : ($result['error_code'] ?? 'gmail_uncertain');
        if ($error !== null && (!is_string($error) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $error))) $error = 'gmail_uncertain';
        $changes = ['state' => $state, 'safe_to_retry' => (int)$safe, 'error_code' => $error,
            'draft_id' => $result['draft_id'] ?? $row['draft_id'], 'message_id' => $result['message_id'] ?? $row['message_id'],
            'stale' => (int)((bool)$row['stale'] || !$this->emails->draftBasisCurrent($finding, $row['language'], $row['fingerprint']))];
        // Merge against the locked current receipt: late readbacks cannot erase newer proof or unlock a create.
        $this->connection->transactional(function () use ($row, $changes): void {
            $this->connection->executeStatement('UPDATE gmail_draft_attempt SET receipt_revision=receipt_revision WHERE attempt_id=?', [$row['attempt_id']]);
            $current = $this->byAttempt($row['attempt_id']);
            if ($current === null) return;
            $newId = ($current['draft_id'] === null && $changes['draft_id'] !== null) || ($current['message_id'] === null && $changes['message_id'] !== null);
            $provenNoCreate = (bool)$changes['safe_to_retry'] && $current['draft_id'] === null && $current['message_id'] === null && $current['state'] !== 'verified';
            if ((int)$current['receipt_revision'] !== (int)$row['receipt_revision'] && !$newId && !$provenNoCreate) return;
            if ($current['draft_id'] !== null && $changes['draft_id'] !== null && $current['draft_id'] !== $changes['draft_id']) {
                $changes['state'] = 'verification_failed'; $changes['error_code'] = 'gmail_invalid_receipt';
                $changes['message_id'] = $current['message_id'];
            }
            $changes['draft_id'] = $current['draft_id'] ?? $changes['draft_id'];
            if ($changes['state'] !== 'verified') $changes['message_id'] = $current['message_id'] ?? $changes['message_id'];
            if ($changes['draft_id'] !== null || $changes['message_id'] !== null) $changes['safe_to_retry'] = 0;
            $changes['stale'] = (int)((bool)$current['stale'] || (bool)$changes['stale']);
            $this->update($current, $changes);
        });
        return $this->receipt($finding, $this->byAttempt($row['attempt_id']), (bool)($result['reused'] ?? false));
    }

    private function receipt(Finding $finding, array $row, bool $reused = false, ?bool $canCreate = null): array
    {
        $stale = (bool)$row['stale'] || !$this->emails->draftBasisCurrent($finding, $row['language'], $row['fingerprint']);
        $error = $row['error_code'];
        if ($canCreate === null) {
            $source = $this->emails->initialState($finding, $row['language']);
            $canCreate = (bool)$row['safe_to_retry'] && $this->configured() && $source['eligible']
                && ($source['contact']['channel'] ?? null) === 'email'
                && !$this->connection->fetchOne('SELECT 1 FROM gmail_draft_attempt WHERE finding_id=? AND account=? AND safe_to_retry=0', [$finding->getId(), $this->account]);
        }
        return ['state' => $row['state'], 'attemptId' => $row['attempt_id'], 'draftId' => $row['draft_id'], 'messageId' => $row['message_id'],
            'gmailUrl' => $row['draft_id'] === null ? null : 'https://mail.google.com/mail/?authuser='.rawurlencode($row['account']).'#drafts', 'account' => $row['account'],
            'stale' => $stale, 'reused' => $reused, 'canCreate' => $canCreate,
            'safeToRetry' => (bool)$row['safe_to_retry'], 'code' => $error,
            'error' => $error === null ? null : ($row['safe_to_retry'] ? 'No Gmail draft was created by this attempt. Fix the connection or refresh the case, then explicitly try again.'
                : ($row['draft_id'] !== null ? 'A Gmail draft was created, but its current contents could not be verified. Check this attempt; do not create another draft.' : 'The Gmail result is uncertain. Check this attempt; do not create another draft.'))];
    }

    private function request(array $row, string $operation): array
    {
        return ['version' => 1, 'operation' => $operation, 'account' => $row['account'], 'attempt_id' => $row['attempt_id'], 'case_key' => $row['case_key'], 'content_sha256' => $row['content_sha256']];
    }
    private function failure(array $row, string $state, string $code, bool $safe = false): array
    {
        return ['ok' => false, 'state' => $state, 'attempt_id' => $row['attempt_id'], 'account' => $row['account'], 'content_sha256' => $row['content_sha256'], 'error_code' => $code, 'safe_to_retry' => $safe];
    }
    private function update(array $row, array $changes): void
    {
        $this->connection->update('gmail_draft_attempt', $changes + ['receipt_revision' => (int)$row['receipt_revision'] + 1, 'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM)], ['attempt_id' => $row['attempt_id']]);
    }
    private function latest(Finding $finding): ?array
    {
        return $this->connection->fetchAssociative('SELECT * FROM gmail_draft_attempt WHERE finding_id=? AND account=? ORDER BY safe_to_retry ASC, rowid DESC LIMIT 1', [$finding->getId(), $this->account]) ?: null;
    }
    private function byAttempt(string $id): ?array { return $this->connection->fetchAssociative('SELECT * FROM gmail_draft_attempt WHERE attempt_id=?', [$id]) ?: null; }
    private function available(): bool { return (bool)$this->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='gmail_draft_attempt'"); }
    private function configured(): bool { return filter_var($this->account, FILTER_VALIDATE_EMAIL) !== false && $this->gateway->configured(); }
    private function digest(string $recipient, string $subject, string $body): string { return hash('sha256', $this->account."\0".$recipient."\0".$subject."\0".$body); }
    private function conflict(): EmailPreparationException { return new EmailPreparationException('gmail_attempt_exists', 'A Gmail attempt is already recorded for this case. Check that attempt instead of creating another draft.', 409); }
}
