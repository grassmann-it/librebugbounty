<?php

// Dedicated acceptance server only; never include from the application entrypoint.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CribrumBadgesTransportFixture
{
    public static function client(string $name = 'default'): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use ($name): MockResponse {
            $parts = parse_url($url);
            $path = $parts['path'] ?? '/';
            $accepted = ($parts['scheme'] ?? null) === 'http'
                && ($parts['host'] ?? null) === '127.0.0.1'
                && ($parts['port'] ?? null) === 1;
            $category = !in_array($method, ['GET', 'HEAD'], true) ? 'mutation'
                : ($name === 'health' || $path === '/health' ? 'status-read' : 'read');
            $root = (string) getenv('STUDIO_BROWSER_ROOT');
            $log = $root.'/calls.json';
            $stream = fopen($log, 'c+');
            if ($stream === false || !flock($stream, LOCK_EX)) throw new RuntimeException('Cannot lock fixture calllog.');
            try {
                $calls = json_decode(stream_get_contents($stream) ?: '[]', true, flags: JSON_THROW_ON_ERROR);
                $calls[] = ['client' => $name, 'category' => $category, 'method' => $method,
                    'url' => $url, 'path' => $path, 'accepted' => $accepted, 'refused' => !$accepted && $name === 'default', 'mocked' => true,
                    'body' => (string) ($options['body'] ?? '')];
                rewind($stream); ftruncate($stream, 0);
                fwrite($stream, json_encode($calls, JSON_THROW_ON_ERROR)); fflush($stream);
            } finally {
                flock($stream, LOCK_UN); fclose($stream);
            }
            if (!$accepted && $name === 'default') throw new RuntimeException('Fixture refuses non-local transport.');
            return new MockResponse('{"errorMessage":"Synthetic fixture response"}', [
                'http_code' => 503,
                'response_headers' => ['content-type: application/json'],
            ]);
        });
    }

    public static function release(): MockHttpClient { return self::client('release'); }
    public static function health(): MockHttpClient { return self::client('health'); }
    public static function securityTxt(): MockHttpClient { return self::client('security-txt'); }
    public static function probe(): MockHttpClient { return self::client('cribrum-probe'); }
}


