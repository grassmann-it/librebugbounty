<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add Cribrum claim history and bounded HTTP observations.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE finding_claim (finding_id VARCHAR(36) NOT NULL, claim VARCHAR(48) NOT NULL, state VARCHAR(16) NOT NULL, report_selected BOOLEAN NOT NULL, severity VARCHAR(20) DEFAULT NULL, source VARCHAR(16) NOT NULL, revision INTEGER NOT NULL, observation_id VARCHAR(36) DEFAULT NULL, updated_at DATETIME NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(finding_id, claim), CONSTRAINT FK_FINDING_CLAIM_FINDING FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX idx_finding_claim_finding ON finding_claim (finding_id)');
        $this->addSql('CREATE UNIQUE INDEX pk_finding_claim ON finding_claim (finding_id, claim)');
        $this->addSql('CREATE INDEX idx_finding_claim_state ON finding_claim (claim, state)');
        $this->addSql("CREATE TABLE finding_claim_revision (id VARCHAR(36) NOT NULL, finding_id VARCHAR(36) NOT NULL, claim VARCHAR(48) NOT NULL, revision INTEGER NOT NULL, previous_state VARCHAR(16) DEFAULT NULL, state VARCHAR(16) NOT NULL, report_selected BOOLEAN NOT NULL, severity VARCHAR(20) DEFAULT NULL, source VARCHAR(16) NOT NULL, rule_ids CLOB DEFAULT NULL, reason CLOB DEFAULT NULL, observation_id VARCHAR(36) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_FINDING_CLAIM_REV_FINDING FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX idx_finding_claim_revision_finding ON finding_claim_revision (finding_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finding_claim_revision ON finding_claim_revision (finding_id, claim, revision)');
        $this->addSql('CREATE INDEX idx_finding_claim_history ON finding_claim_revision (finding_id, claim, created_at)');
        $this->addSql("CREATE TABLE http_observation (id VARCHAR(36) NOT NULL, finding_id VARCHAR(36) NOT NULL, screenshot_job_id VARCHAR(36) DEFAULT NULL, retest_run_id VARCHAR(36) DEFAULT NULL, poc_revision INTEGER NOT NULL, http_status INTEGER DEFAULT NULL, final_url CLOB DEFAULT NULL, content_type VARCHAR(255) DEFAULT NULL, artifact_path VARCHAR(1024) DEFAULT NULL, artifact_sha256 VARCHAR(64) DEFAULT NULL, raw_bytes INTEGER NOT NULL, raw_truncated BOOLEAN NOT NULL, dom_bytes INTEGER NOT NULL, dom_truncated BOOLEAN NOT NULL, metadata CLOB DEFAULT NULL, classification CLOB DEFAULT NULL, classified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_HTTP_OBSERVATION_FINDING FOREIGN KEY (finding_id) REFERENCES finding (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX idx_http_observation_finding ON http_observation (finding_id, created_at)');
        $this->addSql("INSERT INTO finding_claim (finding_id, claim, state, report_selected, severity, source, revision, observation_id, updated_at, created_at) SELECT id, 'xss', 'observed', true, severity, 'migration', 1, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM finding WHERE type = 'reflected_xss'");
        $uuid = "lower(hex(randomblob(4)))||'-'||lower(hex(randomblob(2)))||'-4'||substr(lower(hex(randomblob(2))),2)||'-8'||substr(lower(hex(randomblob(2))),2)||'-'||lower(hex(randomblob(6)))";
        $this->addSql("INSERT INTO finding_claim_revision (id, finding_id, claim, revision, previous_state, state, report_selected, severity, source, rule_ids, reason, observation_id, created_at) SELECT $uuid, id, 'xss', 1, NULL, 'observed', true, severity, 'migration', NULL, 'Migrated legacy reflected_xss finding type', NULL, CURRENT_TIMESTAMP FROM finding WHERE type = 'reflected_xss'");
    }

    public function down(Schema $schema): void { $this->abortIf(true, 'Restore a deliberate backup instead of deleting Cribrum history.'); }
}
