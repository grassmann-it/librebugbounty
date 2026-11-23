<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Atomic receipts for sequential queued review assessments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE review_queued_assessment_receipt (id VARCHAR(36) NOT NULL, finding_id VARCHAR(36) NOT NULL, trail_id VARCHAR(36) NOT NULL, payload_hash VARCHAR(64) NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_review_queued_receipt_created ON review_queued_assessment_receipt (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE review_queued_assessment_receipt');
    }
}
