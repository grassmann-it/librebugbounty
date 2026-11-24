<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        // Historical web assertions deliberately exercise German copy. Locale
        // acceptance tests keep the browser default or choose their own translator.
        if ($this->usesGermanCopy()) {
            self::getContainer()->set(\App\Service\UiTranslator::class, new \App\Service\UiTranslator('de'));
        }

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $path = $this->entityManager->getConnection()->getParams()['path'] ?? '';
        if ($path !== APP_TEST_ROOT.'/database.sqlite') {
            throw new \RuntimeException('Refusing to reset a database outside the isolated test directory.');
        }
        if (($_SERVER['EVIDENCE_STORAGE_DIR'] ?? '') !== APP_TEST_ROOT.'/artifacts') {
            throw new \RuntimeException('Refusing to clear artifacts outside the isolated test directory.');
        }
        self::getContainer()->get(\App\Service\EvidenceStorageInterface::class)->clear();
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    protected function usesGermanCopy(): bool
    {
        return true;
    }

    private function resetSchema(): void
    {
        // DBAL-only reports, writing profiles and draft receipts are outside
        // ORM metadata. Clear them with their triggers between isolated cases.
        $connection = $this->entityManager->getConnection();
        foreach ($connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type='trigger' AND (name LIKE 'report_basis_%' OR name IN ('finding_behavior_fact_immutable','finding_report_revision_immutable') OR tbl_name IN ('email_writing_profile','email_writing_profile_revision','email_writing_profile_settings'))") as $name) {
            $connection->executeStatement('DROP TRIGGER '.$connection->quoteIdentifier($name));
        }
        $connection->executeStatement('DROP VIEW IF EXISTS finding_report_current');
        foreach (['finding_report_revision','finding_behavior_fact','finding_report_context','report_source_contract',
            'email_writing_profile_settings','email_writing_profile_revision','email_writing_profile','gmail_draft_attempt','hunter_domain_lookup'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
        }
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);

        try {
            if ($metadata !== []) {
                $schemaTool->dropSchema($metadata);
            }
        } catch (\Throwable) {
            // Ignore clean-up errors when the database is still empty.
        }

        if ($metadata !== []) {
            $schemaTool->createSchema($metadata);
        }
    }
}
