<?php

namespace App\Tests;

use App\Command\CribrumClassifyCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class CribrumClassifyIsolationTest extends DatabaseTestCase
{
    public function testRegisteredCommandDoesNotCreateCasesObservationsClaimsOrJobs(): void
    {
        $connection = $this->entityManager->getConnection();
        $tables = ['finding', 'finding_claim', 'http_observation', 'screenshot_job', 'retest_run', 'evidence'];
        $snapshot = static function () use ($connection, $tables): array {
            $rows = [];
            foreach ($tables as $table) $rows[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table);
            return $rows;
        };
        $before = $snapshot();
        $connection->executeStatement('PRAGMA query_only = ON');
        $file = APP_TEST_ROOT.'/offline-probe-response.txt';
        file_put_contents($file, "HTTP/1.1 500 Error\nContent-Type: text/html\n\nYou have an error in your SQL syntax OPENBUG");
        $tester = new CommandTester(self::getContainer()->get(CribrumClassifyCommand::class));
        self::assertSame(0, $tester->execute(['url' => 'https://fixture.test/?q=OPENBUG', '--response' => $file, '--format' => 'json']));
        self::assertNotEmpty(json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['classification']['suggestions']);
        self::assertSame($before, $snapshot());
        self::assertSame([], self::getContainer()->get(\App\Service\EvidenceStorageInterface::class)->listPaths());
        $connection->executeStatement('PRAGMA query_only = OFF');
    }
}
