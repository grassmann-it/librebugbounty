<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\RuntimeDiagnosticFamilies;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\TestCase;

final class NativePhpFatalDiagnosticTest extends TestCase
{
    public function testNativeMysqliTypeErrorSurvivesOpenOuterMarkupWithExactRawEvidence(): void
    {
        $diagnostic = self::trace();
        $body = 'Ä😀<html><body><div class="results">'.$diagnostic.'<br />';
        foreach (['shadow', 'active'] as $mode) {
            $input = self::input($body);
            $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input, $mode);
            self::assertSame('completed', $evaluation->status);
            self::assertCount(1, $evaluation->signals);
            $signal = $evaluation->signals[0];
            self::assertSame('error_response', $signal['code']);
            self::assertSame(['family' => 'php', 'diagnosticKind' => 'database_result_type', 'contextKind' => 'native_fatal'], $signal['attributes']['runtimeDetails']);
            self::assertSame($mode, $signal['mode']);
            self::assertSame($diagnostic, $signal['spans'][0]['excerpt']);
            EvidenceSignal::validateAgainstInput($signal, $input);
            foreach ($signal['spans'] as $span) {
                self::assertSame('raw', $span['representation']);
                self::assertSame(mb_substr($body, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
                self::assertSame(hash('sha256', $body), $span['representationSha256']);
            }
        }
    }

    public function testGenericSupportedPhpTypeErrorAndRenderedSourceLocationAreRecognized(): void
    {
        $message = 'Uncaught TypeError: Synthetic\\Search::lookup(): Argument #2 ($limit) must be of type ?int, string given';
        foreach ([
            self::trace($message),
            '<b>Fatal error</b>: '.$message.' in <b>/srv/fixture/search.php</b> on line <b>42</b>',
            'Fatal error: '.$message.' in /srv/fixture/search.php on line 42',
            str_replace('/srv/fixture/search.php', 'C:\\fixture\\search.php', self::trace($message)),
            str_replace('#0 /srv/fixture/search.php(42): mysqli_fetch_array()', '#0 [internal function]: Synthetic\\Search::lookup()', self::trace($message)),
        ] as $body) {
            $input = self::input($body);
            $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input);
            self::assertSame('completed', $evaluation->status);
            self::assertCount(1, $evaluation->signals, $body);
            self::assertSame('argument_type', $evaluation->signals[0]['attributes']['runtimeDetails']['diagnosticKind']);
            EvidenceSignal::validateAgainstInput($evaluation->signals[0], $input);
        }
    }

    public function testNativeFatalGrammarReusesSupportedNonDatabaseDiagnosticKinds(): void
    {
        $body = self::trace('Uncaught Error: Call to undefined function synthetic_lookup()');
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate(self::input($body));
        self::assertCount(1, $evaluation->signals);
        self::assertSame('undefined_function', $evaluation->signals[0]['attributes']['runtimeDetails']['diagnosticKind']);
        self::assertSame(['error_response'], array_unique(array_column($evaluation->signals, 'code')));
    }

