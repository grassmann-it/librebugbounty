<?php

declare(strict_types=1);
namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class CribrumBadgeRenderingTest extends TestCase
{
    public function testAdditionalSuspicionIsVisibleAndActionsAreSeparateInBothLanguages(): void
    {
        foreach (['de'=>'SQL-Injection · Verdacht', 'en'=>'SQL injection · suspected'] as $locale=>$label) {
            $html=$this->render($locale, ['tags'=>['xss_confirmed','sqli_suspected','sql_error_observed'], 'caseSummary'=>['kind'=>'mixed','purity'=>'mixed']]);
            self::assertStringContainsString($label,$html);
            self::assertStringContainsString('data-additional="true"',$html);
            self::assertStringContainsString('cribrum%5B0%5D=sql_injection',$html);
            self::assertStringContainsString('class="cribrum-proof-link" href="#cribrum-',$html);
            self::assertStringContainsString('class="cribrum-badge"',$html);
            self::assertStringContainsString('aria-label=',$html);
            self::assertStringNotContainsString('>sqli_suspected<',$html);
        }
    }

    public function testMissingProjectionRetainsLiveClaimWithoutPretendingEvaluationIsCurrent(): void
    {
        $html=$this->render('de',null,[['claim'=>'sql_injection','state'=>'suspected','source'=>'manual']]);
        self::assertStringContainsString('SQL-Injection · Verdacht',$html);
        self::assertStringContainsString('Auswertung fehlt · weitere Befunde unbekannt',$html);
        self::assertStringContainsString('data-cribrum-freshness="missing"',$html);
        self::assertStringContainsString('Nicht zur Meldung ausgewählt',$html);
        self::assertStringContainsString('Manuell gespeichert',$html);
    }

    public function testMergedSourcesRetainBothKnownOrigins(): void
    {
        $html = $this->render('en', ['tags' => ['xss_confirmed'], 'components' => [['code' => 'xss_confirmed', 'certainty' => 'confirmed']]]);
        self::assertStringContainsString('Evidenced report component · Evaluation observation', $html);
    }

    public function testCompactViewKeepsSuspicionAndProofLinkVisibleWithoutDetails(): void
    {
        $html=$this->render('en',['tags'=>['sqli_suspected']],[],true);
        self::assertStringContainsString('SQL injection · suspected',$html);
        self::assertStringContainsString('View evidence',$html);
        self::assertStringNotContainsString('data-cribrum-proof>',$html);
        self::assertStringNotContainsString('<pre>',$html);
    }

    public function testUnsafeUnknownCodeIsEscapedAndDoesNotBecomeConfirmed(): void
    {
        $html=$this->render('en',['tags'=>['<script>alert(1)</script>']]);
        self::assertStringNotContainsString('<script>alert(1)</script>',$html);
        self::assertStringNotContainsString('data-certainty="confirmed"',$html);
    }

    public function testNewSqlFindingDoesNotLabelExistingXssAsNew(): void
    {
        $html=$this->render('de',['tags'=>['xss_confirmed','sqli_suspected']],[],false,['classification_changed']);
        $document=new \DOMDocument();
        @$document->loadHTML($html);
        $xpath=new \DOMXPath($document);
        self::assertSame(1,$xpath->query('//*[@data-cribrum-case-new]')->length);
        self::assertStringContainsString('Neue Erkenntnisse seit letzter Sichtung',$html);
        self::assertSame(0,$xpath->query('//li[@data-cribrum-code="xss"]//a[contains(@class,"cribrum-new")]')->length);
        self::assertSame(0,$xpath->query('//li[@data-cribrum-code="sql_injection"]//a[contains(@class,"cribrum-new")]')->length);
        self::assertStringContainsString('XSS · bestätigt',$html);
        self::assertStringContainsString('SQL-Injection · Verdacht',$html);
    }

    public function testNativeCodeSelectorOmitsCodesWhenNoFindingFilterIsChosen(): void
    {
        $locale='en';
        $filter=new \App\Dto\FindingReadFilter();
        $escape=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
        ob_start();
        try { require __DIR__.'/../templates/studio/cribrum-filters.php'; $html=ob_get_contents(); }
        finally { ob_end_clean(); }
        $document=new \DOMDocument();
        @$document->loadHTML($html);
        $xpath=new \DOMXPath($document);
        self::assertSame(0,$xpath->query('//select[@name="cribrum[]"]/option[@selected]')->length);
        self::assertSame(0,$xpath->query('//select[@name="cribrum[]"]/option[@value=""]')->length);
        self::assertStringContainsString('No selection: all cases.',$html);
    }

    private function render(string $locale,?array $reportProjection,array $cribrumClaims=[],bool $reportSummaryCompact=false,array $noticeReasons=[]): string
    {
        $finding=null;
        $view=(object)['triggeringObservations'=>array_map(static fn(string $reason):array=>['reason'=>$reason],$noticeReasons)];
        $escape=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
        $t=static fn(string $text):string=>$text;
        ob_start();
        try { require __DIR__.'/../templates/studio/report-summary.php'; return ob_get_contents(); }
        finally { ob_end_clean(); }
    }
}
