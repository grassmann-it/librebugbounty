<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Durable Gmail draft attempt receipts without changing report/contact status.'; }
    public function up(Schema $schema): void
    {
        // Keep receipts after case deletion: deletion must not erase uncertain remote writes.
        $this->addSql("CREATE TABLE gmail_draft_attempt (attempt_id TEXT NOT NULL PRIMARY KEY, case_key TEXT NOT NULL, finding_id TEXT NOT NULL, account TEXT NOT NULL, fingerprint TEXT NOT NULL, language TEXT NOT NULL, content_sha256 TEXT NOT NULL, recipient TEXT NOT NULL, subject TEXT NOT NULL, state TEXT NOT NULL, draft_id TEXT DEFAULT NULL, message_id TEXT DEFAULT NULL, stale INTEGER NOT NULL DEFAULT 0, safe_to_retry INTEGER NOT NULL DEFAULT 0, receipt_revision INTEGER NOT NULL DEFAULT 0, error_code TEXT DEFAULT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
        $this->addSql('CREATE UNIQUE INDEX gmail_draft_attempt_active ON gmail_draft_attempt(account, case_key) WHERE safe_to_retry=0');
        $this->addSql('CREATE INDEX gmail_draft_attempt_finding ON gmail_draft_attempt(finding_id, account)');
    }
    public function down(Schema $schema): void
    {
        $this->abortIf((int)$this->connection->fetchOne('SELECT COUNT(*) FROM gmail_draft_attempt') > 0, 'Gmail receipts must be retained; resolve and archive them before rolling back.');
        $this->addSql('DROP TABLE gmail_draft_attempt');
    }
}
