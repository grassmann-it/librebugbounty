<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Versioned local email writing profiles and original-profile binding for Gmail receipts.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_writing_profile (id TEXT NOT NULL PRIMARY KEY, current_revision INTEGER NOT NULL, archived INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
        $this->addSql('CREATE TABLE email_writing_profile_revision (profile_id TEXT NOT NULL, revision INTEGER NOT NULL, payload_json TEXT NOT NULL, payload_sha256 TEXT NOT NULL, archived INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, PRIMARY KEY(profile_id, revision))');
        $this->addSql("CREATE TRIGGER email_writing_profile_revision_no_update BEFORE UPDATE ON email_writing_profile_revision BEGIN SELECT RAISE(ABORT, 'Writing profile revisions are immutable.'); END");
        $this->addSql("CREATE TRIGGER email_writing_profile_revision_no_delete BEFORE DELETE ON email_writing_profile_revision BEGIN SELECT RAISE(ABORT, 'Writing profile revisions are immutable.'); END");
        $this->addSql('CREATE TABLE email_writing_profile_settings (id INTEGER NOT NULL PRIMARY KEY CHECK(id=1), default_profile_id TEXT NOT NULL, default_revision INTEGER NOT NULL)');
        $this->addSql("INSERT INTO email_writing_profile_settings(id,default_profile_id,default_revision) VALUES(1,'builtin',1)");
        if ($this->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='gmail_draft_attempt'")) {
            $this->addSql("ALTER TABLE gmail_draft_attempt ADD COLUMN profile_id TEXT NOT NULL DEFAULT 'builtin'");
            $this->addSql('ALTER TABLE gmail_draft_attempt ADD COLUMN profile_revision INTEGER NOT NULL DEFAULT 1');
            $this->addSql("ALTER TABLE gmail_draft_attempt ADD COLUMN profile_sha256 TEXT NOT NULL DEFAULT ''");
        }
    }
    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Writing profile history and Gmail profile bindings must be retained.');
    }
}
