<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\ContextTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\TestCase;

final class ContextStructuredDeltaTest extends TestCase
{
    public function testPhpMethodListsAndRelativeLocationsAreCoherent(): void
    {
        $first = '<li><b>Controller-&gt;read</b> - <a onclick="show()">[line 42 - classes/Reader.php]</a><div class="psTrace"><pre>1: example</pre></div></li>';
        $second = '<li><b>Controller-&gt;run</b> - <a onclick="show()">[line 81 - classes/Controller.php]</a></li>';
        self::assertCount(1, $this->kind('<ul>'.$first.$second.'</ul>', 'stack_trace'));
        self::assertSame([], $this->kind('<ul>'.$first.'</ul><ul>'.$second.'</ul>', 'stack_trace'));
        self::assertSame([], $this->kind('<ul>'.$first.'<li>prose</li>'.$second.'</ul>', 'stack_trace'));
        self::assertSame([], $this->kind('<div class="tutorial"><ul>'.$first.$second.'</ul></div>', 'stack_trace'));
        self::assertSame([], $this->kind('<div data-example=\'<ul>'.$first.$second.'</ul>\'></div>', 'stack_trace'));
        $signal = $this->kind('<ul>'.$first.$second.'</ul>', 'stack_trace')[0];
        self::assertCount(2, $signal['spans']);
        self::assertStringNotContainsString('psTrace', $signal['spans'][0]['excerpt']);
    }

    public function testJrootAndSymfonyAndCommentFramesPreserveRealCalls(): void
    {
        $rows = '<tr><td>1</td><td>()</td><td>JROOT/src/Origin.php:11</td></tr><tr><td>2</td><td>App\Reader-&gt;read()</td><td>JROOT/src/Reader.php:42</td></tr><tr><td>3</td><td>App\Controller-&gt;run()</td><td>JROOT/index.php:71</td></tr>';
        $stack = $this->kind('<table><tr><td>Call stack</td></tr>'.$rows.'</table>', 'stack_trace');
        self::assertCount(1, $stack);
        self::assertCount(2, $stack[0]['spans']);
        self::assertStringNotContainsString('Origin', json_encode($stack));
        $frames = "  at App\\Reader-&gt;read()\n     (vendor/demo/Reader.php:42)\n  at App\\Controller-&gt;run()\n     (public/index.php:71)";
        self::assertCount(1, $this->kind('<pre class="stacktrace">'.$frames.'</pre>', 'stack_trace'));
        self::assertSame([], $this->kind('<pre class="stacktrace">'.str_replace("\n  at", "\nprose\n  at", $frames).'</pre>', 'stack_trace'));
        $comment = "<!-- Stack trace:\n 1. App\\Origin-&gt;() /srv/synthetic/Origin.php:10\n 2. App\\Reader-&gt;read() /srv/synthetic/Reader.php:42\n 3. App\\Controller-&gt;run() /srv/synthetic/index.php:71\n-->";
        self::assertCount(1, $this->kind($comment, 'stack_trace'));
        self::assertCount(2, $this->kind($comment, 'stack_trace')[0]['spans']);
    }

    public function testSilverStripeSourceRequiresActualWindowAndKeepsOtherError(): void
    {
        $window = '<div class="info"><h3>Source</h3><pre><span>40</span> if ($value) {' . "\n" . '<span>41</span>     $data = $reader-&gt;read();' . "\n" . '<span>42</span> <span class="error">    throw new DemoException($data);</span>' . "\n" . '<span>43</span> }</pre></div>';
        self::assertCount(1, $this->kind($window, 'source_code'));
        foreach (['<code>'.$window.'</code>', '<div class="documentation">'.$window.'</div>', '<div data-x=\''.$window.'\'></div>', str_replace('<span>41</span>', '<span>51</span>', $window)] as $body) self::assertSame([], $this->kind($body, 'source_code'), $body);
        self::assertCount(1, $this->kind('<section class="tutorial">'.$window.'</section>'.$window, 'source_code'));
        self::assertSame([], $this->runtime($window));
    }

    public function testTypoFramesOwnTheirSourceAndNoSourceForHeaderWithoutWindow(): void
    {
        $first = $this->phpFrame(4, 'read', 42);
        $second = $this->phpFrame(3, 'run', 52);
        $headerOnly = '<pre><span>5</span> App\Reader<span>::</span>missing<span>()</span></pre>';
        $body = $headerOnly.$first.$second;
        self::assertCount(1, $this->kind($body, 'stack_trace'));
        self::assertCount(2, $this->kind($body, 'source_code'));
        self::assertStringNotContainsString('missing', json_encode($this->kind($body, 'source_code')));
        self::assertSame([], $this->runtime($body));
    }

