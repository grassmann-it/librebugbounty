<?php

namespace App\Tests\Cribrum;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\TestCase;

final class ClassifierQueryDeltaTest extends TestCase
{
    private const QUERY = "SELECT id FROM synthetic_records WHERE title = 'OPENBUG'";
    private const DIAGNOSTIC = 'SQLSTATE[HY000]: General error: 1267 Illegal mix of collations';

    public function testDiagnosticQueryDoesNotNeedARequestValueAndClaimsNoBinding(): void
    {
        $body = "<strong>An exception occurred while executing 'SELECT id FROM synthetic_records WHERE title = ?' with params [\"different\"]: ".self::DIAGNOSTIC.'</strong><pre>#9 lookup(OPENBUG)</pre>';
        $result = $this->classify($body);
        $disclosure = array_values(array_filter($result['suggestions'], static fn ($s) => $s['claim'] === 'information_disclosure'));
        self::assertCount(1, $disclosure);
        self::assertContains('A SQL query was returned in a database diagnostic.', $disclosure[0]['reasons']);
        self::assertStringNotContainsString('request-specific', json_encode($disclosure));
        self::assertCount(2, $disclosure[0]['spans']);
        self::assertStringNotContainsString('OPENBUG', $disclosure[0]['spans'][1]['excerpt']);
        self::assertNotContains('sql_injection', array_column($result['suggestions'], 'claim'));
    }

    public function testAcceptedRequestSpecificEchoWithoutErrorWrapperRemainsSupported(): void
    {
        $result = $this->classify('<p>'.self::QUERY.'</p>');
        self::assertContains('sql-query-echo', array_column($result['signals'], 'rule'));
        $disclosure = array_values(array_filter($result['suggestions'], static fn ($s) => $s['claim'] === 'information_disclosure'));
        self::assertContains('A request-specific SQL query was returned.', $disclosure[0]['reasons']);
    }

    public function testStaticAttributeDocumentationAndTemplateCannotUseOldEchoFallback(): void
    {
        $wrapper = '<strong>'.self::DIAGNOSTIC.' Query: '.self::QUERY.'</strong>';
        foreach ([
            '<div data-example="'.$wrapper.'">ordinary page</div>',
            '<article class="documentation">'.$wrapper.'</article>',
            '<template>'.$wrapper.'</template>',
            '<!--'.$wrapper.'-->',
            '<script>const example='.json_encode($wrapper).';</script>',
        ] as $body) {
            self::assertNotContains('sql-query-echo', array_column($this->classify($body)['signals'], 'rule'), $body);
        }
    }

    public function testShadowAndCoreSuggestionsRemainEqualAfterBuiltinCorrection(): void
    {
        $body = '<strong>'.self::DIAGNOSTIC.' Query: '.self::QUERY.'</strong>';
        self::assertSame($this->classify($body)['suggestions'], $this->classify($body, true)['suggestions']);
    }

    public function testOrdinarySelectFromAndPageIsNotQueryDisclosure(): void
    {
        $body = '<a href="#model">Select your model</a><h2>Getting the Most From Your Heat Pump</h2><p>Controllers and Parts</p>';
        self::assertNotContains('information_disclosure', array_column($this->classify($body)['suggestions'], 'claim'));
    }

    public function testHiddenServerQueryIsDisclosureButRequestProvidedQueryIsNot(): void
    {
        $query = 'SELECT id FROM synthetic_records WHERE active=1 ORDER BY id';
        $body = '<form><input type="hidden" name="sQuery" value="'.$query.'"></form>';
        $result = $this->classify($body);
        self::assertContains('information_disclosure', array_column($result['suggestions'], 'claim'));
        self::assertNotContains('sql_injection', array_column($result['suggestions'], 'claim'));
        $input = new ObservationInput('https://fixture.test/?sQuery='.rawurlencode($query), $body, null, 'text/html', 200, null, null, null, false, null);
        $provided = (new CribrumClassifier('/nonexistent'))->classify($input)->toArray();
        self::assertNotContains('information_disclosure', array_column($provided['suggestions'], 'claim'));
    }

    public function testHiddenQueryRequiresActiveFormContextAndRawSpanIsExact(): void
    {
        $value = 'SELECT id FROM synthetic_records WHERE name=&#39;München&#39; AND active=1';
        $field = '<input type="hidden" name="sQuery" value="'.$value.'">';
        foreach (['<template>'.$field.'</template>', '<code>'.$field.'</code>', '<div class="documentation">'.$field.'</div>'] as $body) {
            self::assertNotContains('information_disclosure', array_column($this->classify($body)['suggestions'], 'claim'));
        }
        $result = $this->classify('<form>'.$field.'</form>');
        $disclosure = array_values(array_filter($result['suggestions'], static fn ($s) => $s['claim'] === 'information_disclosure'));
        self::assertCount(1, $disclosure);
        self::assertSame($value, $disclosure[0]['spans'][0]['excerpt']);
        $decoy = '<form><input type="hidden" name="sQuery" title="value=&quot;'.$value.'&quot;" value="ordinary"></form>';
        self::assertNotContains('information_disclosure', array_column($this->classify($decoy)['suggestions'], 'claim'));
        $input = new ObservationInput('https://fixture.test/?sQuery='.rawurlencode("SELECT id FROM synthetic_records WHERE name='München' AND active=1 ;"), '<form>'.$field.'</form>', null, 'text/html', 200, null, null, null, false, null);
        self::assertNotContains('information_disclosure', array_column((new CribrumClassifier('/nonexistent'))->classify($input)->toArray()['suggestions'], 'claim'));
    }

    public function testDeliveredJoinedQueryWithMarkupInsideLiteralRemainsDisclosure(): void
    {
        $payload = '<script>alert(3)</script>';
        $query = "SELECT interviews.id, museums.name FROM interviews, museums WHERE interviews.term LIKE '%".$payload."%' AND interviews.museum_id = museums.id";
        $body = '<table><tr><td>No results</td></tr></table>'."\n\n".$query;
        $input = new ObservationInput('https://fixture.test/?s='.rawurlencode($payload), $body, null, 'text/html', 200, null, null, null, false, null);
        $result = (new CribrumClassifier('/nonexistent'))->classify($input)->toArray();
        $disclosure = array_values(array_filter($result['suggestions'], static fn ($s) => $s['claim'] === 'information_disclosure'));
        self::assertCount(1, $disclosure);
        self::assertSame($query, $disclosure[0]['spans'][0]['excerpt']);
        self::assertNotContains('sql_injection', array_column($result['suggestions'], 'claim'));
        $prose = '<h1>Catalog</h1>'."\n"."SELECT your model <b> FROM our catalogue</b> WHERE choices include 'OPENBUG'";
        self::assertNotContains('information_disclosure', array_column($this->classify($prose)['suggestions'], 'claim'));
        $commentedProse = '<h1>Catalog</h1>'."\n"."SELECT your model <!-- separator --> FROM our catalogue WHERE choices include 'OPENBUG'";
        self::assertNotContains('information_disclosure', array_column($this->classify($commentedProse)['suggestions'], 'claim'));
    }

    private function classify(string $body, bool $shadow = false): array
    {
        $input = new ObservationInput('https://fixture.test/?q=OPENBUG', $body, null, 'text/html', 200, null, null, null, false, null);
        return (new CribrumClassifier('/nonexistent', $shadow ? dirname(__DIR__, 2).'/resources/cribrum/candidates' : null))->classify($input)->toArray();
    }
}
