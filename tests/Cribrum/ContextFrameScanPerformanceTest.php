<?php

declare(strict_types=1);

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\ContextTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ContextFrameScanPerformanceTest extends TestCase
{
    public function testLargePlainAndDottedBodiesFinishWithinABoundedWorkerRun(): void
    {
        // A subprocess deadline catches quadratic scans without hanging the suite.
        // The generous bound covers startup and several maximum-sized inputs.
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
foreach ([
    ['rawHtml', str_repeat('a', 1048576)],
    ['domText', str_repeat('a', 524288)],
    ['domText', str_repeat('éa', 170000)],
    ['domText', str_repeat('1', 524288)],
    ['rawHtml', str_repeat('a.', 524288)],
    ['rawHtml', str_repeat('Namespace.', 100000)],
    ['rawHtml', str_repeat('a.', 500000).'System.Web.Foo()'],
    ['rawHtml', str_repeat("bei System.Web.Foo(\n", 40000)],
] as [$field, $body]) {
    $input = App\Cribrum\ObservationInput::fromArray(['url' => 'https://fixture.test/size', $field => $body]);
    $result = (new App\Cribrum\CribrumClassifier('/nonexistent-private-packs'))->classify($input);
    if ($result->suggestions !== []) throw new RuntimeException('Plain or incomplete frames acquired a claim.');
}
echo 'completed';
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2)]);
        $process->setTimeout(10);
        $process->mustRun();
        self::assertSame('completed', $process->getOutput());
    }

    public function testNearLimitPrefixKeepsTheLaterFrameAndItsExactUnicodeSpan(): void
    {
        $trace = "App.Run()\nat System.Web.Page.Handle()";
        $prefix = str_repeat('a', 500000)."\né";
        foreach (['rawHtml' => 'raw', 'domText' => 'dom_text'] as $field => $representation) {
            $input = ObservationInput::fromArray(['url' => 'https://fixture.test/size', $field => $prefix.$trace]);
            $result = (new ContextTriageDetector())->evaluateDiagnostics($input);
            $details = array_values(array_filter($result->signals, static fn (array $signal): bool => ($signal['attributes']['detailKind'] ?? null) === 'stack_trace'));
            self::assertCount(1, $details);
            EvidenceSignal::validateAgainstInput($details[0], $input);
            self::assertSame($trace, $details[0]['spans'][0]['excerpt']);
            self::assertSame(mb_strlen($prefix, 'UTF-8'), $details[0]['spans'][0]['start']);
            self::assertSame(mb_strlen($trace, 'UTF-8'), $details[0]['spans'][0]['length']);
            self::assertSame($representation, $details[0]['spans'][0]['representation']);
        }
    }

    public function testLegacyFrameStartsKeepTheirExactExcerpts(): void
    {
        foreach (['', 'É', '1', '123É', 'cat ', 'éà '] as $prefix) {
            $trace = "App.Run()\nat System.Web.Page.Handle()";
            $expected = match ($prefix) {
                'cat ' => 'at '.$trace,
                'éà ' => 'à '.$trace,
                default => $trace,
            };
            $body = $prefix.$trace;
            $input = ObservationInput::fromArray(['url' => 'https://fixture.test/trace', 'rawHtml' => $body]);
            $result = (new ContextTriageDetector())->evaluateDiagnostics($input);
            $details = array_values(array_filter($result->signals, static fn (array $signal): bool => ($signal['attributes']['detailKind'] ?? null) === 'stack_trace'));
            self::assertCount(1, $details, $prefix);
            self::assertSame($expected, $details[0]['spans'][0]['excerpt'], $prefix);
            self::assertSame(mb_strpos($body, $expected, 0, 'UTF-8'), $details[0]['spans'][0]['start'], $prefix);
            EvidenceSignal::validateAgainstInput($details[0], $input);
        }
    }
}
