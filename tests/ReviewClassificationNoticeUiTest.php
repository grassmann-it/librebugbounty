<?php
namespace App\Tests;

use App\Controller\ReviewController;
use App\Dto\ReviewQueueView;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\ReviewQueueService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ReviewClassificationNoticeUiTest extends DatabaseTestCase
{
    public function testInterpretationNoticeOffersExplicitAcknowledgementWithoutManualVerdict(): void
    {
        $domain = (new Domain())->setHostname('notice-ui.test');
        $finding = (new Finding())->setDomain($domain)->setUrl('https://notice-ui.test/?q=1')->setTitle('Notice UI')->setType('other')->setSeverity('low');
        $this->entityManager->persist($domain); $this->entityManager->persist($finding); $this->entityManager->flush();
        $queue = self::getContainer()->get(ReviewQueueService::class);
        $base = $queue->get(['images' => 'all']);
        self::assertNotNull($base->detail);
        $request = Request::create('/review?images=all');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(\Symfony\Component\HttpFoundation\RequestStack::class)->push($request);
        $render = new \ReflectionMethod(ReviewController::class, 'render');
        $controller = self::getContainer()->get(ReviewController::class);
        $html = $render->invoke($controller, $request, $base)->getContent();
        self::assertStringNotContainsString('value="acknowledge_change"', $html);
        $view = new ReviewQueueView(...array_replace(get_object_vars($base), [
            'notice' => true,
            'triggeringObservations' => [['id' => 'interpretation', 'reason' => 'classification_changed', 'result' => 'classification_changed', 'mode' => 'classification', 'started_at' => null, 'finished_at' => null, 'error_message' => '']],
        ]));
        $html = $render->invoke($controller, $request, $view)->getContent();
        self::assertStringContainsString('value="acknowledge_change"', $html);
        self::assertStringContainsString('Geprüft · Klassifikationshinweis erledigen', $html);
        self::assertStringContainsString('name="context_token"', $html);
        self::assertNull($finding->getManualAssessment());
    }
}