    public function testDocumentationScriptsRequestEchoAndUnboundProseRemainExcluded(): void
    {
        $diagnostic = self::trace();
        foreach ([
            '<section class="documentation">'.$diagnostic.'</section>',
            '<p>Example: '.$diagnostic.'</p>',
            "Example: ".$diagnostic,
            '<code>'.$diagnostic.'</code>',
            '<template>'.$diagnostic.'</template>',
            '<textarea>'.$diagnostic.'</textarea>',
            '<!--'.$diagnostic.'-->',
            '<script>const diagnostic=`'.$diagnostic.'`;</script>',
            '<div data-message="'.str_replace('"', '&quot;', $diagnostic).'"></div>',
            json_encode(['catalog' => [$diagnostic]], JSON_THROW_ON_ERROR),
            'The boolean mysqli result might be false after a failed query.',
            'Uncaught TypeError: mysqli_fetch_array(): Argument #1 ($result) must be of type mysqli_result, bool given',
            '<b>Fatal error</b>: Uncaught TypeError: generic bool given in /srv/fixture/search.php on line 42',
        ] as $body) {
            self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate(self::input($body))->signals, $body);
        }
        foreach ([null, $diagnostic] as $payload) {
            $input = new ObservationInput('https://fixture.test/?q='.rawurlencode($diagnostic), '<main>'.$diagnostic, null, 'text/html', 200, null, $payload, null, false, null);
            self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($input)->signals);
        }
        $plain = html_entity_decode(strip_tags($diagnostic), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $input = new ObservationInput('https://fixture.test/', $diagnostic, null, 'text/html', 200, null, $plain, null, false, null);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($input)->signals, 'Adding native formatting to a wholly supplied fatal error is not provenance.');
    }

    public function testMissingOrConflictingSourceAndTraceCannotBeReconstructed(): void
    {
        $diagnostic = self::trace();
        foreach ([
            str_replace('/srv/fixture/search.php', 'search.php', $diagnostic),
            str_replace('/srv/fixture/search.php', 'https://fixture.test/search.php', $diagnostic),
            str_replace(' on line <b>42</b>', ' on line <b>43</b>', $diagnostic),
            str_replace('thrown in <b>/srv/fixture/search.php</b>', 'thrown in <b>/srv/fixture/other.php</b>', $diagnostic),
            str_replace("Stack trace:\n", '', $diagnostic),
            str_replace('#0 ', '#1 ', $diagnostic),
            str_replace('#0 /srv/fixture/search.php(42): mysqli_fetch_array()', '#0 an unrelated prose line', $diagnostic),
            str_replace('#0 /srv/fixture/search.php(42): mysqli_fetch_array()', '#0 [internal unrelated]: Synthetic::lookup()', $diagnostic),
            substr($diagnostic, 0, strpos($diagnostic, "\nStack trace:")),
            str_replace("\nStack trace:", "</div><div>\nStack trace:", $diagnostic),
        ] as $body) {
            self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate(self::input($body))->signals, $body);
        }
    }

    public function testInvalidEarlierFatalDoesNotHideLaterIndependentDiagnostic(): void
    {
        $good = self::trace();
        $bad = str_replace(' on line <b>42</b>', ' on line <b>43</b>', $good);
        $body = $bad."\n".$good;
        $input = self::input($body);
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input);
        self::assertCount(1, $evaluation->signals);
        self::assertSame(mb_strlen($bad."\n", 'UTF-8'), $evaluation->signals[0]['spans'][0]['start']);
        EvidenceSignal::validateAgainstInput($evaluation->signals[0], $input);
    }

    public function testDomOnlySourcePreservesItsOwnCoordinatesAndRawDoesNotBorrowDom(): void
    {
        $text = 'Ä😀 '.strip_tags(self::trace());
        $input = new ObservationInput('https://fixture.test/', null, $text, 'text/html', 200, null, null, null, false, null);
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input);
        self::assertCount(1, $evaluation->signals);
        self::assertSame('dom_text', $evaluation->signals[0]['spans'][0]['representation']);
        EvidenceSignal::validateAgainstInput($evaluation->signals[0], $input);
        $input = new ObservationInput('https://fixture.test/', '<p>Normal page</p>', $text, 'text/html', 200, null, null, null, false, null);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($input)->signals);
    }

    public function testFrameBudgetIsExplicitAndCannotQualifyIncompleteEvidence(): void
    {
        $body = str_replace("#1 {main}\n", implode('', array_map(static fn (int $i): string => '#'.$i." /srv/fixture/caller.php(8): lookup()\n", range(1, 17))), self::trace());
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate(self::input($body));
        self::assertSame('limited', $evaluation->status);
        self::assertSame([], $evaluation->signals);
        $issues = array_merge(...array_column($evaluation->familyOutcomes[0]['stages'], 'issues'));
        self::assertContains('php_native_fatal', array_column($issues, 'stage'));
    }

    private static function trace(string $message = 'Uncaught TypeError: mysqli_fetch_array(): Argument #1 ($result) must be of type mysqli_result, bool given'): string
    {
        return '<b>Fatal error</b>: '.$message." in /srv/fixture/search.php:42\nStack trace:\n#0 /srv/fixture/search.php(42): mysqli_fetch_array()\n#1 {main}\n  thrown in <b>/srv/fixture/search.php</b> on line <b>42</b>";
    }

    private static function input(string $body): ObservationInput
    {
        return new ObservationInput('https://fixture.test/', $body, null, 'text/html', 200, null, null, null, false, null);
    }
}