    public function testAspFontLocationStaysNeutralAndPhpHtmlObjectExposesConcretePath(): void
    {
        $asp = '<font face="Arial" size=2><p>Microsoft VBScript runtime </font> <font face="Arial" size=2>error \'800a000d\'</font><p><font face="Arial" size=2>Type mismatch: \'Clng\'</font><p><font face="Arial" size=2>/public/demo.asp</font><font face="Arial" size=2>, line 23</font>';
        self::assertCount(1, $this->runtime($asp));
        self::assertSame('classic_asp', $this->runtime($asp)[0]['attributes']['runtimeDetails']['family']);
        self::assertSame([], $this->kind($asp, 'source_location'));
        self::assertSame([], $this->runtime('<div class="example">'.$asp.'</div>'));
        self::assertSame([], $this->runtime('<a href="/public/demo.asp">Type mismatch, line23</a>'));
        $php = '{"data":{"error":{"type":1,"message":"Uncaught Error: Undefined constant in \/html\/synthetic\/app.php:38","file":"\/html\/synthetic\/app.php","line":38}}}';
        self::assertCount(1, $this->kind('<html>unfinished'.$php, 'internal_path'));
        self::assertSame([], $this->kind('{"file":"\/html\/synthetic\/app.php","line":38}', 'internal_path'));
    }

    public function testCatalogIsDescriptiveAndIndependentRealDiagnosticSurvives(): void
    {
        $catalog = '<script>$.lang = {locale: "de", language: {"SQL error":"Datenbankfehler","Connect failed":"Verbindung fehlgeschlagen"}, item:function(key){return $.lang.language[key];}};</script>';
        $contexts = array_values(array_filter($this->signals($catalog), fn($s) => ($s['attributes']['contextKind'] ?? '') === 'diagnostic_catalog'));
        self::assertCount(1, $contexts);
        self::assertSame('product_context', $contexts[0]['code']);
        self::assertSame(2, $contexts[0]['attributes']['entryCount']);
        self::assertLessThan(100, $contexts[0]['spans'][0]['length']);
        self::assertSame([], $this->kind($catalog, 'query'));
        self::assertCount(1, $this->kind($catalog.'<p>Database error Query: SELECT synthetic_column FROM synthetic_table</p>', 'query'));
        self::assertCount(1, array_filter($this->signals(str_replace('<script>', '<script>const ratio = (8) / 2;', $catalog)), fn($s) => ($s['attributes']['contextKind'] ?? '') === 'diagnostic_catalog'));
        foreach (['<script>if (true) /;$.lang = {locale:"en",language:{"one":"SQL syntax error","two":"example"}};/;</script>', '<script>if (true) {} /;$.lang = {locale:"en",language:{"one":"SQL syntax error","two":"example"}};/;</script>', '<script>const pattern = /;$.lang = {locale:"en",language:{"one":"SQL syntax error","two":"Call to undefined function sample()"}};/;</script>', '<code>'.$catalog.'</code>', '<script>const example=`'.substr($catalog,8,-9).'`;</script>', str_replace('"Connect failed":"Verbindung fehlgeschlagen"', '"nested":{"x":"y"}', $catalog)] as $body) {
            self::assertSame([], array_filter($this->signals($body), fn($s) => ($s['attributes']['contextKind'] ?? '') === 'diagnostic_catalog'));
        }
    }

    public function testExplicitUnknownWafAndAccessChallengeDoNotNeed455(): void
    {
        $block = '<p>The Website Application Firewall protecting your site blocked this request as unwanted.</p>';
        $signals = array_values(array_filter($this->signals($block), fn($s) => $s['code'] === 'coverage_limited'));
        self::assertCount(1, $signals);
        self::assertSame('waf_block', $signals[0]['attributes']['reason']);
        self::assertSame('unknown', $signals[0]['attributes']['vendor']);
        $gate = '<main><h1>Security check</h1><p>To protect the website from automated traffic, please confirm that you are a person.</p><form class="challenge" method="post"><input type="hidden" name="return_url" value="/demo"><div id="captcha"></div></form><script>hcaptcha.render(box, {sitekey:"synthetic"});</script></main>';
        $challenge = array_values(array_filter($this->signals($gate), fn($s) => $s['code'] === 'coverage_limited'));
        self::assertCount(1, $challenge);
        self::assertSame('waf_challenge', $challenge[0]['attributes']['reason']);
        self::assertSame([], array_filter($this->signals('<main><h1>Contact</h1><form class="challenge"><input name="return_url"><script>hcaptcha.render(box, {sitekey:"synthetic"});</script></form></main>'), fn($s) => $s['code'] === 'coverage_limited'));
    }

    public function testStructuredRubyAndLuaFramesExcludeNativeAndOriginOnlyRows(): void
    {
        $ruby = json_encode(['error'=>['status_code'=>500,'details'=>['PG::OffsetError: OFFSET must not be negative', "vendor/demo/adapter.rb:41:in `execute'", "vendor/demo/adapter.rb:42:in `block in query'"]]], JSON_THROW_ON_ERROR);
        $stacks = $this->kind($ruby, 'stack_trace');
        self::assertCount(1, $stacks);
        self::assertCount(2, $stacks[0]['spans']);
        self::assertStringNotContainsString('OFFSET', json_encode($stacks));
        self::assertSame([], $this->kind(str_replace("adapter.rb:42:in `block in query'", 'example text', $ruby), 'stack_trace'));
        $lua = json_encode(['traceback'=>"\nstack traceback:\n\t[C]: in function 'decode'\n\t./lib/demo.lua:41: in function 'read'\n\t./app.lua:82: in function 'handle'\n\t[C]: in function 'xpcall'\n\tcontent_by_lua(nginx.conf:1):2: in main chunk"], JSON_THROW_ON_ERROR);
        self::assertCount(1, $this->kind($lua, 'stack_trace'));
        self::assertSame([], $this->kind(json_encode(['traceback'=>"stack traceback:\n[C]: in function 'decode'\n[C]: in function 'xpcall'\ncontent_by_lua(nginx.conf:1):2: in main chunk"]), 'stack_trace'));
    }

