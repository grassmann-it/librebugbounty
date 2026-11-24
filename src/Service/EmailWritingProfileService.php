<?php

declare(strict_types=1);
namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Local writing preferences. Never reads mailboxes, files or model providers. */
final class EmailWritingProfileService
{
    public const BUILTIN = 'builtin';
    public function __construct(private readonly Connection $connection) {}

    public function available(): bool
    {
        return (int)$this->connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('email_writing_profile','email_writing_profile_revision','email_writing_profile_settings')") === 3;
    }

    public function defaultSelection(): array
    {
        if (!$this->available()) return ['id' => self::BUILTIN, 'revision' => 1];
        $row = $this->connection->fetchAssociative('SELECT default_profile_id,default_revision FROM email_writing_profile_settings WHERE id=1');
        if (!$row) throw new EmailPreparationException('profile_storage', 'Writing profile settings are unavailable.', 503);
        return ['id' => $row['default_profile_id'], 'revision' => (int)$row['default_revision']];
    }

    public function all(bool $includeArchived = true): array
    {
        $profiles = [$this->get(self::BUILTIN)];
        if ($this->available()) foreach ($this->connection->fetchFirstColumn('SELECT id FROM email_writing_profile'.($includeArchived ? '' : ' WHERE archived=0').' ORDER BY created_at,id') as $id) $profiles[] = $this->get($id, $includeArchived);
        return $profiles;
    }

    public function summaries(bool $includeArchived = false): array
    {
        return array_map(static fn (array $profile): array => array_diff_key($profile, array_flip(['instructions', 'examples'])), $this->all($includeArchived));
    }

    public function get(string $id, bool $allowArchived = false): array
    {
        if ($id === self::BUILTIN) {
            $data = ['name' => 'Built-in neutral style', 'instructions' => '', 'examples' => []];
            return $data + ['id' => self::BUILTIN, 'revision' => 1, 'archived' => false, 'builtin' => true,
                'payloadHash' => hash('sha256', self::json($data)), 'exampleCount' => 0, 'isDefault' => $this->defaultSelection()['id'] === self::BUILTIN];
        }
        if (!Uuid::isValid($id) || !$this->available()) throw new EmailPreparationException('profile_missing', 'The selected writing profile is unavailable. Select a profile and preview again.', 409);
        $row = $this->connection->fetchAssociative('SELECT r.*,p.archived AS profile_archived FROM email_writing_profile p JOIN email_writing_profile_revision r ON r.profile_id=p.id AND r.revision=p.current_revision WHERE p.id=?', [$id]);
        if (!$row || (!$allowArchived && (bool)$row['profile_archived'])) throw new EmailPreparationException('profile_missing', 'The selected writing profile is unavailable or archived. Select a profile and preview again.', 409);
        return $this->decode($row) + ['isDefault' => $this->defaultSelection()['id'] === $id];
    }

    public function history(string $id, int $limit = 20): array
    {
        if ($id === self::BUILTIN) return [$this->get($id)];
        $this->get($id, true);
        return array_map(fn (array $row): array => $this->decode($row), $this->connection->fetchAllAssociative('SELECT * FROM email_writing_profile_revision WHERE profile_id=? ORDER BY revision DESC LIMIT '.max(1, min(100, $limit)), [$id]));
    }

