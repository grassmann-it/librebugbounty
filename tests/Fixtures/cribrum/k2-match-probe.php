<?php

// Isolated test instrumentation only. The production source has no counters or switches.
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$source = file_get_contents($root.'/src/Cribrum/CribrumClassifier.php');
$sourceHash = hash('sha256', $source);
if (!hash_equals($request['sourceSha256'], $sourceHash)) throw new RuntimeException('Classifier source hash changed before instrumentation.');
if (class_exists(App\Cribrum\CribrumClassifier::class, false)) throw new RuntimeException('Classifier must be unloaded before isolated instrumentation.');
$entry = "    private function match(Rule \$rule, ObservationInput \$input, ?string \$raw, ?string \$text, array \$context, ?string &\$exclusion): ?array\n    {";
if (substr_count($source, $entry) !== 1) throw new RuntimeException('Expected exactly one matcher entry.');
$source = str_replace($entry, $entry.'
        $GLOBALS["cribrumK2ProbeCalls"][] = $rule->id;
        if ($GLOBALS["cribrumK2ProbeThrow"] ?? false) throw new \\RuntimeException("Controlled isolated matcher failure.");', $source);
if ($request['uncached'] ?? false) {
    $origin = '$shareCoreMatches = $this->repository->isShippedCore($pack);';
    if (substr_count($source, $origin) !== 1) throw new RuntimeException('Expected exactly one cache eligibility assignment.');
    $source = str_replace($origin, '$shareCoreMatches = false;', $source);
}
eval(substr($source, strlen('<?php')));
$compiler = new App\Cribrum\RulePackCompiler();
$injected = isset($request['injectedPacks']) ? array_map($compiler->compile(...), $request['injectedPacks']) : null;
$engine = new App\Cribrum\CribrumClassifier($request['privateDirectory'] ?? '/tmp/cribrum-k2-probe-no-private-packs', null, 'shadow', $injected);
if (isset($request['forcedShippedManifest'])) {
    // Exercise ordered/gated tuples using the real classify loop, without changing shipped bytes.
    $pack = $compiler->compile($request['forcedShippedManifest']);
    $repository = (new ReflectionProperty($engine, 'repository'))->getValue($engine);
    (new ReflectionProperty($repository, 'cache'))->setValue($repository, ['packs' => [$pack], 'diagnostics' => []]);
    (new ReflectionProperty($repository, 'shippedCore'))->setValue($repository, $pack);
}
$GLOBALS['cribrumK2ProbeThrow'] = $request['throwMatcher'] ?? false;
$snapshots = [];
foreach ($request['inputs'] as $data) {
    $GLOBALS['cribrumK2ProbeCalls'] = [];
    $explain = [];
    $result = $engine->classify(App\Cribrum\ObservationInput::fromArray($data), $explain);
    $snapshots[] = ['result' => $result->toArray(), 'explain' => $explain, 'calls' => $GLOBALS['cribrumK2ProbeCalls']];
}
echo json_encode(['sourceSha256' => $sourceHash, 'snapshots' => $snapshots], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
