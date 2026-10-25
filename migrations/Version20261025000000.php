<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Record human rule-review hints and immutable review events for deselected classifier proposals.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE cribrum_rule_review_hint (id VARCHAR(36) PRIMARY KEY, finding_id VARCHAR(36) NOT NULL REFERENCES finding(id) ON DELETE CASCADE, claim VARCHAR(48) NOT NULL, rule_id VARCHAR(128) NOT NULL, observation_id VARCHAR(36) NOT NULL, classifier_version VARCHAR(128) NOT NULL, pack_version VARCHAR(128) NOT NULL, evidence_ids CLOB NOT NULL DEFAULT '[]', status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','reviewed')), revision INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, note CLOB DEFAULT NULL, CONSTRAINT uniq_cribrum_rule_review_hint UNIQUE(finding_id,claim,rule_id,observation_id,classifier_version,pack_version))");
        $this->addSql('CREATE INDEX idx_cribrum_rule_review_status ON cribrum_rule_review_hint(status, created_at, id)');
        $this->addSql("CREATE TABLE cribrum_rule_review_event (id VARCHAR(36) PRIMARY KEY, hint_id VARCHAR(36) NOT NULL REFERENCES cribrum_rule_review_hint(id) ON DELETE CASCADE, action VARCHAR(16) NOT NULL CHECK(action IN ('raised','reviewed')), revision INTEGER NOT NULL, note CLOB DEFAULT NULL, created_at DATETIME NOT NULL)");
        $this->addSql('CREATE INDEX idx_cribrum_rule_review_event_hint ON cribrum_rule_review_event(hint_id, revision)');
        $this->addSql("CREATE TRIGGER cribrum_rule_review_event_immutable BEFORE UPDATE ON cribrum_rule_review_event BEGIN SELECT RAISE(ABORT, 'immutable rule review event'); END");
        $this->addSql("CREATE TABLE cribrum_review_selection (id VARCHAR(36) PRIMARY KEY, finding_id VARCHAR(36) NOT NULL REFERENCES finding(id) ON DELETE CASCADE, option_key VARCHAR(128) NOT NULL, observation_id VARCHAR(36) NOT NULL, selected BOOLEAN NOT NULL, revision INTEGER NOT NULL DEFAULT 1, provenance CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, CONSTRAINT uniq_cribrum_review_selection UNIQUE(finding_id,option_key,observation_id))");
        $this->addSql("CREATE TABLE cribrum_review_selection_event (id VARCHAR(36) PRIMARY KEY, selection_id VARCHAR(36) NOT NULL REFERENCES cribrum_review_selection(id) ON DELETE CASCADE, revision INTEGER NOT NULL, selected BOOLEAN NOT NULL, provenance CLOB NOT NULL, created_at DATETIME NOT NULL)");
        $this->addSql('CREATE INDEX idx_cribrum_review_selection_event ON cribrum_review_selection_event(selection_id,revision)');
        $this->addSql("CREATE TRIGGER cribrum_review_selection_event_immutable BEFORE UPDATE ON cribrum_review_selection_event BEGIN SELECT RAISE(ABORT, 'immutable review selection event'); END");
    }

    public function down(Schema $schema): void { $this->abortIf(true, 'Restore a deliberate backup rather than deleting review history.'); }
}
