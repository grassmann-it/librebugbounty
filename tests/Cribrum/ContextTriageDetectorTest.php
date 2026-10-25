<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\ContextTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContextTriageDetectorTest extends TestCase
{
    #[DataProvider('disclosures')]
    public function testConcreteInternals(string $body, string $kind): void
    {
        $input = $this->input($body);
        $signals = (new ContextTriageDetector())->detect($input);
        $details = array_filter($signals, static fn (array $s): bool => $s['code'] === 'internal_information_exposed' && $s['attributes']['detailKind'] === $kind);
        self::assertNotEmpty($details, $body);
        foreach ($signals as $signal) {
            EvidenceSignal::validateAgainstInput($signal, $input);
            self::assertSame('shadow', $signal['mode']);
            self::assertArrayNotHasKey('claim', $signal);
        }
    }

    public static function disclosures(): iterable
    {
        yield 'PHP fatal' => ['Fatal error: Uncaught RuntimeException in <b>/var/www/app/index.php</b> on line <b>27</b>', 'internal_path'];
        yield 'PHP warning HTML' => ['<b>Warning</b>: Undefined array key in <b>/homepages/site/app.php</b> on line <b>27</b>', 'internal_path'];
        yield 'PHP hosting frame' => ['#0 \/customers\/site\/index.php(27): App->run()', 'stack_trace'];
        yield 'PHP homepages frame' => ['#0 \/homepages\/site\/index.php(27): App->run()', 'stack_trace'];
        yield 'WordPress memory object' => ['{"error":{"type":1,"message":"Allowed memory size of 134217728 bytes exhausted (tried to allocate 2097152 bytes)","file":"\/home\/site\/wp-includes\/class-wpdb.php","line":2324}}', 'internal_path'];
        yield 'WordPress parse object' => ['{"error":{"type":4,"message":"syntax error, unexpected identifier \"version\"","file":"\/var\/www\/wp-content\/themes\/demo\/loading.php","line":10}}', 'internal_path'];
        yield 'WordPress warning object' => ['{"error":{"type":2,"message":"Undefined array key \"demo\"","file":"\/homepages\/site\/wp-content\/plugins\/demo.php","line":42}}', 'internal_path'];
        yield 'PHP frame' => ["Stack trace:\n#0 /var/www/app/index.php(27): Controller->run()", 'stack_trace'];
        yield 'WordPress embedded JSON' => ['<html><body>{"code":"internal_server_error","message":"Fatal error: Uncaught Error in \\/var\\/www\\/wp-content\\/plugins\\/demo.php on line 42\\nStack trace:\\n#0 \\/var\\/www\\/index.php(11): Demo->run()"}', 'stack_trace'];
        yield 'Joomla XML' => ['<error><message>Unable to load renderer class</message><trace>#0 /srv/joomla/libraries/Renderer.php(35): Joomla->render()</trace></error>', 'stack_trace'];
        yield 'Joomla structured XML' => ['<trace><frame><file>/srv/joomla/view.php</file><line>34</line><function>render</function></frame></trace>', 'stack_trace'];
        yield 'Joomla HTML' => ['<h2>Call stack</h2><table><tr><td>1</td><td>render()</td><td>/var/www/joomla/Renderer.php:25</td></tr></table>', 'stack_trace'];
        yield 'Java stack' => ['java.lang.NumberFormatException: invalid integer<br> at org.example.Parser.parse(Parser.java:51)', 'stack_trace'];
        yield 'CFML stack' => ['at app.modules.reader.run(reader.cfc:123)', 'stack_trace'];
        yield 'German .NET' => ['bei Example.Controller.Run() in C:\\inetpub\\app\\Controller.cs:Zeile 42', 'stack_trace'];
        yield 'French .NET' => ['à Example.Controller.Run() dans C:\\inetpub\\app\\Controller.cs:ligne 42', 'stack_trace'];
        yield 'query' => ['Database error Query: SELECT secret_column FROM private_accounts', 'query'];
        yield 'HTML comment stack' => ['<!-- at app.service.Reader.read(Reader.java:22) -->', 'stack_trace'];
    }

    #[DataProvider('shells')]
    public function testShellsDoNotDiscloseOrBlock(string $body): void
    {
        $signals = (new ContextTriageDetector())->detect($this->input($body));
        self::assertNotContains('internal_information_exposed', array_column($signals, 'code'));
        self::assertNotContains('coverage_limited', array_column($signals, 'code'));
    }

    public static function shells(): iterable
    {
        yield ['{"code":"internal_server_error","message":"There has been a critical error. Visit https://wordpress.org/support/"}'];
        yield ['<h1>Unable to load renderer class</h1><p>Joomla</p>'];
        yield ['at org.example.Parser.parse(Unknown Source)'];
        yield ['<div class="cf-turnstile">Contact form</div> Cloudflare'];
        yield ['<h1>Stack-Trace</h1>java.lang.NumberFormatException: not an integer'];
        yield ['FormatException. Enable debugging to view details. /public/example.asp'];
        yield ['<a href="https://site.test/test.asp">Source file</a> Error 80040e14'];
        yield ['<footer>Powered by Cloudflare</footer><p>Welcome back!</p>'];
        yield ['<h1>Access denied</h1><p>Please sign in.</p><footer>Cloudflare</footer>'];
        yield ['AWS WAF and Akamai are supported products.'];
        yield ['Check Point Application Security is installed.'];
        yield ['Azure WAF supports JavaScript challenges.'];
        yield ['SecuPress installation instructions: User-Agents interdits.'];
        yield ['SecuPress documentation: 403 Forbidden means User-Agents interdits is configured'];
        yield ["<code>Check Point's <b>Application Security</b> has detected an attack and blocked it.</code>"];
        yield ['<code><h1>SecuPress</h1><h2>403 Forbidden</h2>User-Agents interdits</code>'];
        yield ['<samp>Azure WAF<script>window.challengeType = `jsc`; window.specialRoute = `appgwazwaf`;</script><script src="/appgwazwaf/jsc/assets/challenge.example.js"></script></samp>'];
        yield ['<h1>403 Forbidden</h1><footer>SecuPress</footer>'];
        yield ['Check Point Application Security documentation: attacks may be blocked.'];
        yield ['<title>Azure WAF</title><script>window.challengeType = `jsc`;</script>'];
        yield ['<script>window.challengeType = `jsc`; window.specialRoute = `appgwazwaf`;</script><script src="/appgwazwaf/jsc/assets/challenge.example.js"></script>'];
        yield ['{"error":{"type":1,"message":"Critical error","file":"index.php","line":42}}'];
        yield ['{"error":{"type":1,"message":"Critical error","file":"\/var\/www\/app.php"}}'];
        yield ['{"error":{"type":1,"message":"Critical error","file":"\/var\/www\/app.php","line":42'];
        yield ['{"error":{"type":1,"message":"Critical error"},"file":"\/var\/www\/app.php","line":42}'];
        yield ['{"error":{"type":1,"message":"Critical error","file":"https://fixture.test/public/app.php","line":42}}'];
        yield ['{"file":"\/var\/www\/app.php","line":42}'];
        yield ['<h1>403 Forbidden</h1>'];
        yield ['<p>SELECT secret_column FROM private_accounts</p>'];
        yield ['<h1>403 Forbidden</h1><footer>SafeLine</footer>'];
        yield ['<script src="/wp-content/plugins/wordfence/js/script.js"></script><h1>Welcome</h1>'];
        yield ['ModSecurity documentation: tune rules for your web application.'];
        yield ['NinjaFirewall is installed. Thank you for visiting.'];
        yield ['NinjaFirewall: your request cannot be processed. Please try later.'];
        yield ['NinjaFirewall documentation: requests may be blocked and logged.'];
        yield ['Your request cannot be processed.<br />For security reasons, it was blocked and logged.'];
        yield ['SafeLine, Wordfence, ModSecurity and NinjaFirewall can block malicious traffic.'];
    }

    #[DataProvider('blocks')]
    public function testBlockAndChallenge(string $body, string $reason, string $vendor): void
    {
        $signals = (new ContextTriageDetector())->detect($this->input($body), 'active');
        $blocks = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'coverage_limited'));
        self::assertCount(1, $blocks);
        self::assertSame($reason, $blocks[0]['attributes']['reason']);
        self::assertSame($vendor, $blocks[0]['attributes']['vendor']);
        self::assertTrue($blocks[0]['attributes']['wafBlock']);
        self::assertSame('active', $blocks[0]['mode']);
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $this->input($body));
    }

    public static function blocks(): iterable
    {
        yield ['Sorry, you have been blocked. Cloudflare Ray ID: abc123', 'waf_block', 'cloudflare'];
        yield ['Cloudflare Error 1020 Access denied', 'waf_block', 'cloudflare'];
        yield ['<title>Just a moment...</title><script src="/cdn-cgi/challenge-platform/run"></script>', 'waf_challenge', 'cloudflare'];
        yield ['Access Denied - Sucuri Website Firewall', 'waf_block', 'sucuri'];
        yield ['Request unsuccessful. Incapsula incident ID 123', 'waf_block', 'imperva'];
        yield ['Access Denied Reference #12.abc.34 Akamai', 'waf_block', 'akamai'];
        yield ['AWS WAF: request blocked', 'waf_block', 'aws_waf'];
        yield ["Check Point's <b>Application Security</b> has detected an attack and blocked it.", 'waf_block', 'checkpoint'];
        yield ['<title>Azure WAF</title><script>window.challengeType = `jsc`; window.specialRoute = `appgwazwaf`;</script><script src="/appgwazwaf/jsc/assets/challenge.example.js"></script>', 'waf_challenge', 'azure_waf'];
        yield ['{"code":"wp_die","message":"<h1>SecuPress<\/h1><h2>403 Forbidden<\/h2><p>Raison: User-Agents interdits<\/p>"}', 'waf_block', 'secupress'];
        yield ['Request blocked by the web application firewall', 'waf_block', 'unknown'];
        yield ['SafeLine: your request was blocked for security reasons.', 'waf_block', 'safeline'];
        yield ['Access denied. Protected by SafeLine.', 'waf_block', 'safeline'];
        yield ['Your access to this site has been limited by the site owner.<br>Generated by Wordfence.', 'waf_block', 'wordfence'];
        yield ['Wordfence has blocked your request.', 'waf_block', 'wordfence'];
        yield ['ModSecurity: Access denied with code 403.', 'waf_block', 'modsecurity'];
        yield ['Your request was rejected by Mod_Security.', 'waf_block', 'modsecurity'];
        yield ['NinjaFirewall has blocked your request.', 'waf_block', 'ninjafirewall'];
        yield ['<title>NinjaFirewall 403 Forbidden</title>Sorry <b>192.0.2.42</b>, your request cannot be processed.<br />For security reasons, it was blocked and logged.', 'waf_block', 'ninjafirewall'];
        yield ['Your request cannot be processed.<br />For security reasons, it was blocked and logged.<img alt="NinjaFirewall">', 'waf_block', 'ninjafirewall'];
        yield ['Access denied. NinjaFirewall security protection.', 'waf_block', 'ninjafirewall'];
        yield ["<h1>Request blocked</h1>\n<footer>Protected by SafeLine</footer>", 'waf_block', 'safeline'];
        yield ["ModSecurity\nAccess denied with code 403.", 'waf_block', 'modsecurity'];
        yield ["<h1>Access denied</h1>\n<footer>NinjaFirewall</footer>", 'waf_block', 'ninjafirewall'];
    }

    #[DataProvider('productPresence')]
    public function testProductPresenceHasNoSecurityOrBlockImplication(string $body, string $vendor): void
    {
        $input = $this->input($body);
        $signals = (new ContextTriageDetector())->detect($input);
        self::assertCount(1, $signals);
        self::assertSame('product_context', $signals[0]['code']);
        self::assertSame($vendor, $signals[0]['attributes']['vendor']);
        self::assertFalse($signals[0]['attributes']['blockEstablished']);
        EvidenceSignal::validateAgainstInput($signals[0], $input);
    }

    public static function productPresence(): iterable
    {
        foreach (['safeline' => 'SafeLine', 'wordfence' => 'Wordfence', 'modsecurity' => 'ModSecurity', 'ninjafirewall' => 'NinjaFirewall', 'checkpoint' => 'Check Point', 'azure_waf' => 'Azure WAF', 'secupress' => 'SecuPress', 'iis' => 'Microsoft-IIS/10.0', 'apache' => 'Apache/2.4.62', 'nginx' => 'nginx/1.24.0', 'varnish' => 'Varnish cache server', 'squid' => 'Squid/6.0', 'haproxy' => 'HAProxy', 'envoy' => 'envoy'] as $vendor => $name) yield $vendor => [$name, $vendor];
    }

    #[DataProvider('genericServers')]
    public function testGenericServerFailuresAreContextOnly(string $body, string $vendor): void
    {
        $input = $this->input($body);
        $signals = (new ContextTriageDetector())->detect($input);
        self::assertContains('error_response', array_column($signals, 'code'));
        self::assertNotContains('internal_information_exposed', array_column($signals, 'code'));
        self::assertNotContains('coverage_limited', array_column($signals, 'code'));
        $products = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'product_context'));
        self::assertSame($vendor, $products[0]['attributes']['vendor']);
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public static function genericServers(): iterable
    {
        yield ['<title>HTTP 500 - Internal Server Error</title><p>Internet Information Services</p>', 'iis'];
        yield ['<h1>404 Not Found</h1><address>Apache/2.4.62 Server at fixture.test Port 443</address>', 'apache'];
        yield ['<h1>502 Bad Gateway</h1><center>nginx/1.24.0</center>', 'nginx'];
        yield ['<h1>Error 503 Backend fetch failed</h1><p>Varnish cache server</p>', 'varnish'];
        yield ['<title>504 Gateway Timeout</title><p>Squid Proxy</p>', 'squid'];
        yield ['<h1>503 Service Unavailable</h1><footer>HAProxy</footer>', 'haproxy'];
        yield ['<h1>Bad Gateway</h1><footer>envoy</footer>', 'envoy'];
    }

    public function testNewWafFamiliesPreserveApplicationEvidence(): void
    {
        foreach (['SafeLine: your request was blocked.', 'Wordfence has blocked your request.', 'ModSecurity: Access denied.', 'NinjaFirewall has blocked your request.', "Check Point's <b>Application Security</b> has detected an attack and blocked it.", '<h1>SecuPress</h1><h2>403 Forbidden</h2>User-Agents interdits.'] as $block) {
            $input = $this->input($block."\nFatal error: Uncaught RuntimeException in /var/www/app.php on line 42");
            $signals = (new ContextTriageDetector())->detect($input);
            self::assertContains('coverage_limited', array_column($signals, 'code'));
            self::assertContains('internal_information_exposed', array_column($signals, 'code'));
        }
    }

    public function testBlockDoesNotSuppressIndependentApplicationEvidence(): void
    {
        $signals = (new ContextTriageDetector())->detect($this->input("Sorry, you have been blocked. Cloudflare\n#0 /var/www/app.php(27): Application->run()"));
        self::assertContains('coverage_limited', array_column($signals, 'code'));
        self::assertContains('internal_information_exposed', array_column($signals, 'code'));
    }

    public function testDomFallbackAndUnicodeSpans(): void
    {
        $input = new ObservationInput('https://fixture.test/', null, 'Äpfel: at app.Reader.run(Reader.java:22)', 'text/plain', 500, null, null, null, false, null);
        $signals = (new ContextTriageDetector())->detect($input);
        self::assertCount(1, $signals);
        self::assertSame('dom_text', $signals[0]['spans'][0]['representation']);
        self::assertSame(7, $signals[0]['spans'][0]['start']);
        EvidenceSignal::validateAgainstInput($signals[0], $input);
    }

    public function testLocalWafExamplesDoNotSuppressIndependentBlockOrApplication(): void
    {
        $block = "Check Point's <b>Application Security</b> has detected an attack and blocked it.";
        $body = '<code>' . $block . '</code><div>' . $block . '</div>'
            . "\nFatal error: Uncaught RuntimeException in /var/www/app.php on line 42";
        $input = $this->input($body);
        $signals = (new ContextTriageDetector())->detect($input);
        $blocks = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'coverage_limited'));
        self::assertCount(1, $blocks);
        self::assertGreaterThan(strpos($body, '</code>'), $blocks[0]['spans'][0]['start']);
        self::assertContains('internal_information_exposed', array_column($signals, 'code'));
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public function testRawDiagnosticsSurviveLuceeLessThanInScript(): void
    {
        $body = '<script>// bracket-notation required for IE<9' . "\n</script>"
            . '<p>Number conversion failed.</p> at lucee.runtime.Reader.run(Reader.java:22)';
        self::assertStringNotContainsString('Reader.java', strip_tags($body));
        $input = $this->input($body);
        $signals = (new ContextTriageDetector())->detect($input);
        self::assertContains('internal_information_exposed', array_column($signals, 'code'));
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public function testBoundedWordPressObjectInLongUnfinishedHtmlAtHttp200(): void
    {
        $tail = '{"code":"internal_server_error","data":{"status":500,"error":{"type":2,"message":"Undefined array key","file":"\/homepages\/demo\/wp-content\/plugins\/sample.php","line":27}}}';
        $body = 'Äpfel: ' . str_repeat('<div>page content</div>', 4000) . '<section id="content" ' . $tail;
        $input = new ObservationInput('https://fixture.test/', $body, null, 'application/json', 200, null, null, null, false, null);
        $signals = (new ContextTriageDetector())->detect($input);
        $details = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'internal_information_exposed'));
        self::assertCount(1, $details);
        self::assertSame('internal_path', $details[0]['attributes']['detailKind']);
        self::assertStringStartsWith('"error":{', $details[0]['spans'][0]['excerpt']);
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public function testRepeatedFramesStayBounded(): void
    {
        $signals = (new ContextTriageDetector())->detect($this->input(str_repeat("#0 /var/www/app.php(27): Application->run()\n", 5000)));
        self::assertLessThanOrEqual(12, count($signals));
    }

    #[DataProvider('newConcreteDetails')]
    public function testAdditionalConcreteDetailsKeepRawSpans(string $body, string $kind): void
    {
        $input = $this->input('Äpfel: '.$body);
        $signals = (new ContextTriageDetector())->detect($input);
        $details = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'internal_information_exposed'));
        self::assertNotEmpty($details);
        self::assertContains($kind, array_column(array_column($details, 'attributes'), 'detailKind'));
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        foreach ($details as $detail) {
            self::assertTrue($detail['attributes']['concreteInternals']);
            self::assertArrayNotHasKey('claim', $detail);
        }
    }

    public static function newConcreteDetails(): iterable
    {
        yield 'Symfony actual header' => ['<div class="trace-line"><div class="trace-line-header sf-toggle"><span class="block trace-file-path">in <a href="file:///srv/demo/vendor/Reader.php#L42">vendor/<strong>Reader.php</strong></a> (line 42)</span></div></div>', 'stack_trace'];
        yield 'visible Zope' => ['<p>Traceback (innermost last):</p><ul><li>Module ZPublisher.Publish, line 249, in publish</li><li>Module zope.event, line 31, in notify</li></ul>', 'stack_trace'];
        $frames = "  Module ZPublisher.Publish, line 249, in publish\n    response = publish(request)\n  Module zope.event, line 31, in notify";
        yield 'comment Zope' => ['<!-- Traceback (most recent call last):' . "\n" . $frames . ' -->', 'stack_trace'];
        yield 'hidden Zope' => ['<input type="hidden" name="trace" value="Traceback (most recent call last):' . "\n" . $frames . '">', 'stack_trace'];
        yield 'template pair' => ['<!-- start file:/opt/demo/modules/system/templates/page.html --><h1>Page</h1><!-- end file:/opt/demo/modules/system/templates/page.html -->', 'internal_path'];
        yield 'nested template pair' => ['<!-- start file:/opt/demo/templates/page.html --><!-- start file:/opt/demo/templates/title.html -->Title<!-- end file:/opt/demo/templates/title.html --><!-- end file:/opt/demo/templates/page.html -->', 'internal_path'];
        foreach (["'", '&#039;', '&apos;', '&quot;', '&#x27;'] as $quote) {
            yield 'include '.$quote => ['<h2>Failed opening required '.$quote.'/mnt/demo/private/bootstrap.php'.$quote.' (include_path='.$quote.'.:/opt/php/includes'.$quote.')</h2>', 'internal_path'];
        }
    }

    #[DataProvider('newContextCounterexamples')]
    public function testNewContextCounterexamples(string $body): void
    {
        $signals = (new ContextTriageDetector())->detect($this->input($body));
        self::assertNotContains('internal_information_exposed', array_column($signals, 'code'));
        self::assertNotContains('coverage_limited', array_column($signals, 'code'));
    }

    public static function newContextCounterexamples(): iterable
    {
        yield ['<div class="stack trace-line-header">{traceback}</div>'];
        yield ['<input type="hidden" name="traceback" value="{traceback}">'];
        yield ['Module ZPublisher.Publish, line 249, in publish'];
        yield ["Module ZPublisher.Publish, line 249, in publish</div><div>\n  Module zope.event, line 31, in notify"];
        yield ['<div class="trace-line-header"><span class="trace-file-path">in <a href="file:///srv/demo/Reader.php#L42">Reader.php</a> (line 41)</span></div>'];
        yield ['<div class="trace-line-header"></div><div><span class="trace-file-path">in <a href="file:///srv/demo/Reader.php#L42">Reader.php</a> (line 42)</span></div>'];
        yield ['<a href="file:///srv/demo/Reader.php#L42">Source asset</a>'];
        yield ['<!-- start file:/opt/demo/templates/a.html -->a<!-- end file:/opt/demo/templates/b.html -->'];
        yield ['<!-- start file:/opt/demo/templates/a.html -->'];
        yield ['<!-- start file:/assets/templates/a.html -->a<!-- end file:/assets/templates/a.html -->'];
        yield ['<!-- start file:/opt/demo/templates/a.html --><!-- start file:/opt/demo/templates/b.html --><!-- end file:/opt/demo/templates/a.html --><!-- end file:/opt/demo/templates/b.html -->'];
        yield ['Failed opening required &#039;bootstrap.php&#039; (include_path=&#039;.:/opt/php&#039;)'];
        yield ['Failed opening required &#039;/srv/demo/bootstrap.php (include_path=&#039;.:/opt/php&#039;)'];
        yield ['Failed opening required &#039;https://fixture.test/bootstrap.php&#039; (include_path=&#039;.&#039;)'];
        yield ['<code>Failed opening required \'/srv/demo/bootstrap.php\' (include_path=\'.\')</code>'];
        yield ["<samp>Module ZPublisher.Publish, line 249, in publish\n  Module zope.event, line 31, in notify</samp>"];
        yield ['<code><!-- start file:/opt/demo/templates/a.html -->a<!-- end file:/opt/demo/templates/a.html --></code>'];
        yield ['Neos exception logged at Data/Logs/Exceptions/20261023.txt'];
        yield ['<script>window.__NUXT__={public:{apiUrl:"https://api.fixture.test",release:"v3",clientId:"browser"}}; const WP_DEBUG=true;</script>'];
    }

    public function testNewExamplesLeaveIndependentDetailsEligible(): void
    {
        $body = '<code>Failed opening required \'/srv/demo/bootstrap.php\' (include_path=\'.\')</code>'
            . '<h2>Failed opening required &#039;/srv/demo/real.php&#039; (include_path=&#039;.&#039;)</h2>';
        $input = $this->input($body);
        $signals = (new ContextTriageDetector())->detect($input);
        $details = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'internal_information_exposed'));
        self::assertCount(1, $details);
        self::assertStringContainsString('real.php', $details[0]['spans'][0]['excerpt']);
        EvidenceSignal::validateAgainstInput($details[0], $input);
    }

    private static function safeLineInstaller(): string
    {
        return '<script>function install(url, err) { var tag = document.createElement("script"); tag.src = url; tag.onload = function() { SafeLineChallenge("synthetic-token", {host: "waf.chaitin.com", local: true}); }; tag.onerror = err; document.head.appendChild(tag); } document.addEventListener("DOMContentLoaded", () => { install("https://waf.chaitin.com/challenge/v2/challenge.js", function () { install("/.safeline/challenge/v2/challenge.js"); }); });</script>';
    }

    private static function goAwayVerification(): string
    {
        return '<head><link rel="stylesheet" href="/.well-known/.git.example/git/go-away/cmd/go-away/challenge/resource-load/verify-challenge?token=synthetic"></head><body><main><div class="card"><h1>Checking your browser</h1><p id="status">Loading challenge <em>resource-load</em>...</p><details>Your browser is solving a short proof-of-work challenge before the page is loaded.</details></div></main></body>';
    }

    #[DataProvider('additionalWafCases')]
    public function testAdditionalWafCases(string $body, string $reason, string $vendor, int $status): void
    {
        $input = new ObservationInput('https://fixture.test/', 'Äpfel: '.$body, null, 'text/html', $status, null, null, null, false, null);
        $signals = (new ContextTriageDetector())->detect($input, 'active');
        $coverage = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'coverage_limited'));
        self::assertCount(1, $coverage);
        self::assertSame($reason, $coverage[0]['attributes']['reason']);
        self::assertSame($vendor, $coverage[0]['attributes']['vendor']);
        self::assertArrayNotHasKey('relevantRequestActuallyBlocked', $coverage[0]['attributes']);
        self::assertArrayNotHasKey('claim', $coverage[0]);
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public static function additionalWafCases(): iterable
    {
        yield 'go-away 418' => [self::goAwayVerification(), 'waf_challenge', 'go_away', 418];
        yield 'go-away 200' => [self::goAwayVerification(), 'waf_challenge', 'go_away', 200];
        yield 'SafeLine active installer' => [self::safeLineInstaller(), 'waf_challenge', 'safeline', 468];
        foreach (['type=""', "type=''", 'type', 'type="  "', 'type="text/javascript"', 'type=application/javascript', "type='text/ecmascript'", 'type="application/ecmascript"', 'TYPE="TEXT/JAVASCRIPT"', 'type="module"', 'data-type="text/plain" data-note="type=application/json"', 'data-note="type=text/plain" type="text/javascript"'] as $attributes) {
            yield 'SafeLine executable '.$attributes => [str_replace('<script>', '<script '.$attributes.'>', self::safeLineInstaller()), 'waf_challenge', 'safeline', 468];
        }
        yield 'SafeLine translations plus active installer' => ['<script>const translations={name:"SafeLine",deny:"Access denied",blocked:"Your request was blocked"};</script>'.self::safeLineInstaller(), 'waf_challenge', 'safeline', 403];
        yield 'Admin Tools current request' => ['<title>Fehler: 403 This request is blocked by Admin Tools.</title>', 'waf_block', 'admin_tools', 500];
        yield 'Admin Tools suspension' => ['We have detected suspicious activity from your IP address. Your access to this site is temporarily suspended. If you are the administrator, use /administrator/?admintools_rescue=you@example.test. This URL will only send you an email if your IP address is being blocked by Admin Tools.', 'waf_block', 'admin_tools', 403];
    }

    #[DataProvider('additionalWafCounterexamples')]
    public function testAdditionalWafCounterexamples(string $body): void
    {
        foreach ([200, 403, 418] as $status) {
            $input = new ObservationInput('https://fixture.test/', $body, null, 'text/html', $status, null, null, null, false, null);
            $signals = (new ContextTriageDetector())->detect($input);
            self::assertNotContains('coverage_limited', array_column($signals, 'code'));
        }
    }

    public static function additionalWafCounterexamples(): iterable
    {
        yield 'presence' => ['SafeLine, go-away and Admin Tools are installed.'];
        yield 'form CAPTCHA' => ['<form><h1>Contact us</h1><input name="captcha"><img src="/captcha.png"></form> Protected by Admin Tools'];
        yield 'conditional rescue' => ['You are a spammer, hacker or an otherwise bad person. This URL will only send you an email if the IP address from which you are accessing it is being blocked by Admin Tools.'];
        yield 'status only' => ['<h1>403 Forbidden</h1><footer>Admin Tools</footer>'];
        yield 'go-away asset' => ['<link rel="stylesheet" href="/.well-known/.git.example/git/go-away/cmd/go-away/challenge/resource-load/verify-challenge?token=synthetic"><h1>Welcome</h1>'];
        yield 'generic browser message' => ['<h1>Checking your browser</h1>go-away'];
        yield 'SafeLine translation dictionary' => ['<script>const translations={name:"SafeLine",deny:"Access denied",blocked:"Your request was blocked"};</script>'];
        yield 'SafeLine loader only' => ['<script src="/.safeline/challenge/v2/challenge.js"></script>'];
        yield 'SafeLine call only' => ['<script>SafeLineChallenge("synthetic-token");</script>'];
        yield 'disconnected SafeLine operations' => ['<script>function install(url, err) { var tag = document.createElement("script"); tag.src = url; tag.onload = function() { SafeLineChallenge("synthetic-token"); }; document.head.appendChild(tag); }</script><article>Unrelated application</article><script>install("/.safeline/challenge/v2/challenge.js");</script>'];
        yield 'SafeLine commented installer' => [str_replace('<script>', '<script>/*', str_replace('</script>', '*/</script>', self::safeLineInstaller()))];
        yield 'SafeLine quoted installer' => ['<script>const example = \''.substr(self::safeLineInstaller(), 8, -9).'\';</script>'];
        yield 'SafeLine HTML comment' => ['<!--'.self::safeLineInstaller().'-->'];
        yield 'SafeLine inert template' => ['<template>'.self::safeLineInstaller().'</template>'];
        foreach (['text/plain', 'application/json', 'application/ld+json', 'text/x-template', 'importmap', 'speculationrules'] as $type) {
            yield 'SafeLine inert script type '.$type => [str_replace('<script>', '<script type="'.$type.'">', self::safeLineInstaller())];
        }
        foreach (['textarea', 'noscript'] as $tag) {
            yield 'SafeLine inert '.$tag => ['<'.$tag.'>'.self::safeLineInstaller().'</'.$tag.'>'];
            yield 'SafeLine block in '.$tag => ['<'.$tag.'>SafeLine: your request was blocked.</'.$tag.'>'];
            yield 'Admin Tools inert '.$tag => ['<'.$tag.'>This request is blocked by Admin Tools.</'.$tag.'>'];
            yield 'go-away inert '.$tag => ['<'.$tag.'>'.self::goAwayVerification().'</'.$tag.'>'];
        }
        yield 'Admin Tools HTML comment' => ['<!--This request is blocked by Admin Tools.-->'];
        yield 'Admin Tools inert template' => ['<template>This request is blocked by Admin Tools.</template>'];
        yield 'SafeLine code example' => ['<code>'.self::safeLineInstaller().'</code>'];
        yield 'go-away sample' => ['<samp>'.self::goAwayVerification().'</samp>'];
        yield 'Admin Tools sample' => ['<code>This request is blocked by Admin Tools.</code>'];
        yield 'Admin Tools script dictionary' => ['<script>const text="This request is blocked by Admin Tools.";</script>'];
    }

    public function testChallengeAllowsIndependentActualDenyAndApplicationInternals(): void
    {
        $body = '<code>This request is blocked by Admin Tools.</code>'.self::safeLineInstaller()
            . '<h1>This request is blocked by Admin Tools.</h1>'
            . '\nFatal error: Uncaught RuntimeException in /srv/demo/app.php on line 42';
        $input = $this->input($body);
        $signals = (new ContextTriageDetector())->detect($input);
        $coverage = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'coverage_limited'));
        self::assertCount(2, $coverage);
        self::assertEqualsCanonicalizing(['waf_challenge', 'waf_block'], array_column(array_column($coverage, 'attributes'), 'reason'));
        self::assertContains('internal_information_exposed', array_column($signals, 'code'));
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public function testInertWafTextLeavesIndependentBlocksAndDisclosuresEligible(): void
    {
        foreach (['textarea', 'noscript'] as $tag) {
            $inert = '<'.$tag.'>'.self::safeLineInstaller().'This request is blocked by Admin Tools.</'.$tag.'>';
            $body = $inert.self::safeLineInstaller().'<h1>This request is blocked by Admin Tools.</h1>'
                . '<!-- #0 /srv/demo/comment.php(14): Demo->run() -->'
                . '<input type="hidden" value="#0 /srv/demo/hidden.php(21): Demo->run()">'
                . "\nFatal error: Uncaught RuntimeException in /srv/demo/app.php on line 42";
            $input = $this->input($body);
            $signals = (new ContextTriageDetector())->detect($input);
            $coverage = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'coverage_limited'));
            self::assertCount(2, $coverage);
            foreach ($coverage as $signal) self::assertGreaterThanOrEqual(strlen($inert), $signal['spans'][0]['start']);
            $details = array_filter($signals, static fn (array $s): bool => $s['code'] === 'internal_information_exposed');
            $excerpts = implode("\n", array_map(static fn (array $s): string => $s['spans'][0]['excerpt'], $details));
            foreach (['comment.php', 'hidden.php', 'app.php'] as $file) self::assertStringContainsString($file, $excerpts);
            foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        }
    }

    #[DataProvider('reconciledInternals')]
    public function testReconciledInternals(string $body, string $kind): void
    {
        $input = $this->input('Äpfel: '.$body);
        $signals = (new ContextTriageDetector())->detect($input, 'active');
        $details = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'internal_information_exposed' && $s['attributes']['detailKind'] === $kind));
        self::assertNotEmpty($details);
        foreach ($signals as $signal) {
            EvidenceSignal::validateAgainstInput($signal, $input);
            self::assertSame('active', $signal['mode']);
            self::assertNotContains($signal['code'], ['sql_input_structure_indication', 'lfi_suspected', 'rfi_suspected']);
        }
        foreach ($details as $detail) self::assertSame(['detailKind' => $kind, 'concreteInternals' => true, 'source' => 'response_body'], $detail['attributes']);
    }

    public static function reconciledInternals(): iterable
    {
        $java = "org.example.Parser.parse(Parser.java:51)\norg.example.Controller.run(Controller.java:22)";
        yield 'Java no at' => [$java, 'stack_trace'];
        yield 'Java hidden' => ['<div style="display:none">'.$java.'</div>', 'stack_trace'];
        yield 'Java unknown alongside concrete' => ["org.example.Parser.parse(Unknown Source)\norg.example.Controller.run(Controller.java:22)", 'stack_trace'];
        yield 'Java HTML lines' => [str_replace("\n", '<br />', $java), 'stack_trace'];
        yield 'dotnet no prefix offsets' => ["<code><pre>System.Web.HttpRequest.ValidateString(String value, String key) +119\nSystem.Web.UI.Page.ProcessRequest() +75</pre></code>", 'stack_trace'];
        yield 'dotnet German' => ["bei Microsoft.VisualBasic.Conversions.ParseDouble(String Value)\nbei System.Web.UI.Page.ProcessRequest()", 'stack_trace'];
        yield 'dotnet Czech comment' => ["<!-- v System.Web.HttpRequest.ValidateString(String value)\n   v System.Web.UI.Page.ProcessRequest() -->", 'stack_trace'];
        yield 'iPHP numbered HTML' => ["<pre>1. <b>iCMS::run()</b> in <b>iCMS://core/iCMS.class.php</b> on line <b>110</b><br />2. <b>iPHP::run()</b> in <b>iCMS://core/iCMS.class.php</b> on line <b>105</b></pre>", 'stack_trace'];
        yield 'Yii actual call header' => [self::yiiHeader(), 'stack_trace'];
        yield 'IIS detail' => ['<title>IIS 10.0 Detailed Error - 500.0</title><table><tr><th>Physical Path</th><td>&nbsp;&nbsp;D:\\Applications\\Synthetic.Web\\search</td></tr></table>', 'internal_path'];
        yield 'IIS actual marker after inert marker' => ['<article class="documentation"><h1>IIS Detailed Error tutorial</h1></article><title>IIS 10.0 Detailed Error - 500.0</title><table><tr><th>Physical Path</th><td>D:\\Applications\\Synthetic.Web\\search</td></tr></table>', 'internal_path'];
        foreach (['is', 'hp'] as $root) {
            yield 'bounded raw diagnostic '.$root => ['{"error":{"type":64,"message":"Cannot redeclare synthetic_function()","file":"\\/'.$root.'\\/synthetic\\/app.php","line":11}}', 'internal_path'];
            yield 'numbered PHP '.$root => ['#0 \\/'.$root.'\\/synthetic\\/app.php(42): Synthetic->run()', 'stack_trace'];
            yield 'PHP error '.$root => ['Fatal error: Uncaught RuntimeException in /'.$root.'/synthetic/app.php on line 42', 'internal_path'];
        }
    }

    #[DataProvider('reconciledCounterexamples')]
    public function testReconciledInternalsCounterexamples(string $body): void
    {
        $signals = (new ContextTriageDetector())->detect($this->input($body));
        self::assertNotContains('internal_information_exposed', array_column($signals, 'code'));
        self::assertNotContains('coverage_limited', array_column($signals, 'code'));
    }

    public static function reconciledCounterexamples(): iterable
    {
        yield 'single Java frame without at' => ['org.example.Parser.parse(Parser.java:51)'];
        yield 'unknown Java only' => ["org.example.Parser.parse(Unknown Source)\norg.example.Controller.run(Native Method)"];
        yield 'disconnected Java' => ['org.example.Parser.parse(Parser.java:51)<article>Documentation</article>org.example.Controller.run(Controller.java:22)'];
        yield 'server log reference' => ['See server log at /logs/error.log for org.example.Parser.parse'];
        yield 'single dotnet frame' => ['bei System.Web.UI.Page.ProcessRequest()'];
        yield 'dotnet names without actual frames' => ['System.Web.HttpRequest.ValidateString(String value) System.Web.UI.Page.ProcessRequest()'];
        yield 'disconnected dotnet' => ['bei System.Web.UI.Page.ProcessRequest()<div>Another example</div>bei System.Web.UI.Control.OnLoad(EventArgs e)'];
        yield 'development instructions' => ['<h1>Development Mode</h1><p>Swapping to Development environment will display more detailed information. Set ASPNETCORE_ENVIRONMENT to Development and restart the app.</p>'];
        yield 'appSecret parameter name' => ['Missing parameter appSecret. The request requires appSecret and clientId.'];
        yield 'stock customErrors comment' => ['<!-- <configuration><system.web><customErrors mode="Off"/></system.web></configuration> -->'];
        yield 'single virtual iPHP' => ['1. iCMS::run() in iCMS://core/iCMS.class.php on line 110'];
        yield 'out of order iPHP' => ['1. iCMS::run() in iCMS://core/iCMS.class.php on line 110<br>4. iPHP::run() in iCMS://core/iCMS.class.php on line 105'];
        yield 'IIS banner and URL' => ['IIS 10.0 Detailed Error<tr><th>Requested URL</th><td>https://synthetic.invalid/search</td></tr>'];
        yield 'Physical Path prose' => ['IIS Detailed Error: the Physical Path field contains a filesystem location.'];
        yield 'Physical Path without IIS details' => ['<tr><th>Physical Path</th><td>C:\\example\\public</td></tr>'];
        yield 'IIS public URI' => ['IIS Detailed Error<tr><th>Physical Path</th><td>https://synthetic.invalid/public</td></tr>'];
        yield 'IIS tutorial' => ['<code>IIS Detailed Error<tr><th>Physical Path</th><td>C:\\example\\private</td></tr></code>'];
        yield 'IIS nested documentation' => ['<article class="documentation"><h1>IIS Detailed Error tutorial</h1><table><tr><th>Physical Path</th><td>C:\\synthetic\\private</td></tr></table></article>'];
        yield 'IIS pre example' => ['<pre>IIS Detailed Error<table><tr><th>Physical Path</th><td>C:\\synthetic\\private</td></tr></table></pre>'];
        yield 'IIS marker borrowed from tutorial' => ['<article class="documentation"><h1>IIS Detailed Error tutorial</h1></article><table><tr><th>Physical Path</th><td>C:\\synthetic\\private</td></tr></table>'];
        yield 'is ordinary URL' => ['<a href="/is/synthetic/app.php">Application</a>'];
        yield 'hp ordinary path' => ['The public file is /hp/synthetic/app.php'];
        yield 'arbitrary PHP type' => ['{"error":{"type":256,"message":"Application message","file":"\\/is\\/synthetic\\/app.php","line":11}}'];
        yield 'type64 incomplete object' => ['{"error":{"type":64,"message":"Cannot redeclare synthetic_function()","file":"\\/hp\\/synthetic\\/app.php","line":11'];
        yield 'Yii no method' => [preg_replace('~<span class="call">[\\s\\S]*?</span>\\s*</div>~', '</div>', self::yiiHeader())];
        yield 'Yii no line' => [str_replace('<span class="line">42</span>', '', self::yiiHeader())];
        yield 'Yii no item number' => [str_replace('<span class="item-number">2.</span>', '', self::yiiHeader())];
        yield 'Yii public file' => [str_replace('/srv/synthetic/Reader.php', 'https://synthetic.invalid/Reader.php', self::yiiHeader())];
        yield 'Yii sibling cannot complete header' => [str_replace('<span class="call">', '</div><div><span class="call">', self::yiiHeader())];
        yield 'JET is not SQL suspicion' => ["Microsoft JET Database Engine error '80040e14': Characters found after end of SQL statement."];
    }

    #[DataProvider('inactiveIisMarkers')]
    public function testIisPhysicalPathRequiresItsOwnActiveMarker(string $marker): void
    {
        $unqualifiedRow = '<tr><th>Physical Path</th><td>C:\\synthetic\\unqualified</td></tr>';
        $prefix = 'Äpfel: '.$marker.'<table>'.$unqualifiedRow.'</table>';
        $signals = (new ContextTriageDetector())->detect($this->input($prefix));
        self::assertNotContains('internal_information_exposed', array_column($signals, 'code'));

        $row = '<tr><th>Physical Path</th><td>&nbsp;&nbsp;D:\\Applications\\Synthetic.Web\\search</td></tr>';
        foreach (['title', 'h1', 'h2', 'h3'] as $tag) {
            $body = $prefix.'<'.$tag.'>IIS 10.0 Detailed Error - 500.0</'.$tag.'><table>'.$row.'</table>';
            $input = $this->input($body);
            $signals = (new ContextTriageDetector())->detect($input);
            $details = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'internal_information_exposed'));
            self::assertCount(1, $details);
            self::assertSame(['detailKind' => 'internal_path', 'concreteInternals' => true, 'source' => 'response_body'], $details[0]['attributes']);
            self::assertSame([], $details[0]['relations']);
            self::assertSame($row, $details[0]['spans'][0]['excerpt']);
            self::assertSame(mb_strlen(substr($body, 0, strpos($body, $row)), 'UTF-8'), $details[0]['spans'][0]['start']);
            self::assertSame(mb_strlen($row, 'UTF-8'), $details[0]['spans'][0]['length']);
            foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        }
    }

    public static function inactiveIisMarkers(): iterable
    {
        yield 'documentation heading' => ['<article class="documentation"><h1>IIS Detailed Error tutorial</h1></article>'];
        yield 'marker attribute' => ['<div title="IIS Detailed Error"></div>'];
        yield 'heading markup in attribute' => ['<div data-example="<h1>IIS Detailed Error</h1>"></div>'];
        yield 'heading attribute alone' => ['<h1 title="IIS Detailed Error">Documentation</h1>'];
        yield 'plain mention' => ['IIS Detailed Error tutorial'];
        yield 'incomplete heading' => ['<h1>IIS Detailed Error</h2>'];
        yield 'comment' => ['<!-- <title>IIS Detailed Error</title> -->'];
        yield 'script quoted markup' => ['<script>const example="<title>IIS Detailed Error</title>"; const fragment=\'<div title="\';</script>'];
        foreach (['code', 'samp', 'pre', 'example', 'template', 'textarea', 'noscript', 'style'] as $tag) {
            yield 'inert '.$tag => ['<'.$tag.'><h1>IIS Detailed Error</h1></'.$tag.'>'];
        }
    }

    public function testSourceCodeKeepsExactPrivateSpanAndFixedAttributes(): void
    {
        foreach ([self::syntheticSource(), str_replace('<?php', '<?', self::syntheticSource())] as $source) {
            $body = "Äpfel: <html><body>\n".$source.'</body></html>';
            $input = $this->input($body);
            $signals = (new ContextTriageDetector())->detect($input, 'active');
            $sourceSignals = array_values(array_filter($signals, static fn (array $s): bool => $s['code'] === 'internal_information_exposed' && $s['attributes']['detailKind'] === 'source_code'));
            self::assertCount(1, $sourceSignals);
            self::assertSame(rtrim($source), $sourceSignals[0]['spans'][0]['excerpt']);
            self::assertSame(['detailKind' => 'source_code', 'concreteInternals' => true, 'source' => 'response_body'], $sourceSignals[0]['attributes']);
            self::assertSame([], $sourceSignals[0]['relations']);
            foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
            self::assertSame(['internal_information_exposed'], array_column($signals, 'code'));
        }
    }

    #[DataProvider('sourceCounterexamples')]
    public function testSourceCounterexamples(string $body): void
    {
        $signals = (new ContextTriageDetector())->detect($this->input($body));
        self::assertNotContains('internal_information_exposed', array_column($signals, 'code'));
    }

    public static function sourceCounterexamples(): iterable
    {
        $source = self::syntheticSource();
        foreach (['code', 'samp', 'pre', 'textarea', 'script', 'template', 'example'] as $tag) yield 'source in '.$tag => ['<'.$tag.'>'.$source.'</'.$tag.'>'];
        foreach (['example', 'examples', 'sample', 'tutorial', 'documentation'] as $class) yield 'source example class '.$class => ['<div class="'.$class.'">'.$source.'</div>'];
        yield 'nested documentation' => ['<article class="documentation"><h2>Connection example</h2>'."\n".$source.'</article>'];
        yield 'nested example' => ['<div class="example"><div>'."\n".$source.'</div></div>'];
        yield 'unquoted example class' => ['<section class=example><p>Example</p>'."\n".$source.'</section>'];
        yield 'escaped source' => [htmlspecialchars($source, ENT_QUOTES | ENT_HTML5)];
        yield 'XML prolog' => ['<?xml version="1.0"?><document>mysql_connect mysql_select_db</document>'];
        yield 'isolated names' => ['mysql_connect mysql_select_db $host $user $password $database'];
        yield 'isolated calls' => ['<?php mysql_connect($host, $user, $password); mysql_select_db($database); ?>'];
        yield 'no function' => [str_replace(['function synthetic_connection() {', "\n}\n?>"], ['', "\n?>"], $source)];
        yield 'incomplete source' => [str_replace('?>', '', $source)];
        yield 'incomplete function' => [str_replace("\n}\n?>", "\n?>", $source)];
        yield 'oversize source' => [str_replace('<?php', '<?php /*'.str_repeat('x', 8192).'*/', $source)];
        yield 'HTML comment source' => ['<!--'.$source.'-->'];
        yield 'source in PHP string' => ["<?php \$example = '".str_replace("'", "\\'", $source)."'; ?>"];
        yield 'source in PHP comment' => ['<?php /*'.$source.'*/ ?>'];
        yield 'source in plain comment' => ['/*'.$source.'*/'];
        yield 'source in plain quote' => ["'".$source."'"];
        yield 'commented assignments and calls' => [str_replace(['function synthetic_connection() {', "\n}\n?>"], ['function synthetic_connection() { /*', "*/\n}\n?>"], $source)];
        yield 'split functions' => [str_replace('    if (!$connection', "}\nfunction another_function() {\n    if (!\$connection", $source)];
        yield 'assignment overwritten' => [str_replace('    if (!$connection', "    \$host = getenv('SYNTHETIC_HOST');\n    if (!\$connection", $source)];
        yield 'database overwritten' => [str_replace('    if (!mysql_select_db', "    \$database = getenv('SYNTHETIC_DATABASE');\n    if (!mysql_select_db", $source)];
        yield 'unrelated literal names' => [str_replace('mysql_connect($host, $user, $password)', 'mysql_connect($other_host, $other_user, $other_password)', $source)];
        yield 'method names are not calls' => [str_replace('mysql_connect(', '$client->mysql_connect(', $source)];
        yield 'public JavaScript config' => ['<script>const config={appSecret:"parameter-name",apiUrl:"https://api.synthetic.invalid"};</script>'];
    }

    public function testNewContextLimitsAndIndependentSource(): void
    {
        $source = self::syntheticSource();
        $body = '<code>'.$source.'</code>'."\n".$source;
        $signals = (new ContextTriageDetector())->detect($this->input($body));
        self::assertCount(1, $signals);
        self::assertGreaterThan(strpos($body, '</code>'), $signals[0]['spans'][0]['start']);
        $body = '<article class="documentation"><div>'.$source.'</div></article>'."\n".$source;
        $signals = (new ContextTriageDetector())->detect($this->input($body));
        self::assertCount(1, $signals);
        self::assertGreaterThan(strpos($body, '</article>'), $signals[0]['spans'][0]['start']);
        foreach ([str_repeat(self::yiiHeader(), 30), str_repeat("org.example.Parser.parse(Parser.java:51)\norg.example.Controller.run(Controller.java:22)\n", 100)] as $body) {
            $signals = (new ContextTriageDetector())->detect($this->input($body));
            self::assertNotEmpty($signals);
            self::assertLessThanOrEqual(12, count($signals));
        }
    }

    public function testCommentBeforeRawTextElementDoesNotTurnIisMarkerScanIntoEngineError(): void
    {
        foreach (['title', 'script', 'style', 'textarea'] as $tag) {
            $prefix = '<!-- synthetic comment --><'.$tag.'>inert content</'.$tag.'>';
            $evaluation = (new ContextTriageDetector())->evaluate($this->input($prefix));
            self::assertSame('completed', $evaluation->status, $tag);
            self::assertSame([], $evaluation->diagnostics, $tag);
            self::assertSame([], $evaluation->signals, $tag);
            $iis = '<h1>IIS 10.0 Detailed Error</h1><table><tr><th>Physical Path</th><td>C:\\inetpub\\synthetic\\index.php</td></tr></table>';
            $evaluation = (new ContextTriageDetector())->evaluate($this->input($prefix.$iis));
            self::assertSame('completed', $evaluation->status, $tag);
            self::assertSame([], $evaluation->diagnostics, $tag);
            $details = array_values(array_filter($evaluation->signals, static fn (array $signal): bool => $signal['code'] === 'internal_information_exposed'));
            self::assertCount(1, $details, $tag);
            self::assertSame('internal_path', $details[0]['attributes']['detailKind']);
        }
    }

    #[DataProvider('evaluationMatchBudgets')]
    public function testEvaluationMarksEveryTwelveResultSubpath(string $path, int $count): void
    {
        $unit = match ($path) {
            'signature' => "#0 /var/www/app/index.php(27): Controller->run()\n",
            'concreteDetails' => "Failed opening required '/var/www/secret.php' (include_path='.:/usr/lib/php')\n",
            'additionalInternals' => self::yiiHeader()."\n",
            'sourceBlocks' => self::syntheticSource()."\n",
            'templatePairs' => '<!-- start file:/opt/demo/templates/page.html -->body<!-- end file:/opt/demo/templates/page.html -->'."\n",
        };
        $input = $this->input(str_repeat($unit, $count));
        $evaluation = (new ContextTriageDetector())->evaluate($input);
        self::assertSame($count < 12 ? 'completed' : 'limited', $evaluation->status, $path);
        self::assertCount(min(12, $count), $evaluation->signals);
        self::assertSame([], $evaluation->diagnostics);
        foreach ($evaluation->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        self::assertSame($evaluation->signals, (new ContextTriageDetector())->detect($input));
    }

    public static function evaluationMatchBudgets(): iterable
    {
        foreach (['signature', 'concreteDetails', 'additionalInternals', 'sourceBlocks', 'templatePairs'] as $path) {
            foreach ([11, 12, 13] as $count) yield $path.' '.$count => [$path, $count];
        }
    }

    public function testTwelveMatchesAtRepresentationEndHaveNoUnsearchedRemainder(): void
    {
        $body = rtrim(str_repeat("#0 /var/www/app/index.php(27): Controller.run()\n", 12));
        $evaluation = (new ContextTriageDetector())->evaluate($this->input($body));
        self::assertCount(12, $evaluation->signals);
        self::assertSame('completed', $evaluation->status);
    }

    #[DataProvider('helperFailuresAfterQualifiedEvidence')]
    public function testQualifiedEvidenceSurvivesFailureInsideTheSameHelper(string $helper, string $body, string $retained, int $backtrackLimit): void
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
        try {
            $input = $this->input($body.' Cloudflare HTTP 500');
            $evaluation = (new ContextTriageDetector())->evaluate($input);
            $families = array_column($evaluation->familyOutcomes, null, 'family');
            self::assertSame('failed', $families['disclosure']['status']);
            self::assertSame('completed', $families['waf']['status']);
            self::assertSame('completed', $families['error_response']['status']);
            $stages = array_column($families['disclosure']['stages'], null, 'stage');
            self::assertSame('failed', $stages[$helper]['status']);
            self::assertContains($retained, array_column($evaluation->signals, 'phrase'));
            self::assertContains('product_context', array_column($evaluation->signals, 'code'));
            self::assertContains('error_response', array_column($evaluation->signals, 'code'));
            self::assertNotEmpty($evaluation->diagnostics);
            self::assertStringNotContainsString('synthetic_reader', json_encode($evaluation->familyOutcomes, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('not-a-real-password', json_encode($evaluation->diagnostics, JSON_THROW_ON_ERROR));
            foreach ($evaluation->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }

    public static function helperFailuresAfterQualifiedEvidence(): iterable
    {
        $module = "Module example.app, line 1, in foo\n  Module example.next, line 2, in bar";
        yield 'concreteDetails keeps earlier pattern' => ['concreteDetails', $module."\nFailed opening required '/var/www/secret.php' (include_path='.:/usr/lib/php')".str_repeat(' ', 500).'x', $module, 30];
        $java = "org.example.Parser.parse(Parser.java:51)\norg.example.Controller.run(Controller.java:22)";
        yield 'additionalInternals keeps earlier pattern' => ['additionalInternals', $java."\n ".str_repeat('at System.Thing.call() ', 50).'x', $java, 50];
        $source = self::syntheticSource();
        yield 'sourceBlocks keeps earlier object' => ['sourceBlocks', $source.'<div '.str_repeat('x ', 500).'>'."\n".$source, rtrim($source), 500];
    }

    public function testFailedNegativeExampleGuardNeverQualifiesSourceCandidate(): void
    {
        $body = self::syntheticSource().'<div '.str_repeat('x ', 500).'>'."\n".self::syntheticSource();
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '500');
        try {
            $evaluation = (new ContextTriageDetector())->evaluate($this->input($body));
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
        self::assertCount(1, $evaluation->signals);
        self::assertSame(0, $evaluation->signals[0]['spans'][0]['start']);
        $families = array_column($evaluation->familyOutcomes, null, 'family');
        $stages = array_column($families['disclosure']['stages'], null, 'stage');
        $issue = $stages['sourceBlocks']['issues'][0];
        self::assertSame('regex_error', $issue['code']);
        self::assertSame('match', $issue['operation']);
        self::assertSame(mb_strrpos($body, '<?php'), $issue['candidate']['start']);
        self::assertSame(hash('sha256', $body), $issue['candidate']['representationSha256']);
        self::assertArrayNotHasKey('excerpt', $issue['candidate']);
    }

    public function testLocalResourceLimitsDoNotEraseIndependentWafEvidence(): void
    {
        $cases = [
            'source_window' => str_replace('<?php', '<?php /*'.str_repeat('x', 8192).'*/', self::syntheticSource()),
            'template_window' => '<!-- start file:/opt/demo/templates/page.html -->'.str_repeat('x', 16000).'<!-- end file:/opt/demo/templates/page.html -->',
            'template_stack' => str_repeat('<!-- start file:/opt/demo/templates/page.html -->', 33),
            'example_ancestor_depth' => str_repeat('<div>', 129)."\n".self::syntheticSource(),
        ];
        foreach ($cases as $stage => $body) {
            $evaluation = (new ContextTriageDetector())->evaluate($this->input($body.' Cloudflare'));
            $families = array_column($evaluation->familyOutcomes, null, 'family');
            self::assertSame('limited', $families['disclosure']['status'], $stage);
            self::assertSame('completed', $families['waf']['status'], $stage);
            $stages = array_column($families['disclosure']['stages'], null, 'stage');
            self::assertSame('limited', $stages[$stage]['status']);
            self::assertContains('product_context', array_column($evaluation->signals, 'code'));
            self::assertSame([], $evaluation->diagnostics);
        }
    }

    public function testUnsupportedSourceGrammarAndNormalExclusionsCompleteNormally(): void
    {
        foreach ([str_replace('?>', '', self::syntheticSource()), str_replace("\n}\n?>", "\n?>", self::syntheticSource()), '<pre>'.self::syntheticSource().'</pre>'] as $body) {
            $evaluation = (new ContextTriageDetector())->evaluate($this->input($body));
            self::assertSame([], $evaluation->signals);
            self::assertSame('completed', $evaluation->status);
        }
    }

    public function testInvalidChosenRepresentationIsUnavailableWithoutNewDomFallback(): void
    {
        $input = new ObservationInput('https://fixture.test/', "\xff", 'Cloudflare', 'text/html', 200, null, null, null, false, null);
        $evaluation = (new ContextTriageDetector())->evaluate($input);
        self::assertSame('unavailable', $evaluation->status);
        self::assertSame([], $evaluation->signals);
        self::assertSame([], $evaluation->diagnostics);
        self::assertSame(['unavailable', 'unavailable', 'unavailable'], array_column($evaluation->familyOutcomes, 'status'));
    }

    private static function syntheticSource(): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/cribrum/context-triage-1.4/synthetic-source.txt');
    }

    private static function yiiHeader(): string
    {
        return '<div class="element"><span class="item-number">2.</span><span class="text">in /srv/synthetic/Reader.php</span><span class="at">at line <span class="line">42</span></span><span class="call">&ndash; <a href="https://www.yiiframework.com/doc-2.0/yii-base-module.html">yii\\base\\Module::runAction</a>(<span class="string">\'synthetic\'</span>, [])</span></div>';
    }

    private function input(string $body): ObservationInput
    {
        return new ObservationInput('https://fixture.test/', $body, null, 'text/html', 500, null, null, null, false, null);
    }
}