    public function testJspWindowIsActualBackendSourceWithoutRuntimeCauseInference(): void
    {
        $source = "23: int userid = 0;\n24: String selected = request.getParameter(&quot;demo&quot;);\n25: int selectedNumber = Integer.parseInt(selected);";
        $body = '<p><b>Exception</b></p><pre>jakarta.servlet.ServletException: org.apache.jasper.JasperException: An exception occurred processing [WEB&#47;demo.jsp] at line [25]' . "\n\n" . $source . "\nStacktrace: caught\n" . '</pre>';
        self::assertCount(1, $this->kind($body, 'source_code'));
        self::assertStringNotContainsString('Stacktrace', $this->kind($body, 'source_code')[0]['spans'][0]['excerpt']);
        self::assertSame([], $this->runtime($body));
        self::assertSame([], $this->kind('<section class="tutorial">'.$body.'</section>', 'source_code'));
        self::assertSame([], $this->kind('<pre>'.$source.'</pre>', 'source_code'));
        self::assertSame([], $this->kind(str_replace('24: String', '44: String', $body), 'source_code'));
    }

    public function testPlainAspIsNeutralAndCatalogParseLimitsStayVisible(): void
    {
        $plain = "Microsoft VBScript runtime error '800a000d'<br>/public/demo.asp, line 23";
        self::assertCount(1, $this->runtime($plain));
        self::assertSame('source_location', $this->runtime($plain)[0]['attributes']['runtimeDetails']['contextKind']);
        self::assertSame([], $this->kind($plain, 'source_location'));
        self::assertSame([], $this->runtime('<code>'.$plain.'</code>'));
        $body = '<script>$.lang = {locale:"de", language:{"SQL error":"example",</script>';
        $i = new ObservationInput('https://fixture.test/', $body, null, 'text/html', 200, null, null, null, false, null);
        $e = (new ContextTriageDetector())->evaluate($i);
        self::assertSame('limited', $e->status);
        self::assertSame([], array_filter($e->signals, fn($s) => ($s['attributes']['contextKind']??'')==='diagnostic_catalog'));
    }

    public function testPowGateNeedsActualOperationsAndNormalCaptchaCannotBorrowItsHeading(): void
    {
        $script = 'var VERIFY_PATH="/__pow_gate_verify"; var DIFFICULTY=5; var worker=new Worker(URL.createObjectURL(blob)); worker.onmessage=function(e){var nonce=e.data.nonce; fetch(VERIFY_PATH,{method:"POST"});}; worker.postMessage({ token: TOKEN, difficulty: DIFFICULTY });';
        $gate = '<main><h1>Bot controle</h1><p>Please wait: proof-of-work before access.</p></main>';
        $positive = $gate.'<script>'.$script.'</script>';
        $signals = array_values(array_filter($this->signals($positive), fn($s) => $s['code']==='coverage_limited'));
        self::assertCount(1,$signals);
        self::assertSame('waf_challenge',$signals[0]['attributes']['reason']);
        self::assertSame([],array_filter($this->signals($gate.'<script>const example=`'.$script.'`;</script>'),fn($s) => $s['code']==='coverage_limited'));
        self::assertSame([],array_filter($this->signals('<div data-example=\''.$positive.'\'></div>'),fn($s) => $s['code']==='coverage_limited'));
    }

    private function phpFrame(int $call, string $method, int $line): string
    {
        return '<pre><span>'.$call.'</span> App\Reader<span>::</span>'.$method.'<span>()</span></pre><br><span>/srv/synthetic/Reader.php:</span><br><pre>'.($line - 1).': $before = 1;' . "\n" . '</pre><pre>'.$line.': $data = $reader-&gt;read();' . "\n" . '</pre><pre>'.($line + 1).': return $data;' . "\n" . '</pre><br>';
    }

    private function signals(string $body): array
    {
        $input = new ObservationInput('https://fixture.test/', $body, null, 'text/html', 200, null, null, null, false, null);
        $evaluation = (new ContextTriageDetector())->evaluate($input);
        self::assertSame([], $evaluation->diagnostics);
        foreach ($evaluation->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        return $evaluation->signals;
    }

    private function kind(string $body, string $kind): array
    {
        return array_values(array_filter($this->signals($body), fn($s) => $s['code'] === 'internal_information_exposed' && $s['attributes']['detailKind'] === $kind));
    }

    private function runtime(string $body): array
    {
        return array_values(array_filter($this->signals($body), fn($s) => $s['code'] === 'error_response' && isset($s['attributes']['runtimeDetails'])));
    }
}