    public function save(array $input): array
    {
        $this->assertAvailable();
        $action = $input['action'] ?? '';
        if (!in_array($action, ['create', 'update'], true)) throw new EmailPreparationException('profile_invalid', 'Choose a writing profile action.', 400);
        $data = $this->input($input);
        $id = $action === 'create' ? Uuid::v7()->toRfc4122() : ($input['profileId'] ?? '');
        if (!is_string($id) || $id === self::BUILTIN || !Uuid::isValid($id)) throw new EmailPreparationException('profile_read_only', 'The built-in profile is read-only. Create your own profile.', 422);
        $expected = $action === 'create' ? 0 : $this->revision($input['revision'] ?? null);
        $this->connection->transactional(function () use ($id, $data, $expected, $action): void {
            $this->lock();
            if ($action === 'create') {
                if ((int)$this->connection->fetchOne('SELECT COUNT(*) FROM email_writing_profile WHERE archived=0') >= 50) throw new EmailPreparationException('profile_limit', 'Archive an unused profile before creating another one. Up to 50 active profiles are supported.');
                $this->connection->insert('email_writing_profile', ['id' => $id, 'current_revision' => 1, 'archived' => 0, 'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
            } else {
                $old = $this->get($id);
                if ($old['revision'] !== $expected) throw $this->stale();
                $this->connection->update('email_writing_profile', ['current_revision' => $expected + 1], ['id' => $id]);
            }
            $this->append($id, $expected + 1, $data, false);
        });
        return $this->get($id);
    }

    public function archive(string $id, int $revision): void
    {
        $this->assertAvailable();
        if ($id === self::BUILTIN) throw new EmailPreparationException('profile_read_only', 'The built-in profile cannot be archived.');
        $this->connection->transactional(function () use ($id, $revision): void {
            $this->lock();
            $old = $this->get($id);
            if ($old['revision'] !== $revision) throw $this->stale();
            if ($this->defaultSelection()['id'] === $id) throw new EmailPreparationException('profile_is_default', 'Choose another default profile before archiving this one.');
            $this->append($id, $revision + 1, array_intersect_key($old, array_flip(['name', 'instructions', 'examples'])), true);
            $this->connection->update('email_writing_profile', ['current_revision' => $revision + 1, 'archived' => 1], ['id' => $id]);
        });
    }

    public function setDefault(string $id, int $revision, int $defaultRevision): void
    {
        $this->assertAvailable();
        $this->connection->transactional(function () use ($id, $revision, $defaultRevision): void {
            $this->lock();
            $profile = $this->get($id);
            $selection = $this->defaultSelection();
            if ($profile['revision'] !== $revision || $selection['revision'] !== $defaultRevision) throw $this->stale();
            $this->connection->update('email_writing_profile_settings', ['default_profile_id' => $id, 'default_revision' => $defaultRevision + 1], ['id' => 1]);
        });
    }

    private function input(array $input): array
    {
        $name = trim($this->text($input['name'] ?? null, 80, false));
        $instructions = $this->text($input['instructions'] ?? '', 6000, true);
        if ($name === '') throw new EmailPreparationException('profile_name', 'Give the writing profile a name.');
        $names = $input['example_names'] ?? []; $texts = $input['example_texts'] ?? [];
        if (!is_array($names) || !is_array($texts) || !array_is_list($names) || !array_is_list($texts) || count($names) > 3 || count($texts) > 3 || count($names) !== count($texts)) throw new EmailPreparationException('profile_examples', 'Use at most three named example emails.');
        $examples = [];
        foreach ($names as $index => $value) {
            $label = trim($this->text($value, 80, false)); $text = $this->text($texts[$index], 10000, true);
            if ($label === '' && trim($text) === '') continue;
            if ($label === '' || trim($text) === '') throw new EmailPreparationException('profile_examples', 'Each example needs a name and email text.');
            $examples[] = ['name' => $label, 'text' => $text];
        }
        $data = ['name' => $name, 'instructions' => $instructions, 'examples' => $examples];
        if (strlen(self::json($data)) > 131072) throw new EmailPreparationException('profile_limit', 'The writing profile exceeds the 128 KiB size limit.');
        return $data;
    }

    private function text(mixed $value, int $limit, bool $multiline): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
            || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u', $value)) throw new EmailPreparationException('profile_invalid', 'Check the profile field lengths and text format.');
        return $value;
    }
    private function revision(mixed $revision): int
    {
        if ((is_int($revision) && $revision > 0) || (is_string($revision) && preg_match('/^[1-9][0-9]{0,8}$/D', $revision))) return (int)$revision;
        throw $this->stale();
    }
    private function append(string $id, int $revision, array $data, bool $archived): void
    {
        $json = self::json($data);
        $this->connection->insert('email_writing_profile_revision', ['profile_id' => $id, 'revision' => $revision, 'payload_json' => $json, 'payload_sha256' => hash('sha256', $json), 'archived' => (int)$archived, 'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
    }
    private function decode(array $row): array
    {
        if (!hash_equals($row['payload_sha256'], hash('sha256', $row['payload_json']))) throw new EmailPreparationException('profile_storage', 'The saved writing profile could not be verified.', 503);
        $data = json_decode($row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        return $data + ['id' => $row['profile_id'], 'revision' => (int)$row['revision'], 'archived' => (bool)$row['archived'], 'builtin' => false, 'payloadHash' => $row['payload_sha256'], 'exampleCount' => count($data['examples']), 'createdAt' => $row['created_at']];
    }
    private function lock(): void { $this->connection->executeStatement('UPDATE email_writing_profile_settings SET default_revision=default_revision WHERE id=1'); }
    private function assertAvailable(): void { if (!$this->available()) throw new EmailPreparationException('profile_migration_required', 'Apply the writing profile migration before saving profiles.', 503); }
    private function stale(): EmailPreparationException { return new EmailPreparationException('profile_stale', 'This writing profile or default choice changed. Reload its current version before saving.', 409); }
    private static function json(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}
