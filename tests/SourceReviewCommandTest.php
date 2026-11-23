<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\SourceReviewClaimCommand;
use App\Command\SourceReviewRecordCommand;
use App\Service\CribrumObservationStore;
use App\Service\FindingService;
use App\Service\SourceReviewService;
use App\Service\WorkerProofResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SourceReviewCommandTest extends DatabaseTestCase
{
    public function testClaimAndActualReadingReceiptRoundTripThroughJsonCli(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://source-review.fixture.test/page');
        self::getContainer()->get(CribrumObservationStore::class)->store($finding, [
            'schema' => 1, 'httpStatus' => 200, 'contentType' => 'text/html', 'finalUrl' => $finding->getPocUrl(),
            'redirectChain' => [], 'headers' => [],
            'rawHtml' => '<p>Stored fixture source.</p>', 'rawHtmlBytes' => strlen('<p>Stored fixture source.</p>'),
            'domText' => 'Stored fixture source.', 'domTextBytes' => strlen('Stored fixture source.'),
            'rawHtmlTruncated' => false, 'domTextTruncated' => false,
        ], requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
        $connection = $this->entityManager->getConnection();
        $before = $connection->fetchAllAssociative('SELECT * FROM finding');
        $reviews = self::getContainer()->get(SourceReviewService::class);
        $claim = new CommandTester(new SourceReviewClaimCommand($reviews));
        self::assertSame(Command::SUCCESS, $claim->execute(['--finding' => $finding->getId(), '--reviewer' => 'Codex / fixture model']));
        $work = json_decode($claim->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $work);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $work[0]['leaseToken']);
        self::assertSame($finding->getId(), $work[0]['findingId']);
        $path = APP_TEST_ROOT.'/source-review-summary.txt';
        file_put_contents($path, "Read the retained fixture source.\nNo additional hint found.");
        $record = new CommandTester(new SourceReviewRecordCommand($reviews));
        self::assertSame(Command::SUCCESS, $record->execute([
            'lease' => $work[0]['leaseToken'], '--outcome' => 'no_new_hint', '--summary-file' => $path,
            '--classifier-version' => 'cribrum-fixture',
        ]));
        $receipt = json_decode($record->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('recorded', $receipt['status']);
        self::assertSame('Codex / fixture model', $receipt['reviewer']);
        self::assertSame("Read the retained fixture source.\nNo additional hint found.", $receipt['summary']);
        self::assertSame('current', $reviews->readCurrentForFinding($finding)['status']);
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM finding'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding_claim'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM source_review_lease'));
    }

    public function testInvalidCliInputsAndMissingMigrationDoNotPretendAReviewWasRecorded(): void
    {
        $reviews = self::getContainer()->get(SourceReviewService::class);
        $claim = new CommandTester(new SourceReviewClaimCommand($reviews));
        self::assertSame(Command::INVALID, $claim->execute(['--reviewer' => 'Codex', '--limit' => '0']));
        self::assertSame(Command::INVALID, $claim->execute(['--reviewer' => 'Codex', '--limit' => '1.5']));
        $record = new CommandTester(new SourceReviewRecordCommand($reviews));
        self::assertSame(Command::INVALID, $record->execute([
            'lease' => str_repeat('a', 64), '--outcome' => 'no_new_hint', '--summary' => 'Fixture',
            '--summary-file' => '/missing/fixture', '--classifier-version' => 'fixture',
        ]));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM source_review'));
        $this->entityManager->getConnection()->executeStatement('DROP TABLE source_review_lease');
        self::assertSame(Command::FAILURE, $claim->execute(['--reviewer' => 'Codex']));
        self::assertStringContainsString('Datenbankaktualisierung', $claim->getDisplay());
    }
}
