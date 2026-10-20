<?php

namespace App\Tests;

use App\Command\ArtifactAuditCommand;
use App\Service\EvidenceStorageInterface;
use App\Service\LocalEvidenceStorage;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ArtifactAuditCommandTest extends TestCase
{
    public function testCountsAllReferenceSourcesAndObservationOrphansWithoutChanges(): void
    {
        $root = APP_TEST_ROOT.'/audit-'.bin2hex(random_bytes(4));
        mkdir($root, 0700);
        $files = ['evidence.png' => 'image', 'retest.png' => 'retest', 'job.png' => 'job',
            'observation.json' => '{"rawHtml":"fixture"}', 'orphan-observation.json' => 'orphan'];
        foreach ($files as $path => $contents) {
            file_put_contents($root.'/'.$path, $contents);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            foreach (['evidence' => 'file_path', 'retest_run' => 'screenshot_path',
                'screenshot_job' => 'screenshot_path', 'http_observation' => 'artifact_path'] as $table => $column) {
                $connection->executeStatement("CREATE TABLE $table ($column TEXT)");
            }
            $connection->insert('evidence', ['file_path' => 'storage/artifacts/evidence.png']);
            $connection->insert('retest_run', ['screenshot_path' => 'retest.png']);
            $connection->insert('screenshot_job', ['screenshot_path' => 'job.png']);
            // Both path conventions and duplicate references identify one physical file.
            foreach (['storage/artifacts/observation.json', 'observation.json', 'missing-observation.json', null] as $path) {
                $connection->insert('http_observation', ['artifact_path' => $path]);
            }
            $before = $connection->fetchAllAssociative('SELECT * FROM http_observation');
            $connection->executeStatement('PRAGMA query_only = ON');
            $storage = new LocalEvidenceStorage($root);
            $tester = new CommandTester(new ArtifactAuditCommand($connection, $storage));
            self::assertSame(1, $tester->execute([]));
            $output = $tester->getDisplay();
            $this->assertMetric($output, 'Stored files', 5);
            $this->assertMetric($output, 'Stored bytes (readable files)', array_sum(array_map('strlen', $files)));
            $this->assertMetric($output, 'Unique referenced paths', 5);
            $this->assertMetric($output, 'Missing or unavailable referenced files', 1);
            $this->assertMetric($output, 'Possible orphan files', 1);
            $this->assertMetric($output, 'Possible orphan bytes (readable files)', 6);
            self::assertStringContainsString('missing-observation.json', $output);
            self::assertStringContainsString('orphan-observation.json', $output);
            self::assertDoesNotMatchRegularExpression('/Unreferenced file\s+storage\/artifacts\/observation\.json/', $output);
            self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM http_observation'));
            self::assertCount(count($files), $storage->listPaths());
            foreach ($files as $path => $contents) {
                self::assertSame($contents, file_get_contents($root.'/'.$path));
            }
            $connection->executeStatement('PRAGMA query_only = OFF');
            $connection->delete('http_observation', ['artifact_path' => 'missing-observation.json']);
            $connection->insert('http_observation', ['artifact_path' => 'orphan-observation.json']);
            $connection->executeStatement('PRAGMA query_only = ON');
            self::assertSame(0, $tester->execute([]), $tester->getDisplay());
            $this->assertMetric($tester->getDisplay(), 'Missing or unavailable referenced files', 0);
            $this->assertMetric($tester->getDisplay(), 'Possible orphan files', 0);
        } finally {
            $connection->close();
        }
    }

    public function testUnavailableSizeReportsIncompleteTotals(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(['observation.json']);
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('exists')->willReturn(true);
        $storage->method('listPaths')->willReturn(['storage/artifacts/observation.json']);
        $storage->method('size')->willReturn(null);
        foreach (['storeFile', 'storeContents', 'deleteFile', 'deleteForFinding', 'clear'] as $method) {
            $storage->expects(self::never())->method($method);
        }
        $tester = new CommandTester(new ArtifactAuditCommand($connection, $storage));
        self::assertSame(1, $tester->execute([]));
        $this->assertMetric($tester->getDisplay(), 'Files with unavailable size', 1);
        self::assertStringContainsString('byte totals are incomplete', $tester->getDisplay());
    }

    public function testEmptyStoreReportsZeroTotalsAndSucceeds(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $storage = $this->createMock(EvidenceStorageInterface::class);
        $storage->method('listPaths')->willReturn([]);
        $storage->expects(self::never())->method('size');
        $tester = new CommandTester(new ArtifactAuditCommand($connection, $storage));
        self::assertSame(0, $tester->execute([]));
        foreach (['Stored files', 'Stored bytes (readable files)', 'Unique referenced paths', 'Possible orphan files'] as $label) {
            $this->assertMetric($tester->getDisplay(), $label, 0);
        }
    }

    private function assertMetric(string $output, string $label, int $value): void
    {
        self::assertMatchesRegularExpression('/'.preg_quote($label, '/').'\s+'.$value.'\s/', $output);
    }
}