$root = (string) getenv('STUDIO_BROWSER_ROOT');
if (!preg_match('~^/tmp/librebugbounty-cribrum-badges-[a-zA-Z0-9_-]+$~D', $root)) {
    throw new RuntimeException('A fresh isolated STUDIO_BROWSER_ROOT for Studio inventory is required.');
}
foreach ([
    'APP_ENV' => 'test',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'isolated-studio-inventory-browser-acceptance',
    'DATABASE_URL' => 'sqlite:///'.$root.'/database.sqlite',
    'EVIDENCE_STORAGE_DIR' => $root.'/artifacts',
    'PLAYWRIGHT_WORKER_URL' => 'http://127.0.0.1:1',
    'PLAYWRIGHT_INTERACTIVE_URL' => 'http://127.0.0.1:1',
    'PLAYWRIGHT_WORKER_TOKEN' => 'isolated-ux0-worker-token-never-used',
    'PLAYWRIGHT_GATEWAY_TOKEN' => 'isolated-ux0-gateway-token-never-used',
    'RETEST_DEFAULT_TIMEOUT_MS' => '1000',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}

$kernel = new class('test', false) extends App\Kernel {
    public function getCacheDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/cache'; }
    public function getLogDir(): string { return (string) getenv('STUDIO_BROWSER_ROOT').'/logs'; }
    protected function configureContainer(Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        foreach (['http_client' => 'client', 'test.release_http_client' => 'release',
            'test.health_http_client' => 'health', 'test.security_txt_http_client' => 'securityTxt',
            'app.cribrum_probe_client' => 'probe'] as $id => $factory) {
            $container->services()->set($id, MockHttpClient::class)
                ->factory([CribrumBadgesTransportFixture::class, $factory])->public();
        }
    }
};

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'init' || file_exists($root)) {
        throw new RuntimeException('Use init once with a new isolated root.');
    }
    mkdir($root, 0700, true);
    file_put_contents($root.'/calls.json', '[]');
    file_put_contents($root.'/runtime.json', json_encode(['sourceRoot' => realpath(dirname(__DIR__, 2)), 'storageRoot' => $root, 'createdAt' => gmdate('c'), 'pid' => getmypid()], JSON_THROW_ON_ERROR));
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    (new Doctrine\ORM\Tools\SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    $db=$manager->getConnection();
    require_once dirname(__DIR__,2).'/migrations/Version20261022000000.php';
    $migration=new \DoctrineMigrations\Version20261022000000($db,new \Psr\Log\NullLogger());
    $migration->up(new \Doctrine\DBAL\Schema\Schema());
    foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
    $domain=(new App\Entity\Domain())->setHostname('127.0.0.1')->setScheme('http')->setAuthorized(true);
    $manager->persist($domain);
    $fixtures=[]; $findings=[];
    // Main is deliberately oldest, beyond page one before a global additional-first sort.
    foreach (array_merge(['mixed','dense','sql-error','disclosure','include','waf','stale','missing'],array_map(fn($n)=>sprintf('plain-%02d',$n),range(1,27))) as $i=>$name) {
        $finding=(new App\Entity\Finding())->setDomain($domain)->setTitle('Synthetic · '.$name)->setType('synthetic')->setSeverity('medium')->setUrl('http://127.0.0.1/fixture-never-navigate/'.$name)->setSubmittedAt(new DateTimeImmutable('2026-10-01T00:00:00Z +'.$i.' minutes'));
        $manager->persist($finding); $findings[$name]=$finding;
        $fixtures[$name]=['id'=>$finding->getId(),'url'=>$finding->getUrl()];
    }
    $findings['dense']->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-20T10:00:00Z'));
    $findings['waf']->setManualAssessment('confirmed', null, new DateTimeImmutable('2026-10-20T10:00:00Z'));
    $manager->flush();
    foreach ($findings as $name=>$finding) $db->update('finding',['created_at'=>$name==='mixed'?'2020-01-01 00:00:00':'2026-10-01 00:00:00'],['id'=>$finding->getId()]);
    // Stored local evidence and HTTP/DOM context: no target request or worker call.
    $mixed = $findings['mixed'];
    $imageSource = dirname(__DIR__).'/Fixtures/ux0/synthetic-alert.png';
    if (!is_file($imageSource)) throw new RuntimeException('Local UX0 synthetic image is missing.');
    $relativeImage = $mixed->getId().'/ux0-synthetic-alert.png';
    if (!is_dir($root.'/artifacts/'.$mixed->getId())) mkdir($root.'/artifacts/'.$mixed->getId(), 0700, true);
    copy($imageSource, $root.'/artifacts/'.$relativeImage);
    $imagePath = 'storage/artifacts/'.$relativeImage;
    $evidence = (new App\Entity\Evidence())->setFinding($mixed)->setKind('screenshot')->setFilePath($imagePath)->setSha256(hash_file('sha256', $imageSource));
    $manager->persist($evidence);
    $job = (new App\Entity\ScreenshotJob())->setFinding($mixed)->setUrl($mixed->getUrl())->setStatus('available')
        ->setRequestedAt(new DateTimeImmutable('2020-01-01T11:59:00Z'))
        ->setCapturedAt(new DateTimeImmutable('2020-01-01T12:00:00Z'))
        ->setFinishedAt(new DateTimeImmutable('2020-01-01T12:00:05Z'))
        ->setScreenshotPath($imagePath)->setCaptureMetadata(['fixture' => 'UX0', 'synthetic' => true, 'captureMethod' => 'local-stored-illustration']);
    $manager->persist($job);
    $dense = $findings['dense'];
    $denseRelative = $dense->getId().'/ux0-synthetic-dense.png';
    mkdir($root.'/artifacts/'.$dense->getId(), 0700, true);
    copy($imageSource, $root.'/artifacts/'.$denseRelative);
    $denseImagePath = 'storage/artifacts/'.$denseRelative;
    $denseEvidence = (new App\Entity\Evidence())->setFinding($dense)->setKind('screenshot')
        ->setFilePath($denseImagePath)->setSha256(hash_file('sha256', $imageSource));
    $manager->persist($denseEvidence);
    $denseJob = (new App\Entity\ScreenshotJob())->setFinding($dense)->setUrl($dense->getUrl())->setStatus('available')
        ->setRequestedAt(new DateTimeImmutable('2026-10-20T08:59:00Z'))
        ->setCapturedAt(new DateTimeImmutable('2026-10-20T09:00:00Z'))
        ->setFinishedAt(new DateTimeImmutable('2026-10-20T09:00:05Z'))
        ->setScreenshotPath($denseImagePath)->setCaptureMetadata(['fixture' => 'UX0', 'synthetic' => true,
            'captureMethod' => 'local-stored-illustration']);
    $manager->persist($denseJob);
    $run = (new App\Entity\RetestRun())->setFinding($mixed)->setMode('browser')->setResult('inconclusive')
        ->setStartedAt(new DateTimeImmutable('2020-01-01T12:00:00Z'))
        ->setFinishedAt(new DateTimeImmutable('2020-01-01T12:00:05Z'))
        ->setObservedEvidence('Stored synthetic SQL error context; not exploit confirmation.')
        ->setFinalUrl($mixed->getUrl());
    $manager->persist($run);
    $denseRun = (new App\Entity\RetestRun())->setFinding($findings['dense'])->setMode('browser')->setResult('inconclusive')
        ->setStartedAt(new DateTimeImmutable('2026-10-20T09:00:00Z'))->setFinishedAt(new DateTimeImmutable('2026-10-20T09:00:05Z'))
        ->setObservedEvidence('Synthetic stored dense history; no target visit.');
    $manager->persist($denseRun);
    $manager->persist(new App\Entity\FindingAssessment($findings['dense'], 'confirmed', null,
        new DateTimeImmutable('2026-10-20T10:00:00Z'), $denseRun->getId()));
    $wafOld = (new App\Entity\RetestRun())->setFinding($findings['waf'])->setMode('browser')->setResult('still_vulnerable')
        ->setStartedAt(new DateTimeImmutable('2026-10-20T09:00:00Z'))->setFinishedAt(new DateTimeImmutable('2026-10-20T09:00:05Z'));
    $manager->persist($wafOld);
    $manager->persist(new App\Entity\FindingAssessment($findings['waf'], 'confirmed', null,
        new DateTimeImmutable('2026-10-20T10:00:00Z'), $wafOld->getId(), null, null, [$wafOld->getId()]));
    $wafNew = (new App\Entity\RetestRun())->setFinding($findings['waf'])->setMode('browser')->setResult('inconclusive')
        ->setStartedAt(new DateTimeImmutable('2026-10-24T09:00:00Z'))->setFinishedAt(new DateTimeImmutable('2026-10-24T09:00:05Z'))
        ->setObservedEvidence('Synthetic WAF block; pending human review, prior assessment retained.');
    $manager->persist($wafNew);
    $rawHtml = '<html><body>You have an error in your SQL syntax near \'marker\' at line 1</body></html>';
    $domText = "You have an error in your SQL syntax near 'marker' at line 1";
    $manifest = ['rawSha256' => hash('sha256', $rawHtml), 'domSha256' => hash('sha256', $domText)];
    $artifact = gzencode(json_encode(['schema' => 1, 'manifest' => $manifest,
        'rawHtml' => $rawHtml, 'domText' => $domText], JSON_THROW_ON_ERROR), 9, FORCE_GZIP);
    if ($artifact === false) throw new RuntimeException('Cannot encode synthetic observation.');
    $httpRelative = $mixed->getId().'/ux0-observation.json.gz';
    file_put_contents($root.'/artifacts/'.$httpRelative, $artifact);
    $http = new App\Entity\HttpObservation($mixed, $mixed->getPocRevision(), 500, $mixed->getUrl(), 'text/html', strlen($rawHtml), false, strlen($domText), false,
        ['fixture' => 'UX0', 'synthetic' => true, 'requestUrl' => $mixed->getUrl(), 'classificationContext' => [
            'url' => $mixed->getUrl(), 'payload' => 'marker', 'expectedEvidence' => 'synthetic local marker',
            'dialogSeen' => false, 'dialogText' => null], 'manifest' => $manifest]);
    $http->setScreenshotJobId($job->getId())->setRetestRunId($run->getId())
        ->setArtifact('storage/artifacts/'.$httpRelative, hash('sha256', $artifact))
        ->setClassification(['suggestions' => [[
            'claim' => 'sql_injection', 'state' => 'suspected', 'score' => 45,
            'ruleIds' => ['synthetic-sql-error-context'], 'reasons' => ['Stored SQL error is suggestive, not exploit proof.'], 'spans' => [],
        ]], 'signals' => [['kind' => 'synthetic_fixture', 'code' => 'sql_error_observed', 'representation' => 'text']],
            'packs' => ['fixture' => 'ux0'], 'diagnostics' => [], 'classifierVersion' => 'synthetic-fixture-v1']);
    $manager->persist($http);
    $manager->flush();
    $db->executeStatement('UPDATE evidence SET created_at=? WHERE id=?', ['2020-01-01 12:00:00', $evidence->getId()]);
    $db->executeStatement('UPDATE http_observation SET created_at=? WHERE id=?', ['2020-01-01 12:00:00', $http->getId()]);
    $fixtures['dense'] += ['syntheticEvidenceId' => $denseEvidence->getId(), 'syntheticScreenshotJobId' => $denseJob->getId()];
    $fixtures['mixed'] += ['syntheticEvidenceId' => $evidence->getId(), 'syntheticObservationId' => $http->getId(), 'syntheticRetestRunId' => $run->getId()];
    foreach (['mixed','sql-error'] as $name) $db->insert('disclosure_reminder',['finding_id'=>$findings[$name]->getId(),'due_on'=>'2026-10-25','next_step'=>'Synthetic local follow-up','revision'=>Symfony\Component\Uid\Uuid::v4()->toRfc4122(),'changed_at'=>'2026-10-24 00:00:00']);
    $source=$db->fetchOne('SELECT source_instance_id FROM report_source_contract');
    foreach ($findings as $name=>$finding) {
        if ($name==='missing') continue;
        $codes=match($name) {'mixed'=>['xss_confirmed','sqli_suspected','sql_error_observed'],
            'dense'=>array_merge(array_map(static fn(string $code): string => match ($code) {
                'sql_injection' => 'sqli_suspected', 'local_file_inclusion' => 'lfi_suspected',
                'remote_file_inclusion' => 'rfi_suspected', default => $code,
            }, array_keys(array_column(App\Value\CribrumBadgeCatalog::entries(), null, 'code'))), ['third_party_unmapped_signal']),
            'sql-error'=>['sql_error_observed'], 'disclosure'=>['information_disclosure'], 'include'=>['lfi_suspected'],
            'waf'=>['xss_confirmed','waf_block','coverage_limited'], 'stale'=>['sqli_suspected'], default=>['xss_confirmed']};
        $componentCodes = match ($name) {
            'mixed' => ['xss_confirmed','sql_error_observed'],
            'dense' => ['xss','sql_error_observed','information_disclosure'],
            'waf' => ['xss_confirmed'],
            'sql-error', 'include', 'stale' => [],
            default => $codes,
        };
        $certainty = static function (string $code): string {
            $canonical = App\Value\CribrumBadgeCatalog::canonicalCode($code);
            return in_array($canonical, ['sql_injection','local_file_inclusion','remote_file_inclusion'], true)
                ? 'suspected' : ($canonical === 'xss' ? 'confirmed' : 'observed');
        };
        $kind = match ($name) {
            'mixed', 'dense' => 'mixed', 'waf' => 'pure_xss',
            'disclosure' => 'pure_information_disclosure',
            'sql-error', 'include', 'stale' => 'unknown',
            default => 'pure_xss',
        };
        $purity = in_array($kind, ['pure_xss', 'pure_information_disclosure'], true) ? 'pure' : ($kind === 'mixed' ? 'mixed' : 'unknown');
        $summary=['kind'=>$kind,'purity'=>$purity,
            'components'=>$componentCodes,'hints'=>array_values(array_diff($codes, $componentCodes))];
        $db->insert('finding_report_context',['finding_id'=>$finding->getId(),'basis_revision'=>$name==='stale'?2:1]);
        $snapshot=['schemaVersion'=>1,'policyVersion'=>'behavior-report-v2','sourceInstanceId'=>$source,
            'findingId'=>$finding->getId(),'decisionRevision'=>1,'basisRevision'=>1,
            'lane'=>in_array($name, ['sql-error','include','stale'], true)?'none':($name==='waf'?'xss':'synthetic'),
            'gate'=>in_array($name, ['sql-error','include','stale'], true)?'no_mail':($name==='waf'?'hold':'eligible'),
            'reasonCodes'=>$name==='waf'?['new_waf_block_unreviewed']:[],
            'wafReview'=>$name==='waf'?['pending'=>[['observedAt'=>'2026-10-24T09:00:00Z',
                'evidenceRefs'=>[['kind'=>'retest_run','id'=>$wafNew->getId()]]]]]:null,
            'decidedAt'=>'2026-10-24T12:00:00Z','observation'=>['observedAt'=>'2020-01-01T12:00:00Z'],
            'tags'=>$codes,'caseSummary'=>$summary,
            'components'=>array_map(fn($code)=>['code'=>$code,'certainty'=>$certainty($code),
                'selected'=>true,'evidenceRefs'=>[]],$componentCodes),'factIds'=>[]];
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        $db->insert('finding_report_revision',['finding_id'=>$finding->getId(),'revision'=>1,'basis_revision'=>1,'schema_version'=>1,'policy_version'=>'behavior-report-v2','source_instance_id'=>$source,'lane'=>$snapshot['lane'],'gate'=>$snapshot['gate'],'snapshot_json'=>$json,'snapshot_sha256'=>hash('sha256',$json),'created_at'=>$snapshot['decidedAt'],'case_kind'=>$summary['kind'],'purity'=>$summary['purity'],'tags_json'=>json_encode($codes),'case_summary_json'=>json_encode($summary),'coverage_json'=>'{}']);
    }
    file_put_contents($root.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
    echo 'Initialized isolated Cribrum badge fixtures at '.$root."\n";
    exit;
}

if (!is_file($root.'/database.sqlite') || !is_file($root.'/fixtures.json')) {
    throw new RuntimeException('Initialize isolated Studio inventory fixtures before serving requests.');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__cribrum_badges_fixture') {
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $snapshot = [];
    foreach ($database->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $snapshot[$table] = $database->query('SELECT * FROM '. $database->quote($table).' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['isolated' => true, 'fixtures' => json_decode(file_get_contents($root.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR), 'snapshot' => $snapshot, 'runtime' => [...json_decode(file_get_contents($root.'/runtime.json'), true, flags: JSON_THROW_ON_ERROR), 'serverPid' => getmypid()], 'paths' => ['database' => $root.'/database.sqlite', 'artifacts' => $root.'/artifacts', 'cache' => $root.'/cache'], 'calls' => json_decode(file_get_contents($root.'/calls.json'), true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
    return;
}

$public = realpath(dirname(__DIR__, 2).'/public');
$file = realpath($public.'/'.$path);
if ($path !== '/' && $file !== false && str_starts_with($file, $public.'/') && is_file($file)) {
    return false;
}

$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
// Preserve this acceptance fixture's historical German copy without deployment settings.
if (!$request->cookies->has('lbb_locale')) $request->cookies->set('lbb_locale', 'de');
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
