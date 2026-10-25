<?php

namespace App\Tests;

use App\Cribrum\CribrumClassifier;
use App\Service\CribrumProcessingService;
use App\Cribrum\ObservationInput;
use App\Cribrum\ReportPolicy;
use App\Dto\FindingReadFilter;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\FindingReadRepository;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\IntakeProfileResolver;
use App\Service\LegacyCohortService;
use App\Service\ReportProjectionService;
use App\Service\WorkerProofResolver;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/** Runs real registered candidate packs through persistence and current consumers. */
final class BehaviorEnginePipelineAcceptanceTest extends DatabaseTestCase
{
    private ReportProjectionService $projection;
    private CribrumObservationStore $observations;
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->entityManager->getConnection();
        if (!in_array('report_source_contract', $db->createSchemaManager()->listTableNames(), true)) {
            require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
            $migration = new \DoctrineMigrations\Version20261022000000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
        $this->observations = new CribrumObservationStore($this->entityManager, self::getContainer()->get(EvidenceStorageInterface::class));
        $this->projection = new ReportProjectionService($db, new ReportPolicy(), new IntakeProfileResolver(), new LegacyCohortService($db), new WorkerProofResolver($db), $this->observations);
    }


    public function testActiveEngineProcessesPureDisclosureWithFullCoverage(): void
    {
        $finding=$this->finding('engine-disclosure');
        $snapshot=$this->process($finding,'<p>Fatal error: broken in /var/www/private/site.php on line 42</p>');
        self::assertSame('eligible',$snapshot['gate']);
        self::assertSame('information_disclosure',$snapshot['lane']);
        self::assertSame('pure_information_disclosure',$snapshot['caseSummary']['kind']);
        self::assertSame('complete',$snapshot['coverage']['status']);
        self::assertSame(1,(new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(reportKind:'only_information_disclosure')));
    }

    public function testActiveEngineProcessesPureXssOnlyWithIndependentNativeWorkerProof(): void
    {
        $finding=$this->finding('engine-xss'); $this->proof($finding);
        $snapshot=$this->process($finding,'<p>Ordinary application response.</p>');
        self::assertSame('eligible',$snapshot['gate']);
        self::assertSame('pure_xss',$snapshot['caseSummary']['kind']);
        self::assertSame(['xss_confirmed'],array_column($snapshot['components'],'code'));
        self::assertSame('complete',$snapshot['coverage']['status']);
    }

    public function testActiveEngineSqlStructureRemainsSuspectedInMixedComposition(): void
    {
        $finding=$this->finding('engine-sql'); $this->proof($finding);
        $snapshot=$this->process($finding,"<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression 'id = OPENBUG'.</pre>");
        self::assertSame('eligible',$snapshot['gate']);
        self::assertSame('xss_with_sqli_suspected',$snapshot['lane']);
        self::assertSame('mixed',$snapshot['caseSummary']['kind']);
        $sql=array_values(array_filter($snapshot['components'],static fn($c)=>$c['code']==='sqli_suspected'));
        self::assertCount(1,$sql); self::assertSame('suspected',$sql[0]['certainty']);
        self::assertSame(0,(new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(reportKind:'pure_xss')));
    }

    public function testDefaultShadowEngineCannotCreateActiveDisclosureFacts(): void
    {
        $finding=$this->finding('engine-shadow'); $this->proof($finding);
        $snapshot=$this->process($finding,'<p>Fatal error: broken in /var/www/private/site.php on line 42</p>','shadow');
        self::assertSame('xss',$snapshot['lane']);
        self::assertNotContains('internal_information_exposed',$snapshot['tags']);
        self::assertNotSame('pure',$snapshot['caseSummary']['purity']);
    }

    public function testBoundTypeErrorKeepsObservationWithoutPromotingSqlInjection(): void
    {
        $finding=$this->finding('engine-type'); $this->proof($finding);
        $snapshot=$this->process($finding,"<pre>ERROR: invalid input syntax for type integer: OPENBUG</pre>");
        self::assertSame('eligible',$snapshot['gate']);
        self::assertSame('xss_with_sql_error',$snapshot['lane']);
        self::assertSame('mixed',$snapshot['caseSummary']['kind']);
        self::assertNotContains('sqli_suspected',array_column($snapshot['components'],'code'));
        self::assertFalse($snapshot['urgencyAllowed']);
    }

    public function testReflectionCannotOpenExecutionGate(): void
    {
        $finding=$this->finding('engine-reflection');
        $snapshot=$this->process($finding,'<p>Value: OPENBUG</p>');
        self::assertContains('input_reflected',$snapshot['tags']);
        self::assertNotContains('xss_confirmed',array_column($snapshot['components'],'code'));
        self::assertNotSame('eligible',$snapshot['gate']);
        self::assertNotSame('pure_xss',$snapshot['caseSummary']['kind']);
    }

    public function testBrokenLinkTailKeepsSqlDiagnosisWithoutInjectionOrUrgencyInEitherConsumer(): void
    {
        $finding = $this->finding('engine-link-tail', ['inc' => "'><test alt=\"\">-marker", 'typ' => 'Nav2', 'cat' => '140']);
        $this->proof($finding);
        $expression = "Active = 1 AND Link = ''><test alt=[]>-marker&typ=Nav2&cat=140'";
        $snapshot = $this->process($finding, '<p>Microsoft OLE DB Provider for ODBC Drivers</p><p>Syntax error (missing operator) in query expression &#039;'.htmlspecialchars($expression, ENT_QUOTES | ENT_HTML5, 'UTF-8').'&#039;.</p>');
        $this->assertDiagnosticOnlyXss($finding, $snapshot);
    }

