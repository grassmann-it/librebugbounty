<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\RuntimeDiagnosticFamilies;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseResultWarningTest extends TestCase
{
    public static function nativeWarnings(): iterable
    {
        yield 'mysqli Windows' => ['mysqli_num_rows', 'mysqli_result', 'bool', 'D:\\Sites\\synthetic_app\\www\\search.php'];
        yield 'mysqli Unix' => ['mysqli_fetch_array', 'mysqli_result', 'boolean', '/srv/fixture/search.php'];
        yield 'mysql Unix' => ['mysql_num_rows', 'resource', 'boolean', '/var/www/fixture/search.php'];
        yield 'mysql legacy path' => ['mysql_fetch_array', 'resource', 'boolean', '/inc/synthetic_start.php4'];
        yield 'mysql data seek' => ['mysql_data_seek', 'resource', 'bool', '/srv/fixture/search.php'];
        yield 'mysqli fetch fields' => ['mysqli_fetch_fields', 'mysqli_result', 'bool', '/srv/fixture/search.php'];
    }

    #[DataProvider('nativeWarnings')]
    public function testNativeDatabaseResultWarningIsRuntimeEvidenceOnly(string $function, string $expected, string $given, string $path): void
    {
        $warning = self::warning($function, $expected, $given, $path);
        $body = 'Ä😀<html><body><div class="results">'.$warning.'<br />';
        foreach (['shadow', 'active'] as $mode) {
            $input = self::input($body);
            $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input, $mode);
            self::assertSame('completed', $evaluation->status);
            self::assertCount(1, $evaluation->signals);
            $signal = $evaluation->signals[0];
            self::assertSame('error_response', $signal['code']);
            self::assertSame(['family' => 'php', 'diagnosticKind' => 'database_result_type', 'contextKind' => 'native_warning'], $signal['attributes']['runtimeDetails']);
            self::assertSame($mode, $signal['mode']);
            self::assertSame($warning, $signal['spans'][0]['excerpt']);
            self::assertArrayNotHasKey('claim', $signal);
            EvidenceSignal::validateAgainstInput($signal, $input);
            foreach ($signal['spans'] as $span) {
                self::assertSame('raw', $span['representation']);
                self::assertSame(mb_substr($body, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
                self::assertSame(hash('sha256', $body), $span['representationSha256']);
            }
        }
    }

    public function testRepeatedWarningsRetainSeparateNativeFileLineEvidence(): void
    {
        $warnings = array_map(static fn (int $line): string => self::warning(line: $line), [19, 43, 67, 91, 115, 139, 163, 187]);
        $input = self::input('<section>'.implode('<br />', $warnings).'</section>');
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input);
        self::assertSame('completed', $evaluation->status);
        self::assertSame(array_fill(0, 8, 'error_response'), array_column($evaluation->signals, 'code'));
        self::assertSame($warnings, array_map(static fn (array $signal): string => $signal['spans'][0]['excerpt'], $evaluation->signals));
        foreach ($evaluation->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        self::assertCount(1, (new RuntimeDiagnosticFamilies())->evaluate(self::input($warnings[0].'<br />'.$warnings[0]))->signals);
    }

    public function testDocumentationScriptsCommentsAttributesAndCatalogsStayInert(): void
    {
        $warning = self::warning();
        foreach ([
            '<section class="documentation">'.$warning.'</section>',
            '<p>For example: '.$warning.'</p>',
            'Sample: '.$warning,
            '<code>'.$warning.'</code>',
            '<template>'.$warning.'</template>',
            '<noscript>'.$warning.'</noscript>',
            '<textarea>'.$warning.'</textarea>',
            '<!--'.$warning.'-->',
            '<script>const warning=`'.$warning.'`;</script>',
            '<div data-message="'.str_replace('"', '&quot;', $warning).'"></div>',
            json_encode(['messages' => [$warning]], JSON_THROW_ON_ERROR),
        ] as $body) self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate(self::input($body))->signals, $body);
    }

    public function testWhollySuppliedRequestAndFinalUrlDiagnosticsAddNoProvenance(): void
    {
        $warning = self::warning();
        $plain = strip_tags($warning);
        $url = 'https://fixture.test/?message='.rawurlencode($plain);
        foreach ([
            new ObservationInput($url, $warning, null, 'text/html', 200, null, null, null, false, null),
            new ObservationInput('https://fixture.test/', $warning, null, 'text/html', 200, $url, null, null, false, null),
            new ObservationInput('https://fixture.test/', $warning, null, 'text/html', 200, null, $plain, null, false, null),
            new ObservationInput('https://fixture.test/?message='.rawurlencode($warning), $warning, null, 'text/html', 200, null, null, null, false, null),
        ] as $input) self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($input)->signals);
    }

    public function testGenericBooleanProseWrongApiAndMalformedLocationsDoNotBecomeDiagnostics(): void
    {
        $warning = self::warning();
        foreach ([
            'mysqli_num_rows() may receive a bool when a query fails.',
            'Warning: mysqli_num_rows() expects parameter 1 to be mysqli_result, bool given',
            str_replace(' on line <b>42</b>', '', $warning),
            str_replace('/srv/fixture/search.php', 'search.php', $warning),
            str_replace('/srv/fixture/search.php', 'https://fixture.test/search.php', $warning),
            str_replace('/srv/fixture/search.php', '/srv/fixture/search.php.txt', $warning),
            str_replace('mysqli_num_rows()', 'mysqli_query()', $warning),
            str_replace('mysqli_num_rows()', 'mysql_num_rows()', $warning),
            str_replace('mysqli_result', 'resource', $warning),
            str_replace('parameter 1 ', 'parameter 2 ', $warning),
            str_replace('bool given', 'string given', $warning),
            str_replace('<b>42</b>', '<b>0</b>', $warning),
            str_replace('mysqli_num_rows()', 'mysqli_result()', $warning),
            self::warning('mysql_fetch_fields', 'resource', 'boolean'),
        ] as $body) self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate(self::input($body))->signals, $body);
    }

    public function testExcludedEarlierWarningDoesNotSuppressLaterIndependentSource(): void
    {
        $first = self::warning();
        $good = self::warning(line: 81);
        $prefix = '<code>'.$first.'</code>';
        $body = $prefix.$good;
        $input = self::input($body);
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input);
        self::assertCount(1, $evaluation->signals);
        self::assertSame($good, $evaluation->signals[0]['spans'][0]['excerpt']);
        self::assertSame(mb_strlen($prefix, 'UTF-8'), $evaluation->signals[0]['spans'][0]['start']);
        EvidenceSignal::validateAgainstInput($evaluation->signals[0], $input);
    }

    public function testDomOnlyCoordinatesAndRawPreferenceRemainExplicit(): void
    {
        $text = 'Ä😀 '.strip_tags(self::warning());
        $input = new ObservationInput('https://fixture.test/', null, $text, 'text/html', 200, null, null, null, false, null);
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input);
        self::assertCount(1, $evaluation->signals);
        self::assertSame('dom_text', $evaluation->signals[0]['spans'][0]['representation']);
        EvidenceSignal::validateAgainstInput($evaluation->signals[0], $input);
        $input = new ObservationInput('https://fixture.test/', '<p>Normal content</p>', $text, 'text/html', 200, null, null, null, false, null);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($input)->signals);
    }

    public function testWarningSignalBudgetRemainsVisible(): void
    {
        $body = implode('<br />', array_map(static fn (int $line): string => self::warning(line: $line), range(1, 17)));
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate(self::input($body));
        self::assertSame('limited', $evaluation->status);
        self::assertCount(16, $evaluation->signals);
        foreach ($evaluation->signals as $signal) self::assertSame('error_response', $signal['code']);
    }

    private static function warning(string $function = 'mysqli_num_rows', string $expected = 'mysqli_result', string $given = 'bool', string $path = '/srv/fixture/search.php', int $line = 42): string
    {
        return '<b>Warning</b>: '.$function.'() expects parameter 1 to be '.$expected.', '.$given.' given in <b>'.$path.'</b> on line <b>'.$line.'</b>';
    }

    private static function input(string $body): ObservationInput
    {
        return new ObservationInput('https://fixture.test/', $body, null, 'text/html', 200, null, null, null, false, null);
    }
}
