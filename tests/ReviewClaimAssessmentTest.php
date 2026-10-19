<?php

namespace App\Tests;

use App\Cribrum\CribrumClassifier;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\CribrumProcessingService;
use App\Service\FindingService;
use App\Service\ReviewQueueService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use App\Entity\HttpObservation;

final class ReviewClaimAssessmentTest extends DatabaseTestCase
{
    private FindingService $findings;
    private ReviewQueueService $queue;
    private CribrumClaimService $claims;
    private CribrumProcessingService $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $request = Request::create('/review');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
        $this->findings = self::getContainer()->get(FindingService::class);
        $this->queue = self::getContainer()->get(ReviewQueueService::class);
        $storage = self::getContainer()->get(\App\Service\EvidenceStorageInterface::class);
        $this->claims = new CribrumClaimService($this->entityManager);
        $this->processor = new CribrumProcessingService(
            $this->entityManager,
            new CribrumClassifier('/nonexistent-private-cribrum-packs'),
            new CribrumObservationStore($this->entityManager, $storage),
            $this->claims,
        );
    }

    public function testEncodedSqlKeepsXssHistoricalAndConfirmsSelectedClaimsManually(): void
    {
        $finding = $this->case();
        $view = $this->queue->get(['images' => 'all'], $finding->getId(), true);
        $byClaim = array_column($view->claims, null, 'claim');
        self::assertSame(['error_disclosure', 'information_disclosure', 'sql_injection', 'xss'], array_keys($byClaim));
        self::assertSame('stale', $byClaim['xss']['state']);
        self::assertNotNull($view->claimObservationId);

        $token = $this->token($finding);
        $this->queue->assess($finding->getId(), 'confirmed', null, null, null, $token, true, [
            ['claim' => 'error_disclosure', 'state' => 'observed', 'report' => true, 'revision' => $byClaim['error_disclosure']['revision']],
            ['claim' => 'information_disclosure', 'state' => 'observed', 'report' => false, 'revision' => $byClaim['information_disclosure']['revision']],
            ['claim' => 'sql_injection', 'state' => 'confirmed', 'report' => true, 'revision' => $byClaim['sql_injection']['revision']],
            ['claim' => 'xss', 'state' => 'rejected', 'report' => false, 'revision' => $byClaim['xss']['revision']],
        ]);
        $current = array_column($this->claims->currentClaims($finding), null, 'claim');
        self::assertSame('manual', $current['sql_injection']->source);
        self::assertTrue($current['sql_injection']->reportSelected);
        self::assertFalse($current['information_disclosure']->reportSelected);
        self::assertSame('rejected', $current['xss']->state);
        $this->entityManager->refresh($finding);
        self::assertSame('confirmed', $finding->getManualAssessment());

        $this->expectException(\UnexpectedValueException::class);
        $this->queue->assess($finding->getId(), 'fixed', null, null, null, $token, true);
    }

    public function testFixedResolvesOnlyOpenClaimsAndKeepsRejectedHistory(): void
    {
        $finding = $this->case();
        $current = array_column($this->claims->currentClaims($finding), null, 'claim');
        $this->queue->assess($finding->getId(), 'confirmed', null, null, null, $this->token($finding), true, [
            ['claim' => 'xss', 'state' => 'rejected', 'report' => false, 'revision' => $current['xss']->revision],
            ['claim' => 'sql_injection', 'state' => 'confirmed', 'report' => true, 'revision' => $current['sql_injection']->revision],
        ]);

        $this->queue->assess($finding->getId(), 'fixed', null, null, null, $this->token($finding), true);
        $current = array_column($this->claims->currentClaims($finding), null, 'claim');
        self::assertSame('resolved', $current['error_disclosure']->state);
        self::assertSame('resolved', $current['sql_injection']->state);
        self::assertSame('rejected', $current['xss']->state);
        $this->entityManager->refresh($finding);
        self::assertSame('fixed', $finding->getManualAssessment());
    }

    private function case(): \App\Entity\Finding
    {
        $finding = $this->findings->createFinding('https://review-claims.example.test/a');
        $finding->setExpectedEvidence('<OPENBUGBOUNTY>');
        $this->entityManager->flush();
        $this->processor->process($finding, [
            'schema' => 1,
            'contentType' => 'text/html',
            'httpStatus' => 500,
            'finalUrl' => 'https://review-claims.example.test/a',
            'redirectChain' => [],
            'headers' => ['server' => 'fixture'],
            'rawHtml' => '<p>You have an error in your SQL syntax &lt;OPENBUGBOUNTY&gt;</p>',
            'rawHtmlBytes' => 65,
            'rawHtmlTruncated' => false,
            'domText' => 'You have an error in your SQL syntax <OPENBUGBOUNTY>',
            'domTextBytes' => 52,
            'domTextTruncated' => false,
        ]);
        $this->entityManager->refresh($finding);

        return $finding;
    }

    private function token(\App\Entity\Finding $finding): string
    {
        return $this->queue->contextToken($finding->getId(), $this->queue->fingerprint($finding->getId()));
    }
}
