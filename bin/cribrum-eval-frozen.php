#!/usr/bin/env php
<?php

require dirname(__DIR__).'/vendor/autoload.php';

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php bin/cribrum-eval-frozen.php MANIFEST.json [PRIVATE_OUTPUT.json]\nOffline replay only. Reports preserve source evidence and must retain corpus privacy.\n");
    exit(2);
}
try {
    $result = (new App\Cribrum\Evaluation\FrozenCorpusEvaluator())->evaluate($argv[1], dirname(__DIR__).'/resources/cribrum/candidates');
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    if (isset($argv[2])) {
        if (file_exists($argv[2])) throw new RuntimeException('Refusing to overwrite an existing evaluation artifact.');
        $handle = fopen($argv[2], 'x');
        if ($handle === false) throw new RuntimeException('Cannot create output.');
        chmod($argv[2], 0600);
        fwrite($handle, $json); fclose($handle);
        fwrite(STDOUT, json_encode($result['summary'], JSON_THROW_ON_ERROR)."\n");
    } else fwrite(STDOUT, $json);
    exit($result['summary']['failedCases'] === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}
