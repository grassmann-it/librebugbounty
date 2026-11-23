<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Track hash-bound AI source-reading receipts and expiring parallel-worker reservations without changing findings.'; }

    public function up(Schema $schema): void
    {
        $identity = 'observation_id VARCHAR(36) NOT NULL, artifact_sha256 VARCHAR(64) NOT NULL, context_sha256 VARCHAR(64) NOT NULL, policy_version VARCHAR(32) NOT NULL, poc_revision INTEGER NOT NULL, request_context_sha256 VARCHAR(64) NOT NULL, poc_url_sha256 VARCHAR(64) NOT NULL, reviewer VARCHAR(128) NOT NULL';
        $this->addSql("CREATE TABLE source_review (id VARCHAR(36) NOT NULL, $identity, outcome VARCHAR(32) NOT NULL CHECK(outcome IN ('no_new_hint','potential_gap','corrected_gap','context_required')), summary CLOB NOT NULL, classifier_version VARCHAR(128) NOT NULL, lease_token VARCHAR(64) NOT NULL, reviewed_at DATETIME NOT NULL, finding_id VARCHAR(36) NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_CFBCA4204323B5E7 FOREIGN KEY(finding_id) REFERENCES finding(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX IDX_CFBCA4204323B5E7 ON source_review(finding_id)');
        $this->addSql('CREATE INDEX idx_source_review_finding ON source_review(finding_id,reviewed_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_source_review_identity ON source_review(finding_id,artifact_sha256,context_sha256,policy_version)');
        $this->addSql('CREATE UNIQUE INDEX uniq_source_review_token ON source_review(lease_token)');
        $this->addSql("CREATE TRIGGER source_review_immutable BEFORE UPDATE ON source_review BEGIN SELECT RAISE(ABORT, 'immutable source review'); END");
        $this->addSql("CREATE TABLE source_review_lease (id VARCHAR(36) NOT NULL, $identity, lease_token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, finding_id VARCHAR(36) NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_23A384364323B5E7 FOREIGN KEY(finding_id) REFERENCES finding(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX IDX_23A384364323B5E7 ON source_review_lease(finding_id)');
        $this->addSql('CREATE INDEX idx_source_review_lease_expiry ON source_review_lease(expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_source_review_lease_identity ON source_review_lease(finding_id,artifact_sha256,context_sha256,policy_version)');
        $this->addSql('CREATE UNIQUE INDEX uniq_source_review_lease_token ON source_review_lease(lease_token)');
    }

    public function down(Schema $schema): void { $this->abortIf(true, 'Restore a deliberate backup rather than deleting source-review history.'); }
}