    public function testYiiExecutedStatementProducesOneCombinedSuspectedCase(): void
    {
        $payload = "'><test>OPENBUG";
        $finding = $this->finding('engine-yii', ['category' => $payload]);
        $this->proof($finding);
        $raw = '<h1>CDbException</h1><p class="message">CDbCommand failed to execute the SQL statement: SQLSTATE[HY000]: General error: 1 near &quot;&gt;&quot;: syntax error. The SQL statement executed was: SELECT * FROM catalog WHERE category_id = '.htmlspecialchars($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
        $snapshot = $this->process($finding, $raw);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('xss_with_sqli_suspected', $snapshot['lane']);
        self::assertSame('mixed', $snapshot['caseSummary']['kind']);
        self::assertCount(1, array_filter($snapshot['components'], static fn ($c) => $c['code'] === 'xss_confirmed'));
        $sql = array_values(array_filter($snapshot['components'], static fn ($c) => $c['code'] === 'sqli_suspected'));
        self::assertCount(1, $sql);
        self::assertSame('suspected', $sql[0]['certainty']);
        $this->assertConsumers($finding, $snapshot, 'mixed', 'xss_containing');
        $python = $this->python($finding, 'compose');
        self::assertStringContainsString('Verdacht auf SQL-Injection', $python['paragraphs']);
        self::assertSame([], $python['safe']);
        self::assertNotEmpty($python['leaks']);
        self::assertSame($finding->getPocUrl(), $python['poc']);
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
    }

    public function testGermanConversionErrorCannotCreateSqlInjectionUrgency(): void
    {
        $finding = $this->finding('engine-german-type', ['ObjID' => 'OPENBUG']);
        $this->proof($finding);
        $snapshot = $this->process($finding, '<pre>Microsoft OLE DB Provider for SQL Server: Fehler beim Konvertieren des varchar-Werts &quot;OPENBUG&quot; in den int-Datentyp.</pre>');
        $this->assertDiagnosticOnlyXss($finding, $snapshot);
    }

    public function testPairedTemplateInternalsReachDisclosureFilterAndReportConsumer(): void
    {
        $finding = $this->finding('engine-template');
        $snapshot = $this->process($finding, '<!-- start file:/opt/private/app/templates/index.html --><main>Page</main><!-- end file:/opt/private/app/templates/index.html -->');
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('information_disclosure', $snapshot['lane']);
        self::assertSame('pure_information_disclosure', $snapshot['caseSummary']['kind']);
        $this->assertConsumers($finding, $snapshot, 'only_information_disclosure', 'disclosure_only');
        self::assertSame([], $this->python($finding, 'compose')['safe']);
    }

    public function testPrefixlessFixedIncludeTargetRetainsDisclosureWithoutInclusionClaim(): void
    {
        $finding = $this->finding('engine-include');
        $snapshot = $this->process($finding, '<h2>Failed opening required &#039;/var/www/private/template.php&#039; (include_path=&#039;.:/usr/share/php&#039;)</h2>');
        self::assertSame('eligible', $snapshot['gate']);
        self::assertContains('file_include_diagnostic', $snapshot['tags']);
        self::assertContains('internal_information_exposed', $snapshot['tags']);
        self::assertNotContains('lfi_suspected', array_column($snapshot['components'], 'code'));
        self::assertNotContains('rfi_suspected', array_column($snapshot['components'], 'code'));
        self::assertNotContains('xss_confirmed', array_column($snapshot['components'], 'code'));
        $python = $this->python($finding);
        self::assertArrayNotHasKey('error', $python, json_encode($python));
        self::assertSame($snapshot['components'], $python['components']);
    }

    public function testSafeLineChallengeDoesNotCreateAnUnprovedMailHold(): void
    {
        $finding = $this->finding('engine-challenge');
        $this->proof($finding);
        $raw = '<script>const translations = {denied: "Access denied by SafeLine"}; function install(url, err) { var tag = document.createElement("script"); tag.src = url; tag.onload = function() { SafeLineChallenge("fixture-id", {local: true}); }; tag.onerror = err; document.head.appendChild(tag); } document.addEventListener("DOMContentLoaded", () => { install("/.safeline/challenge/v2/challenge.js"); });</script>';
        $snapshot = $this->process($finding, $raw);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertContains('coverage_limited', $snapshot['tags']);
        self::assertNotContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        self::assertSame([], $snapshot['wafBlockFingerprints']);
        self::assertNotSame('pure', $snapshot['caseSummary']['purity']);
        self::assertArrayNotHasKey('error', $this->python($finding));
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
    }

    public function testNewAdminToolsBlockRequiresReviewAndSameReviewedBlockStaysAcknowledged(): void
    {
        $finding = $this->finding('engine-admintools');
        $this->proof($finding);
        self::assertSame('eligible', $this->process($finding, '<p>Ordinary response.</p>')['gate']);
        $raw = '<h1>This request is blocked by Admin Tools.</h1>';
        $blocked = $this->process($finding, $raw);
        self::assertSame('hold', $blocked['gate']);
        self::assertContains('new_waf_block_unreviewed', $blocked['reasonCodes']);
        self::assertContains('xss_confirmed', array_column($blocked['components'], 'code'));
        self::assertArrayHasKey('error', $this->python($finding));
        self::assertCount(1, $blocked['wafBlockFingerprints']);
        $ack = $this->projection->acknowledgeWafBlock($finding, $blocked['wafBlockFingerprints'][0], 'acceptance-reviewer');
        self::assertSame('eligible', $ack['gate']);
        $repeat = $this->process($finding, $raw);
        self::assertSame('eligible', $repeat['gate']);
        self::assertNotContains('new_waf_block_unreviewed', $repeat['reasonCodes']);
        self::assertContains('coverage_limited', $repeat['tags']);
        // This list contains pending review fingerprints, not all historical blocks.
        self::assertSame([], $repeat['wafBlockFingerprints']);
        self::assertArrayNotHasKey('error', $this->python($finding));
    }

    public function testPublicConfigurationLogPointerAndCaptchaDoNotInventLeaksOrBlocks(): void
    {
        $finding = $this->finding('engine-public');
        $this->proof($finding);
        $snapshot = $this->process($finding, '<script>window.__NUXT__={config:{public:{apiBase:"/api"}}};</script><p>See Data/Logs/Exceptions/fixture.txt</p><form>Enter CAPTCHA<input name="captcha"></form>');
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('pure_xss', $snapshot['caseSummary']['kind']);
        self::assertNotContains('internal_information_exposed', $snapshot['tags']);
        self::assertNotContains('coverage_limited', $snapshot['tags']);
        $this->assertConsumers($finding, $snapshot, 'pure_xss', 'pure_xss');
    }

    public function testBalancedAccessOperandReachesCombinedReportAsSuspicion(): void
    {
        $finding = $this->finding('engine-parenthesized');
        $this->proof($finding);
        $snapshot = $this->process($finding, "<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression '(CSTR(ID)) = OPENBUG'.</pre>");
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('xss_with_sqli_suspected', $snapshot['lane']);
        self::assertTrue($snapshot['urgencyAllowed']);
        $this->assertConsumers($finding, $snapshot, 'mixed', 'xss_containing');
        $python = $this->python($finding, 'compose');
        self::assertStringContainsString('Verdacht auf SQL-Injection', $python['paragraphs']);
        self::assertSame([], $python['safe']);
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
    }

    public function testJsonNearEchoRetainsDiagnosticAndHardTagWithoutSqlInjection(): void
    {
        $payload = "'<svg onload=OPENBUG>";
        $finding = $this->finding('engine-json-near', ['q' => $payload]);
        $this->proof($finding);
        $json = json_encode(['success' => false, 'data' => ['error' => ['type' => 1,
            'message' => "You have an error in your SQL syntax; near '".$payload."' at line 1",
            'file' => '/is/private/app.php', 'line' => 42]]], JSON_THROW_ON_ERROR);
        $snapshot = $this->process($finding, "<div><p>Unfinished page\n".$json);
        $this->assertDiagnosticOnlyXss($finding, $snapshot);
        self::assertContains('input_in_sql_error', $snapshot['tags']);
        self::assertContains('xss_payload_in_sql_error', $snapshot['tags']);
        self::assertNotContains('sql_input_structure_indication', $snapshot['tags']);
    }

    public function testNewJetDiagnosticCannotCreateInjectionUrgency(): void
    {
        $finding = $this->finding('engine-jet-end');
        $this->proof($finding);
        $snapshot = $this->process($finding, "<pre>Microsoft JET Database Engine error '80040e14'\nCharacters found after end of SQL statement.</pre>");
        $this->assertDiagnosticOnlyXss($finding, $snapshot);
    }

    public function testIncludeBadgeIsDiagnosticAndNotInclusionProof(): void
    {
        $finding = $this->finding('engine-include-badge');
        $snapshot = $this->process($finding, '<blockquote><span class="badge">0</span> Failed opening required &#039;/var/www/private/view.php&#039; (include_path=&#039;.:/usr/share/php&#039;)</blockquote>');
        self::assertSame('eligible', $snapshot['gate']);
        self::assertContains('file_include_diagnostic', $snapshot['tags']);
        self::assertContains('internal_information_exposed', $snapshot['tags']);
        foreach (['lfi_suspected', 'rfi_suspected', 'sqli_suspected', 'xss_confirmed'] as $code) {
            self::assertNotContains($code, array_column($snapshot['components'], 'code'));
        }
        self::assertFalse($snapshot['urgencyAllowed']);
        self::assertArrayNotHasKey('error', $this->python($finding));
    }

    public function testType64PathReachesPureDisclosureConsumer(): void
    {
        $finding = $this->finding('engine-php-redeclare');
        $raw = '<div>Page'.json_encode(['success' => false, 'data' => ['error' => ['type' => 64,
            'message' => 'Cannot redeclare fixture_function()', 'file' => '/hp/private/app.php', 'line' => 17]]], JSON_THROW_ON_ERROR);
        $snapshot = $this->process($finding, $raw);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('pure_information_disclosure', $snapshot['caseSummary']['kind']);
        self::assertFalse($snapshot['urgencyAllowed']);
        $this->assertConsumers($finding, $snapshot, 'only_information_disclosure', 'disclosure_only');
    }

    public static function sourceCanaryModes(): iterable
    {
        yield 'disclosure only' => [false];
        yield 'mixed with independent XSS' => [true];
    }

    public static function newFrameAndPathFormats(): iterable
    {
        yield 'Java without at' => ["org.example.Parser.parse(Parser.java:51)\norg.example.Controller.run(Controller.java:22)", 'stack_trace'];
        yield '.NET frame chain in pre' => ["<code><pre>System.Web.HttpRequest.ValidateString(String value, String key) +119\nSystem.Web.UI.Page.ProcessRequest() +75</pre></code>", 'stack_trace'];
        yield 'iPHP virtual path chain' => ['<pre>1. <b>iCMS::run()</b> in <b>iCMS://core/iCMS.class.php</b> on line <b>110</b><br />2. <b>iPHP::run()</b> in <b>iCMS://core/iCMS.class.php</b> on line <b>105</b></pre>', 'stack_trace'];
        yield 'Yii qualified header' => ['<div class="element"><span class="item-number">2.</span><span class="text">in /srv/synthetic/Reader.php</span><span class="at">at line <span class="line">42</span></span><span class="call">&ndash; <a>yii\\base\\Module::runAction</a>(<span class="string">\'synthetic\'</span>, [])</span></div>', 'stack_trace'];
        yield 'IIS detailed physical path' => ['<title>IIS 10.0 Detailed Error - 500.0</title><table><tr><th>Physical Path</th><td>D:\\Applications\\Synthetic.Web\\search</td></tr></table>', 'internal_path'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('newFrameAndPathFormats')]
    public function testConcreteFramesAndPathsReachDisclosureConsumer(string $raw, string $detailKind): void
    {
        $finding = $this->finding('engine-new-frames');
        $snapshot = $this->process($finding, $raw);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('pure_information_disclosure', $snapshot['caseSummary']['kind']);
        self::assertFalse($snapshot['urgencyAllowed']);
        $details = array_values(array_filter($this->projection->evidence($finding),
            static fn ($f) => $f['code'] === 'internal_information_exposed' && ($f['attributes']['detailKind'] ?? null) === $detailKind));
        self::assertNotEmpty($details);
        $this->assertConsumers($finding, $snapshot, 'only_information_disclosure', 'disclosure_only');
        self::assertSame([], $this->python($finding, 'compose')['safe']);
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sourceCanaryModes')]
    public function testSourceCanariesRemainInPrivateEvidenceNotRecipientComposition(bool $withXss): void
    {
        $finding = $this->finding('engine-source-'.($withXss ? 'mixed' : 'pure'));
        if ($withXss) $this->proof($finding);
        $raw = <<<'PHP_SOURCE'
<?php
function fixture_connection() {
    $host = 'R14_PRIVATE_HOST_61.fixture.invalid';
    $user = 'R14_PRIVATE_USER_72';
    $password = 'R14_PRIVATE_PASSWORD_83';
    $database = 'R14_PRIVATE_DATABASE_94';
    $connection = mysql_connect($host, $user, $password);
    mysql_select_db($database, $connection);
    return $connection;
}
?>
PHP_SOURCE;
        $snapshot = $this->process($finding, $raw);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame($withXss ? 'mixed' : 'pure_information_disclosure', $snapshot['caseSummary']['kind']);
        self::assertFalse($snapshot['urgencyAllowed']);
        foreach (['sqli_suspected', 'lfi_suspected', 'rfi_suspected'] as $code) {
            self::assertNotContains($code, array_column($snapshot['components'], 'code'));
        }
        $source = array_values(array_filter($this->projection->evidence($finding),
            static fn ($f) => $f['code'] === 'internal_information_exposed' && ($f['attributes']['detailKind'] ?? null) === 'source_code'));
        self::assertNotEmpty($source, 'Canary must reach authenticated persisted source evidence.');
        $privateEvidence = json_encode($source, JSON_THROW_ON_ERROR);
        $attributes = json_encode(array_column($source, 'attributes'), JSON_THROW_ON_ERROR);
        $this->assertConsumers($finding, $snapshot, $withXss ? 'mixed' : 'only_information_disclosure', $withXss ? 'xss_containing' : 'disclosure_only');
        $python = $this->python($finding, 'source_canary');
        self::assertArrayNotHasKey('error', $python, json_encode($python));
        self::assertSame([], $python['safe']);
        self::assertSame($finding->getPocUrl(), $python['poc']);
        self::assertStringContainsString($finding->getPocUrl(), $python['mail']);
        foreach (['R14_PRIVATE_HOST_61', 'R14_PRIVATE_USER_72', 'R14_PRIVATE_PASSWORD_83', 'R14_PRIVATE_DATABASE_94'] as $canary) {
            self::assertStringContainsString($canary, $privateEvidence);
            self::assertStringNotContainsString($canary, $attributes);
            self::assertStringNotContainsString($canary, json_encode($python['recipientContext'], JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($canary, $python['paragraphs']);
            self::assertStringNotContainsString($canary, $python['mail']);
        }
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
        if ($withXss) self::assertArrayHasKey('error', $this->python($finding, 'read', 'disclosure_only'));
    }

    public function testDevelopmentInstructionsDoNotInventDisclosureOrWafHold(): void
    {
        $finding = $this->finding('engine-development-instructions');
        $this->proof($finding);
        $snapshot = $this->process($finding, '<h1>An error occurred while processing your request.</h1><h2>Development Mode</h2><p>Set ASPNETCORE_ENVIRONMENT to Development to display details.</p><pre>&lt;customErrors mode="Off"/&gt;</pre><script src="https://www.google.com/recaptcha/api.js"></script>');
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('pure_xss', $snapshot['caseSummary']['kind']);
        self::assertNotContains('internal_information_exposed', $snapshot['tags']);
        self::assertNotContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        $this->assertConsumers($finding, $snapshot, 'pure_xss', 'pure_xss');
    }

    public static function historicalExecutionDetails(): iterable
    {
        yield 'old 1.0 has no invented execution proof' => ['cribrum-1.0.0', null, 'complete'];
        yield 'new 1.1 missing execution proof' => ['cribrum-1.1.0', null, 'partial'];
        yield 'new 1.1 malformed execution proof' => ['cribrum-1.1.0', ['schemaVersion' => 1, 'runs' => [['unrecognized' => 'kept']]], 'partial'];
        yield 'future proof retained without certification' => ['cribrum-1.1.0', ['schemaVersion' => 99, 'futurePrivateDetails' => ['nested' => ['keepAll' => true]]], 'partial'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('historicalExecutionDetails')]
    public function testHistoricalStoredClassificationRemainsByteIdenticalWhileEffectiveCoverageIsValidated(string $version, ?array $evaluation, string $status): void
    {
        $finding = $this->finding('engine-history');
        $this->proof($finding);
        $raw = '<p>Ordinary historical response.</p>';
        $classification = (new CribrumClassifier('/nonexistent-private-cribrum', dirname(__DIR__).'/resources/cribrum/candidates', 'active'))
            ->classify(ObservationInput::fromArray(['url' => $finding->getPocUrl(), 'rawHtml' => $raw, 'contentType' => 'text/html', 'httpStatus' => 500, 'coverage' => ['status' => 'complete']]))->toArray();
        $classification['classifierVersion'] = $classification['coverage']['classifierVersion'] = $version;
        if ($evaluation === null) unset($classification['coverage']['evaluation']);
        else $classification['coverage']['evaluation'] = $evaluation;
        // Store an original historical record without passing it through a new writer.
        $observation = $this->observations->store($finding, ['schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 500, 'finalUrl' => $finding->getPocUrl(),
            'rawHtml' => $raw, 'rawHtmlBytes' => strlen($raw), 'rawHtmlTruncated' => false, 'domText' => null,
            'domTextBytes' => null, 'domTextTruncated' => false, 'redirectChain' => [], 'headers' => []],
            requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
        $observation->setClassification($classification);
        $this->entityManager->flush();
        $db = $this->entityManager->getConnection();
        $stored = $db->fetchOne('SELECT classification FROM http_observation WHERE id=?', [$observation->getId()]);
        $hash = hash('sha256', $stored);
        $snapshot = $this->projection->refresh($finding, $observation);
        self::assertSame($status, $snapshot['coverage']['status']);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame($evaluation, $snapshot['coverage']['evaluation'] ?? null);
        self::assertSame($evaluation, \App\Cribrum\ClassificationResult::fromArray($classification)->coverage['evaluation'] ?? null);
        $refresh = $this->projection->refresh($finding);
        self::assertSame($status, $refresh['coverage']['status']);
        self::assertSame($stored, $db->fetchOne('SELECT classification FROM http_observation WHERE id=?', [$observation->getId()]));
        self::assertSame($hash, $this->observations->coverageReference($observation)['classificationSha256']);
        self::assertArrayNotHasKey('error', $this->python($finding));
        $strict = $this->python($finding, 'read', 'pure_xss');
        if ($status === 'complete') self::assertArrayNotHasKey('error', $strict);
        else self::assertArrayHasKey('error', $strict);
    }

    public function testSqlMatchLimitRetainsQualifiedSuspicionAndIndependentXssInBothConsumers(): void
    {
        $finding = $this->finding('engine-sql-limit');
        $this->proof($finding);
        $snapshot = $this->process($finding, str_repeat("<p>Syntax error in query expression 'id=OPENBUG'.</p>\n", 33));
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('xss_with_sqli_suspected', $snapshot['lane']);
        self::assertTrue($snapshot['urgencyAllowed']);
        self::assertSame('partial', $snapshot['coverage']['status']);
        self::assertNotContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        self::assertSame([], $snapshot['wafBlockFingerprints']);
        self::assertStringContainsString('match_budget', json_encode($snapshot['coverage']['evaluation'], JSON_THROW_ON_ERROR));
        $this->assertConsumers($finding, $snapshot, 'mixed', 'xss_containing');
        self::assertSame(0, (new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(reportKind: 'pure_xss')));
        $python = $this->python($finding, 'compose');
        self::assertSame([], $python['safe']);
        self::assertStringContainsString('Verdacht auf SQL-Injection', $python['paragraphs']);
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
    }

    public function testContextMatchLimitPreservesDisclosureAndPreventsStrictPurityInBothConsumers(): void
    {
        $finding = $this->finding('engine-context-limit');
        $raw = str_repeat("Failed opening required '/var/www/private/secret.php' (include_path='.:/usr/lib/php')\n", 13);
        $snapshot = $this->process($finding, $raw);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('information_disclosure', $snapshot['lane']);
        self::assertSame('partial', $snapshot['coverage']['status']);
        self::assertNotSame('pure', $snapshot['caseSummary']['purity']);
        self::assertNotContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        self::assertSame([], $snapshot['wafBlockFingerprints']);
        self::assertNotEmpty($this->projection->evidence($finding));
        $detail = json_encode($snapshot['coverage']['evaluation'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('match_budget', $detail);
        self::assertSame(0, (new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(reportKind: 'only_information_disclosure')));
        $python = $this->python($finding, 'compose');
        self::assertArrayNotHasKey('error', $python, json_encode($python));
        self::assertSame($snapshot['components'], $python['components']);
        self::assertSame([], $python['safe']);
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'disclosure_only'));
    }

    public function testLateRegexFailureRetainsIndependentDisclosureAndXssWithoutCreatingWafReview(): void
    {
        $finding = $this->finding('engine-late-regex');
        $this->proof($finding);
        $module = "Module example.app, line 1, in foo\n  Module example.next, line 2, in bar";
        $raw = $module."\nFailed opening required '/var/www/private/secret.php' (include_path='.:/usr/lib/php')".str_repeat(' ', 500).'x Cloudflare HTTP 500';
        $previous = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '30');
            $snapshot = $this->process($finding, $raw);
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('xss_with_disclosure', $snapshot['lane']);
        self::assertSame('partial', $snapshot['coverage']['status']);
        self::assertContains('xss_confirmed', array_column($snapshot['components'], 'code'));
        self::assertContains('information_disclosure', array_column($snapshot['components'], 'code'));
        self::assertNotContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        self::assertSame([], $snapshot['wafBlockFingerprints']);
        $facts = $this->projection->evidence($finding);
        self::assertNotEmpty(array_filter($facts, static fn ($f) => $f['code'] === 'internal_information_exposed' && ($f['attributes']['detailKind'] ?? null) === 'stack_trace'));
        self::assertStringContainsString('regex_error', json_encode($snapshot['coverage']['evaluation'], JSON_THROW_ON_ERROR));
        $this->assertConsumers($finding, $snapshot, 'mixed', 'xss_containing');
        $python = $this->python($finding, 'compose');
        self::assertSame([], $python['safe']);
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'disclosure_only'));
    }

    public function testRuleDeltaNextDiagnosticIsVisibleWithoutInventingDisclosureEligibility(): void
    {
        $finding = $this->finding('next-neutral');
        $poc = $finding->getPocUrl();
        $snapshot = $this->process($finding, '<script id="__NEXT_DATA__" type="application/json">{"props":{"pageProps":{"error":"Failed to create resultsState","statusCode":500}},"gssp":true}</script>');
        self::assertSame('no_mail', $snapshot['gate']);
        self::assertSame([], $snapshot['components']);
        self::assertContains('error_response', $snapshot['tags']);
        $facts = $this->projection->evidence($finding);
        self::assertNotEmpty(array_filter($facts, static fn ($f) => ($f['attributes']['runtimeDetails']['diagnosticKind'] ?? '') === 'results_state_creation'));
        $summary = \App\Service\CribrumFindingSummary::derive($snapshot, null, true, 'de', $facts);
        self::assertContains('error_response', array_column($summary['badges'], 'code'));
        self::assertSame(1, (new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(cribrumCodes: ['error_response'])));
        self::assertSame($poc, $finding->getPocUrl());
        self::assertSame($poc, $snapshot['poc']['effectiveUrl']);
    }

    public function testRuleDeltaNextDiagnosticReachesReaderAsContextWithIndependentXss(): void
    {
        $finding = $this->finding('next-with-proof');
        $this->proof($finding);
        $snapshot = $this->process($finding, '<script id="__NEXT_DATA__" type="application/json">{"props":{"pageProps":{"error":"Failed to create resultsState","statusCode":500}}}</script>');
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame(['xss_confirmed'], array_column($snapshot['components'], 'code'));
        self::assertNotContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        $python = $this->python($finding, 'compose');
        self::assertArrayNotHasKey('error', $python, json_encode($python));
        self::assertContains('error_response', $python['behaviorNotice']['tags']);
        self::assertSame($snapshot['components'], $python['components']);
        self::assertStringNotContainsString('SQL-Injection', $python['paragraphs']);
        self::assertSame([], $python['safe']);
        self::assertSame($finding->getPocUrl(), $python['poc']);
    }

    public function testRuleDeltaReturnedQueryWithTraceOnlyValueRemainsDisclosureWithoutSqli(): void
    {
        $finding = $this->finding('query-trace-value');
        $this->proof($finding);
        $body = '<strong>An exception occurred while executing \'SELECT id FROM synthetic_records WHERE title = ?\' with params ["different"]: SQLSTATE[HY000]: General error: 1267 Illegal mix of collations</strong><pre>#9 lookup(OPENBUG)</pre>';
        $snapshot = $this->process($finding, $body);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('xss_with_sql_error', $snapshot['lane']);
        self::assertContains('information_disclosure', array_column($snapshot['components'], 'code'));
        self::assertFalse($snapshot['urgencyAllowed']);
        self::assertNotContains('sqli_suspected', array_column($snapshot['components'], 'code'));
        $facts = $this->projection->evidence($finding);
        self::assertEmpty(array_filter($facts, static fn ($f) => in_array($f['code'], ['input_in_sql_error', 'sql_input_structure_indication'], true)));
        $query = array_values(array_filter($facts, static fn ($f) => ($f['attributes']['detailKind'] ?? '') === 'query'));
        self::assertCount(1, $query);
        self::assertSame(['error', 'query'], array_column($query[0]['spans'], 'role'));
        self::assertStringNotContainsString('OPENBUG', $query[0]['spans'][1]['excerpt']);
        self::assertSame(0, (new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(reportKind: 'pure_xss')));
        $this->assertConsumers($finding, $snapshot, 'mixed', 'xss_containing');
        $python = $this->python($finding, 'compose');
        self::assertStringNotContainsString('Verdacht auf SQL-Injection', $python['paragraphs']);
        self::assertSame([], $python['safe']);
    }

    public function testRuleDeltaCookieFramesKeepTheRuntimeDiagnosticSeparateFromDeliveredStack(): void
    {
        $finding = $this->finding('cookie-stack');
        $this->proof($finding);
        $body = '<p><b>Control character in cookie value or attribute.</b></p><div><h2>Stacktrace</h2><p>org.synthetic.http.CookieProcessor.validate(CookieProcessor.java:41)</p><p>org.synthetic.http.Response.addCookie(Response.java:82)</p></div>';
        $snapshot = $this->process($finding, $body);
        self::assertSame('xss_with_disclosure', $snapshot['lane']);
        $facts = $this->projection->evidence($finding);
        self::assertNotEmpty(array_filter($facts, static fn ($f) => ($f['attributes']['detailKind'] ?? '') === 'stack_trace'));
        self::assertNotEmpty(array_filter($facts, static fn ($f) => ($f['attributes']['runtimeDetails']['diagnosticKind'] ?? '') === 'cookie_validation'));
        self::assertNotContains('sqli_suspected', array_column($snapshot['components'], 'code'));
        $this->assertConsumers($finding, $snapshot, 'mixed', 'xss_containing');
    }

    public function testRuleDeltaPublicAspLocationRemainsVisibleWithoutDisclosureMail(): void
    {
        $finding = $this->finding('asp-neutral');
        $body = '<font face="Arial" size=2><p>Microsoft VBScript runtime </font> <font face="Arial" size=2>error \'800a000d\'</font><p><font face="Arial" size=2>Type mismatch: \'Clng\'</font><p><font face="Arial" size=2>/public/demo.asp</font><font face="Arial" size=2>, line 23</font>';
        $snapshot = $this->process($finding, $body);
        self::assertSame('no_mail', $snapshot['gate']);
        self::assertSame([], $snapshot['components']);
        $facts = $this->projection->evidence($finding);
        self::assertNotEmpty(array_filter($facts, static fn ($f) => ($f['attributes']['runtimeDetails']['family'] ?? '') === 'classic_asp'));
        self::assertEmpty(array_filter($facts, static fn ($f) => $f['code'] === 'internal_information_exposed'));
        $summary = \App\Service\CribrumFindingSummary::derive($snapshot, null, true, 'de', $facts);
        self::assertContains('error_response', array_column($summary['badges'], 'code'));
    }

    public function testRuleDeltaStaticCatalogContextCannotHideIndependentDisclosure(): void
    {
        $finding = $this->finding('catalog-with-disclosure');
        $catalog = '<script>$.lang = {locale: "de", language: {"SQL error":"Datenbankfehler","Connect failed":"Verbindung fehlgeschlagen"}, item:function(key){return $.lang.language[key];}};</script>';
        $snapshot = $this->process($finding, $catalog.'<p>Fatal error: broken in /var/www/private/synthetic.php on line 42</p>');
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('information_disclosure', $snapshot['lane']);
        self::assertContains('product_context', $snapshot['tags']);
        self::assertNotContains('sqli_suspected', array_column($snapshot['components'], 'code'));
        $facts = $this->projection->evidence($finding);
        self::assertNotEmpty(array_filter($facts, static fn ($f) => ($f['attributes']['contextKind'] ?? '') === 'diagnostic_catalog'));
        self::assertSame([], array_values(array_filter($facts, static fn ($f) => $f['code'] === 'sql_error_observed')));
        $python = $this->python($finding, 'compose');
        self::assertArrayNotHasKey('error', $python, json_encode($python));
        self::assertContains('product_context', $python['behaviorNotice']['tags']);
        self::assertSame($snapshot['components'], $python['components']);
        self::assertStringNotContainsString('SQL-Injection', $python['paragraphs']);
        self::assertSame([], $python['safe']);
        self::assertSame($finding->getPocUrl(), $python['poc']);
    }

    public function testRuleDeltaMalformedNuxtKeepsLaterDiagnosticAndPartialCoverageAcrossConsumers(): void
    {
        $finding = $this->finding('nuxt-partial-with-proof');
        $this->proof($finding);
        $fixtures = json_decode(file_get_contents(__DIR__.'/Cribrum/Fixtures/runtime-error-carriers.json'), true, 32, JSON_THROW_ON_ERROR);
        $valid = array_values(array_filter($fixtures, static fn ($f) => $f['id'] === 'nuxt_header'))[0]['body'];
        $body = '<script type="application/json" id="__NUXT_DATA__" data-ssr="true">{"bad":</script>'.$valid;
        $snapshot = $this->process($finding, $body);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('partial', $snapshot['coverage']['status']);
        self::assertSame(['xss_confirmed'], array_column($snapshot['components'], 'code'));
        self::assertNotContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        $facts = $this->projection->evidence($finding);
        self::assertNotEmpty(array_filter($facts, static fn ($f) => ($f['attributes']['runtimeDetails']['diagnosticKind'] ?? '') === 'header_serialization'));
        $repository = new FindingReadRepository($this->entityManager->getConnection());
        self::assertSame(0, $repository->count(new FindingReadFilter(reportKind: 'pure_xss')));
        self::assertSame(1, $repository->count(new FindingReadFilter(cribrumCodes: ['error_response'])));
        $python = $this->python($finding, 'compose');
        self::assertArrayNotHasKey('error', $python, json_encode($python));
        self::assertContains('error_response', $python['behaviorNotice']['tags']);
        self::assertSame($snapshot['components'], $python['components']);
        self::assertSame([], $python['safe']);
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
        self::assertSame($finding->getPocUrl(), $python['poc']);
    }

    private function assertDiagnosticOnlyXss(Finding $finding, array $snapshot): void
    {
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('xss_with_sql_error', $snapshot['lane']);
        self::assertSame('mixed', $snapshot['caseSummary']['kind']);
        self::assertContains('sql_error_observed', $snapshot['tags']);
        self::assertNotContains('sqli_suspected', array_column($snapshot['components'], 'code'));
        self::assertFalse($snapshot['urgencyAllowed']);
        $this->assertConsumers($finding, $snapshot, 'mixed', 'xss_containing');
        self::assertSame(0, (new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(reportKind: 'pure_xss')));
        $python = $this->python($finding, 'compose');
        self::assertStringNotContainsString('Verdacht auf SQL-Injection', $python['paragraphs']);
        self::assertSame([], $python['safe']);
        self::assertSame($finding->getPocUrl(), $python['poc']);
        self::assertArrayHasKey('error', $this->python($finding, 'read', 'pure_xss'));
    }

    private function assertConsumers(Finding $finding, array $snapshot, string $uiKind, string $scope): void
    {
        $views = (new FindingReadRepository($this->entityManager->getConnection()))->findPage(new FindingReadFilter(reportKind: $uiKind));
        self::assertCount(1, array_filter($views, static fn ($view) => $view->id === $finding->getId()));
        $python = $this->python($finding, 'read', $scope);
        self::assertArrayNotHasKey('error', $python, json_encode($python));
        self::assertSame($snapshot['caseSummary'], $python['caseSummary']);
        self::assertSame($snapshot['components'], $python['components']);
    }

    private function python(Finding $finding, string $action = 'read', string $scope = 'all_supported'): array
    {
        $root = getenv('RGEN_BEHAVIOR_PATH') ?: dirname(__DIR__, 2).'/R_GEN-behavior-integration';
        if (!is_file($root.'/src/report_gen/handoff.py')) self::markTestSkipped('Optional report-gen checkout absent; set RGEN_BEHAVIOR_PATH for cross-language acceptance.');
        $process = new Process(['python3', __DIR__.'/Support/behavior_pipeline_consumer.py', APP_TEST_ROOT.'/database.sqlite', $finding->getId(), $action, $scope], $root, ['PYTHONPATH' => $root.'/src']);
        $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function process(Finding $finding,string $raw,string $mode='active'): array
    {
        $logger=new NullLogger();
        $processor=new CribrumProcessingService($this->entityManager,new CribrumClassifier('/nonexistent-private-cribrum',dirname(__DIR__).'/resources/cribrum/candidates',$mode),$this->observations,new CribrumClaimService($this->entityManager),$logger,$this->projection);
        $observation=$processor->process($finding,['schema'=>1,'contentType'=>'text/html','httpStatus'=>500,'finalUrl'=>$finding->getPocUrl(),'rawHtml'=>$raw,'rawHtmlBytes'=>strlen($raw),'rawHtmlTruncated'=>false,'domText'=>null,'domTextBytes'=>null,'domTextTruncated'=>false,'redirectChain'=>[],'headers'=>[]], requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        self::assertNotNull($observation);
        self::assertNotNull($observation->getClassification());
        self::assertSame($observation->getClassification(), \App\Cribrum\ClassificationResult::fromArray($observation->getClassification())->toArray());
        self::assertSame([], \App\Cribrum\EvaluationCoverage::validationErrors($observation->getClassification()['coverage']['evaluation'], $observation->getClassification()['packs']));
        $snapshot=$this->projection->current($finding);
        self::assertNotNull($snapshot,'Processing must persist a report decision; exceptions must not be swallowed');
        return $snapshot;
    }

    private function finding(string $host, array $query = ['q' => 'OPENBUG']): Finding
    {
        return self::getContainer()->get(FindingService::class)->createFinding('https://'.$host.'.fixture.invalid/check?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), type:'unclassified', expectedEvidence:'OPENBUG');
    }

    private function proof(Finding $finding): void
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')->setFinishedAt(new \DateTimeImmutable('-1 minute'))->setRawResult(['proofContext'=>WorkerProofResolver::captureContext($finding), 'dialogEvents'=>[['message'=>'observed OPENBUG', 'type'=>'alert']]]);
        $this->entityManager->persist($run); $this->entityManager->flush();
    }

}
