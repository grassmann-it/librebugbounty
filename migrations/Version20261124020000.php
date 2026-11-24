<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Durable Hunter domain cache and application-local credit reservations independent of case deletion.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE hunter_domain_lookup (id TEXT NOT NULL PRIMARY KEY, domain TEXT NOT NULL, state TEXT NOT NULL, result TEXT NOT NULL, reserved_credits INTEGER NOT NULL, fetched_at TEXT NOT NULL)');
        $this->addSql('CREATE INDEX hunter_domain_lookup_domain ON hunter_domain_lookup(domain)');
    }
    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM hunter_domain_lookup') > 0, 'Hunter reservations must be retained; archive and reconcile them before rolling back.');
        $this->addSql('DROP TABLE hunter_domain_lookup');
    }
}
