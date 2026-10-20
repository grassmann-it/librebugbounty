<?php

namespace App\Tests\Cribrum;

use App\Controller\ObservationDiagnosticsController;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\HttpObservation;
use App\Service\CribrumObservationStore;
use App\Service\ObservationDiagnostics;
use App\Tests\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ObservationDiagnosticsTest extends DatabaseTestCase
{
    public function testCapturedContextWinsAfterPocChangesAndDoesNotWrite(): void
    {
        $observation = $this->observation();
        $before = $observation->getClassification();
        $observation->getFinding()->setExpectedEvidence('TODAYS-MARKER')->setPayload('unrelated');
        $this->entityManager->flush();
        $view = self::getContainer()->get(ObservationDiagnostics::class)->inspect($observation);
        self::assertSame(['xss'], array_column($view['current']['suggestions'], 'claim'));
        self::assertSame('observed', $view['current']['suggestions'][0]['state']);
        self::assertNotEmpty($view['rules']);
        self::assertContains('excluded', array_column($view['rules'], 'outcome'));
        self::assertContains('hit', array_column($view['rules'], 'outcome'));
        self::assertSame([], $view['errors']);
        $this->entityManager->refresh($observation);
        self::assertSame($before, $observation->getClassification());
    }

    public function testLegacyContextNeverUsesCurrentPocAndKeepsStoredResult(): void
    {
        $observation = $this->observation(false);
        $view = self::getContainer()->get(ObservationDiagnostics::class)->inspect($observation);
        self::assertNull($view['current']);
        self::assertNotEmpty($view['warnings']);
        self::assertSame($observation->getClassification(), $view['stored']);
        self::assertArrayHasKey('core', $view['active']['packs']);
    }

    public function testMissingArtifactShowsErrorsAndVersions(): void
    {
        $observation = $this->observation();
        $observation->setArtifact(null, null);
        $view = self::getContainer()->get(ObservationDiagnostics::class)->inspect($observation);
        self::assertNull($view['current']);
        self::assertStringContainsString('no stored artifact', implode(' ', $view['errors']));
        self::assertArrayHasKey('core', $view['active']['packs']);
    }

    public function testDiagnosticHtmlEscapesStoredAndLiveEvidenceAndScopesObservation(): void
    {
        $observation = $this->observation();
        $controller = self::getContainer()->get(ObservationDiagnosticsController::class);
        $response = self::$kernel->handle(Request::create('/findings/'.$observation->getFinding()->getId().'/diagnostics'));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $response->getContent());
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->getContent());
        self::assertStringContainsString('core / 2026.10.19 /', $response->getContent());
        self::assertTrue($response->headers->has('Content-Security-Policy'));
        $other = (new Finding())->setTitle('Diagnostics fixture')->setType('xss')->setDomain($observation->getFinding()->getDomain())->setUrl('https://other.test/')->setPocUrl('https://other.test/', 1);
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        self::assertSame(404, $controller->show($other->getId(), Request::create('/?observation='.$observation->getId()))->getStatusCode());
        self::assertSame(404, $controller->show('missing', Request::create('/'))->getStatusCode());
        self::assertStringContainsString('Observation-Diagnostik', self::$kernel->handle(Request::create('/findings/'.$other->getId()))->getContent());
    }

    private function observation(bool $context = true): HttpObservation
    {
        $domain = (new Domain())->setHostname('fixture.test');
        $finding = (new Finding())->setTitle('Diagnostics fixture')->setType('xss')->setDomain($domain)->setUrl('https://fixture.test/')->setPocUrl('https://fixture.test/', 1)->setExpectedEvidence('OLD-MARKER');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $marker = '<img src=x onerror=alert(1)>';
        $observation = self::getContainer()->get(CribrumObservationStore::class)->store($finding, [
            'redirectChain' => [], 'headers' => [],
            'rawHtmlBytes' => strlen($marker) + 7, 'rawHtmlTruncated' => false,
            'domTextBytes' => strlen($marker), 'domTextTruncated' => false,
            'schema' => 1, 'rawHtml' => '<p>'.$marker.'</p>', 'domText' => $marker,
            'contentType' => 'text/html', 'httpStatus' => 200, 'finalUrl' => 'https://fixture.test/',
        ], classificationContext: $context ? ['url' => 'https://fixture.test/', 'payload' => null, 'expectedEvidence' => $marker, 'dialogSeen' => true, 'dialogText' => $marker] : null);
        $observation->setClassification(['classifierVersion' => 'historical', 'packs' => ['old' => '1'], 'signals' => [], 'suggestions' => [], 'diagnostics' => [$marker]]);
        $this->entityManager->flush();
        return $observation;
    }
}
