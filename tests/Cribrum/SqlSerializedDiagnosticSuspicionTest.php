<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClaimSuggestion;
use App\Cribrum\ObservationInput;
use App\Cribrum\SqlSerializedDiagnosticSuspicion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlSerializedDiagnosticSuspicionTest extends TestCase
{
    private const VALUE = '-->\'"></title></noscript></script><img src=0 onerror=alert(\'fixtureMARK12\')>';

    public function testNativeMariaDbDiagnosticInBrokenScriptRetainsOnlySourceLevelSuspicion(): void
    {
        $suffix = substr(self::VALUE, 6);
        self::assertSame(76, strlen(self::VALUE));
        self::assertSame(70, strlen($suffix));
        self::assertSame(80, strlen($suffix."%' or w..."));
        $input = $this->input($this->native($suffix."%' or w..."));
        $result = (new SqlSerializedDiagnosticSuspicion())->evaluate($input);
        self::assertSame([], $result['issues']);
        $claims = $this->claims($result);
        self::assertSame('observed', $claims['error_disclosure']->state);
        self::assertSame('suspected', $claims['sql_injection']->state);
        self::assertSame([SqlSerializedDiagnosticSuspicion::RULE_ID], $claims['sql_injection']->ruleIds);
        self::assertSame($suffix, $claims['sql_injection']->spans[1]['excerpt']);
        self::assertStringContainsString('distinctive suffix', implode(' ', $claims['sql_injection']->reasons));
        self::assertStringContainsString('not reconstructed', implode(' ', $claims['sql_injection']->reasons));
        self::assertStringNotContainsString('WHERE', implode(' ', $claims['sql_injection']->reasons));
        self::assertStringContainsString('does not establish valid JavaScript execution', implode(' ', $claims['error_disclosure']->reasons));
        $this->assertRawSpans($input, $result);
    }

    #[DataProvider('nativeVariants')]
    public function testCanonicalNativeFamiliesAndLiteralStringEncodings(string $vendor, bool $wrapper, string $encoding): void
    {
        $message = $this->native(self::VALUE, $vendor, $wrapper);
        $input = $this->input($message, encoding: $encoding);
        $result = (new SqlSerializedDiagnosticSuspicion())->evaluate($input);
        self::assertSame(['error_disclosure', 'sql_injection'], array_keys($this->claims($result)));
        $this->assertRawSpans($input, $result);
        $span = $this->claims($result)['sql_injection']->spans[1]['excerpt'];
        if ($encoding === 'single') self::assertStringContainsString("\\'", $span);
        if ($encoding === 'hex') self::assertStringContainsString('\\u003C', $span);
        if ($encoding === 'ascii_hex') self::assertStringContainsString('\\x3c', $span);
    }

    public static function nativeVariants(): iterable
    {
        foreach (['MySQL','MariaDB'] as $vendor) foreach ([false,true] as $wrapper) foreach (['double','single','hex','ascii_hex'] as $encoding) {
            yield $vendor.' '.(int) $wrapper.' '.$encoding => [$vendor,$wrapper,$encoding];
        }
    }

    public function testUnicodeEscapesAndSurrogatePairKeepExactRawEvidence(): void
    {
        $value = "'distinctive_Ä😀_0123456789_abcdefghijklmnopqrstuvwxyz";
        $input = $this->input($this->native($value), value: $value, encoding: 'unicode');
        $result = (new SqlSerializedDiagnosticSuspicion())->evaluate($input);
        $span = $this->claims($result)['sql_injection']->spans[1]['excerpt'];
        self::assertStringContainsString('\\u00c4', $span);
        self::assertStringContainsString('\\ud83d\\ude00', $span);
        $this->assertRawSpans($input, $result);
    }

    #[DataProvider('scriptVariants')]
    public function testOnlyPlainJavascriptCarrierDeclarationsAndApprovedTailAreAllowed(string $open, string $end): void
    {
        $input = $this->input($this->native(self::VALUE));
        $raw = str_replace(['<script>', ');history.go(-1);</script>'], [$open, $end], $input->rawHtml);
        $result = (new SqlSerializedDiagnosticSuspicion())->evaluate($this->withRaw($input, $raw));
        self::assertSame(['error_disclosure','sql_injection'], array_keys($this->claims($result)));
    }

    public static function scriptVariants(): iterable
    {
        yield 'plain no navigation' => ['<script>', ');</script>'];
        yield 'typed' => ['<script type="text/javascript">', ');history.go(-1);</script>'];
        yield 'legacy language' => ["<SCRIPT language='JavaScript'>", ');window.history.go( -1 );</SCRIPT>'];
        yield 'both declarations' => ['<script language=javascript type=application/javascript>', ");\n</script>"];
    }

    #[DataProvider('inertCarriers')]
    public function testExamplesOtherScriptProgramsAndIncompleteFramesAreNotDiagnostics(string $before, string $after): void
    {
        $input = $this->input($this->native(self::VALUE));
        $raw = $before.$input->rawHtml.$after;
        self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($this->withRaw($input, $raw))['suggestions']);
    }

    public static function inertCarriers(): iterable
    {
        foreach (['pre','code','samp','template','noscript','textarea','title'] as $tag) yield $tag => ['<'.$tag.'>', '</'.$tag.'>'];
        yield 'comment' => ['<!-- ', ' -->'];
        yield 'documentation class' => ['<section class="documentation">', '</section>'];
        yield 'docs class' => ['<section class="docs"><div>', '</div></section>'];
        yield 'language source class' => ['<div class="language-js">', '</div>'];
        yield 'example label' => ['Example: ', ''];
        yield 'existing script string' => ['<script>const example="', '";</script>'];
    }

    #[DataProvider('badPrograms')]
    public function testOtherProgramsAndNonExecutableScriptKindsAreRejected(string $from, string $to): void
    {
        $input = $this->input($this->native(self::VALUE));
        $raw = str_replace($from, $to, $input->rawHtml);
        self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($this->withRaw($input, $raw))['suggestions']);
    }

    public static function badPrograms(): iterable
    {
        yield 'dead condition' => ['<script>alert(', '<script>if(false)alert('];
        yield 'nested function' => ['<script>alert(', '<script>function example(){alert('];
        yield 'assignment' => ['<script>alert(', '<script>const message=alert('];
        yield 'external script' => ['<script>', '<script src="/example.js">'];
        yield 'json data script' => ['<script>', '<script type="application/json">'];
        yield 'module script' => ['<script>', '<script type=module>'];
        yield 'duplicate type declaration' => ['<script>', '<script type=text/javascript type=text/javascript>'];
        yield 'unknown attribute convention' => ['<script>', '<script data-example=1>'];
        yield 'extra statement' => [');history.go(-1);</script>', ');doSomething();</script>'];
        yield 'missing native line' => [' at line 7', ''];
        yield 'conflicting native SQLSTATE' => ['You have an error', 'SQLSTATE[HY000]: General error: 20018 You have an error'];
        yield 'wrong vendor' => ['MariaDB server', 'OtherDB server'];
        yield 'concatenated message' => [' at line 7"', ' at line 7" + "extra"'];
        yield 'unterminated program' => [');history.go(-1);</script>', ');history.go(-1);'];
        yield 'unknown JS escape' => ['You have an error', 'You\\z have an error'];
        yield 'literal newline in JS string' => ['near ', "near \n"];
    }

    public function testMalformedUnicodeEscapesDoNotQualifyAString(): void
    {
        $message = $this->native(self::VALUE);
        foreach (['\\uD800', '\\uD800\\u0041', '\\uDC00', '\\xE9', '\\u0000'] as $escape) {
            $raw = $this->input($message)->rawHtml;
            $raw = str_replace('fixtureMARK12', $escape.'fixtureMARK12', $raw);
            self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($this->withRaw($this->input($message),$raw))['suggestions']);
        }
    }

    public function testJsTextDoesNotDecodeHtmlEntitiesOrDoubleDecodeEscapesForInputBinding(): void
    {
        $encoded = htmlspecialchars(self::VALUE, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $input = $this->input($this->native($encoded));
        self::assertSame(['error_disclosure'], array_keys($this->claims((new SqlSerializedDiagnosticSuspicion())->evaluate($input))));
        $input = $this->input($this->native(str_replace('<', '\\u003c', self::VALUE)));
        self::assertSame(['error_disclosure'], array_keys($this->claims((new SqlSerializedDiagnosticSuspicion())->evaluate($input))));
    }

    public function testSuppliedCompleteDiagnosticUnderAnyParameterPayloadOrFinalUrlIsNotServerEvidence(): void
    {
        $message = $this->native(self::VALUE);
        $input = $this->input($message);
        foreach ([
            ['url' => $input->url.'&other='.rawurlencode($message)],
            ['payload' => '<script>alert('.$message.')</script>'],
            ['finalUrl' => 'https://fixture.invalid/next?echo='.rawurlencode($message)],
            ['url' => $input->url.'&other='.rawurlencode($message).'&bad=%ff'],
        ] as $extra) {
            $variant = ObservationInput::fromArray([...$input->toArray(),...$extra]);
            self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($variant)['suggestions']);
        }
    }

    public function testSuppliedRawJsSerializedDiagnosticIsNotIndependentServerEvidence(): void
    {
        $message = $this->native(self::VALUE);
        foreach (['double','single','hex','ascii_hex','unicode'] as $encoding) {
            $input = $this->input($message,encoding:$encoding);
            $rawContent = substr($input->rawHtml,strpos($input->rawHtml,'alert(')+7,-strlen(');history.go(-1);</script>')-1);
            self::assertNotSame($message,$rawContent);
            foreach ([
                ['url'=>$input->url.'&echo='.rawurlencode($rawContent)],
                ['finalUrl'=>'https://fixture.invalid/echo?whole='.rawurlencode($rawContent)],
                ['payload'=>'prefix '.$rawContent.' suffix'],
            ] as $extra) {
                $variant = ObservationInput::fromArray([...$input->toArray(),...$extra]);
                self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($variant)['suggestions']);
            }
        }
    }

    #[DataProvider('ambiguousInputs')]
    public function testAmbiguousOrUnsupportedInputsRetainOnlyTheNativeErrorObservation(string $query): void
    {
        $input = $this->input($this->native(self::VALUE));
        $query = str_replace('{value}', rawurlencode(self::VALUE), $query);
        $input = ObservationInput::fromArray([...$input->toArray(), 'url'=>'https://fixture.invalid/search?'.$query]);
        self::assertSame(['error_disclosure'], array_keys($this->claims((new SqlSerializedDiagnosticSuspicion())->evaluate($input))));
    }

    public static function ambiguousInputs(): iterable
    {
        yield 'same key twice' => ['needle={value}&needle={value}'];
        yield 'different keys same value' => ['needle={value}&other={value}'];
        yield 'array append' => ['needle[]={value}'];
        yield 'indexed array' => ['needle[0]={value}'];
        yield 'php normalized root collision' => ['search_key={value}&search.key=other'];
        yield 'root and array collision' => ['needle={value}&needle[]=other'];
        yield 'leading space key alias' => ['needle={value}&+needle=other'];
        yield 'NUL suffix key alias' => ['needle={value}&needle%00tail=other'];
        yield 'malformed bracket alias' => ['search_key={value}&search[key=other'];
        yield 'no input' => ['other=something'];
    }

    public function testRetentionLimitsAreIndependentAndInteriorMutationNeverBinds(): void
    {
        $suffix = "'distinctive_0123456789_abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789'";
        self::assertNotNull($this->claims((new SqlSerializedDiagnosticSuspicion())->evaluate($this->input($this->native($suffix),value:'--------'.$suffix)))['sql_injection'] ?? null);
        foreach ([
            ['---------'.$suffix,$suffix],
            ['A'.$suffix,$suffix],
            [' '.$suffix,$suffix],
            [self::VALUE,substr(self::VALUE,8)],
            [self::VALUE,str_replace('fixtureMARK12','fixtureXMARK12',self::VALUE)],
            ["'short_marker_0123456789'","'short_marker_0123456789'"],
            [self::VALUE,self::VALUE.' arbitrary prose'],
        ] as [$value,$fragment]) {
            $result = (new SqlSerializedDiagnosticSuspicion())->evaluate($this->input($this->native($fragment),value:$value));
            self::assertSame(['error_disclosure'], array_keys($this->claims($result)));
        }
    }

    public function testTwoCarriersOrNativeDelimitersCannotSupplyOneUniqueSuspicion(): void
    {
        $input = $this->input($this->native(self::VALUE));
        $result = (new SqlSerializedDiagnosticSuspicion())->evaluate($this->withRaw($input,$input->rawHtml.$input->rawHtml));
        self::assertSame(['error_disclosure'], array_keys($this->claims($result)));
        self::assertCount(2,$this->claims($result)['error_disclosure']->spans);
        foreach ([" near 'second'", "' at line 2", $this->native('other')] as $extra) {
            self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($this->input($this->native(self::VALUE.$extra)))['suggestions']);
        }
    }

    public function testRawCarrierAndMessageBudgetsRemainBounded(): void
    {
        $input = $this->input($this->native(self::VALUE));
        $none = ObservationInput::fromArray([...$input->toArray(),'rawHtml'=>null,'domText'=>$input->rawHtml]);
        self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($none)['suggestions']);
        $json = ObservationInput::fromArray([...$input->toArray(),'contentType'=>'application/json']);
        self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($json)['suggestions']);
        $long = $this->input($this->native(str_repeat('x',5000)));
        self::assertSame([], (new SqlSerializedDiagnosticSuspicion())->evaluate($long)['suggestions']);
        $many = $this->withRaw($input,str_repeat($input->rawHtml,33));
        $result = (new SqlSerializedDiagnosticSuspicion())->evaluate($many);
        self::assertSame([], $result['suggestions']);
        self::assertSame('limited',$result['issues'][0]['status']);
    }

    private function native(string $fragment,string $vendor='MariaDB',bool $wrapper=false): string
    {
        return ($wrapper?'SQLSTATE[42000]: Syntax error or access violation: 1064 ':'')."You have an error in your SQL syntax; check the manual that corresponds to your {$vendor} server version for the right syntax to use near '{$fragment}' at line 7";
    }

    private function input(string $message,string $value=self::VALUE,string $encoding='double'): ObservationInput
    {
        $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
        if ($encoding==='single') $serialized="'".str_replace(['\\',"'"],["\\\\","\\'"],$message)."'";
        else $serialized=json_encode($message,($encoding==='unicode'?JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR:$flags)|($encoding==='hex'?JSON_HEX_TAG:0));
        if ($encoding==='ascii_hex') $serialized=str_replace('<','\\x3c',$serialized);
        $url='https://fixture.invalid/search?needle='.rawurlencode($value);
        return new ObservationInput($url,'<html><body><h1>Ä😀</h1><script>alert('.$serialized.');history.go(-1);</script>',null,'text/html',200,$url,null,null,false,null);
    }

    private function withRaw(ObservationInput $input,string $raw): ObservationInput
    {
        return ObservationInput::fromArray([...$input->toArray(),'rawHtml'=>$raw]);
    }

    private function claims(array $result): array
    {
        $claims=[];
        foreach ($result['suggestions'] as $claim) $claims[$claim->claim]=$claim;
        return $claims;
    }

    private function assertRawSpans(ObservationInput $input,array $result): void
    {
        foreach ($result['suggestions'] as $claim) {
            self::assertInstanceOf(ClaimSuggestion::class,$claim);
            foreach ($claim->spans as $span) self::assertSame($span['excerpt'],mb_substr($input->rawHtml,$span['start'],$span['length'],'UTF-8'));
        }
    }
}
