<?php

namespace App\Cribrum\Detector;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;

/** Invocation-local collector. Only completely qualified evidence may be added. */
final class DetectorRun
{
    private array $signals = [];
    private array $families = [];
    private array $diagnostics = [];
    private ?array $scope = null;
    private ?array $candidate = null;

    public function __construct(private readonly ObservationInput $input, private readonly string $detector, array $families)
    {
        foreach ($families as $family) {
            $this->identifier($family);
            $this->families[$family] = ['family' => $family, 'status' => 'completed', 'stages' => [], 'counters' => ['candidatesConsidered' => 0, 'signalsQualified' => 0, 'candidatesExcluded' => 0]];
        }
        $this->identifier($detector);
    }

    /** Independent stages continue after failure; shared prerequisites can name multiple families. */
    public function stage(string|array $families, string $stage, ?string $representation, callable $work): mixed
    {
        $families = is_string($families) ? [$families] : $families;
        if ($families === []) throw new \InvalidArgumentException('A stage requires a family.');
        foreach ($families as $family) $this->ensureStage($family, $stage, $representation);
        $previousScope = $this->scope;
        $previousCandidate = $this->candidate;
        $this->scope = ['families' => $families, 'stage' => $stage, 'representation' => $representation];
        $this->candidate = null;
        try {
            return $work();
        } catch (\Throwable $error) {
            $code = match (true) {
                $error instanceof RegexFailure => 'regex_error',
                $error instanceof InvalidEvidenceCandidate => 'invalid_evidence_candidate',
                $error instanceof \JsonException => 'parser_error',
                default => 'engine_error',
            };
            foreach ($families as $family) {
                $details = $error instanceof RegexFailure ? ['operation' => $error->operation, 'pcreCode' => $error->pcreCode] : [];
                $this->issue($family, $stage, $representation, 'failed', $code, [], $details);
            }
            return null;
        } finally {
            $this->scope = $previousScope;
            $this->candidate = $previousCandidate;
        }
    }

    public function add(array $signal): void
    {
        if ($this->scope === null) throw new \LogicException('Qualified evidence must be collected inside a stage.');
        try {
            $signal = EvidenceSignal::validateAgainstInput($signal, $this->input);
        } catch (\Throwable) {
            throw new InvalidEvidenceCandidate();
        }
        $this->signals[] = $signal;
        foreach ($this->scope['families'] as $family) $this->count($family, 'signalsQualified');
    }

    /** Values are counts, never text, excerpts, URLs or parameter values. */
    public function count(string $family, string $name, int $by = 1): void
    {
        $this->family($family);
        $this->identifier($name);
        if ($by < 0) throw new \InvalidArgumentException('Counters cannot decrease.');
        $this->families[$family]['counters'][$name] = ($this->families[$family]['counters'][$name] ?? 0) + $by;
    }

    /** Private source reference, retained only if safely bound to an original representation. */
    public function candidate(?string $representation = null, ?int $start = null, ?int $length = null): void
    {
        $this->candidate = null;
        if ($representation === null || $start === null || $length === null) return;
        $source = match ($representation) { 'raw' => $this->input->rawHtml, 'dom_text' => $this->input->domText, 'dialog' => $this->input->dialogText, default => null };
        if ($source === null || !mb_check_encoding($source, 'UTF-8') || $start < 0 || $length <= 0) return;
        $size = mb_strlen($source, 'UTF-8');
        if ($start > $size || $length > $size - $start) return;
        $this->candidate = ['representation' => $representation, 'representationSha256' => hash('sha256', $source), 'start' => $start, 'length' => $length];
    }

    public function limited(string|array $families, string $stage, ?string $representation, string $code, array $counters = []): void
    {
        foreach (is_string($families) ? [$families] : $families as $family) $this->issue($family, $stage, $representation, 'limited', $code, $counters);
    }

    public function unavailable(string|array $families, string $stage, ?string $representation, string $code, array $counters = []): void
    {
        foreach (is_string($families) ? [$families] : $families as $family) $this->issue($family, $stage, $representation, 'unavailable', $code, $counters);
    }

    public function failed(string|array $families, string $stage, ?string $representation, string $code, array $counters = []): void
    {
        foreach (is_string($families) ? [$families] : $families as $family) $this->issue($family, $stage, $representation, 'failed', $code, $counters);
    }

    public function finish(): DetectorEvaluation
    {
        $families = [];
        foreach ($this->families as $family) {
            $family['stages'] = array_values($family['stages']);
            // A declared family with no recorded applicable work has no completion proof.
            if ($family['stages'] === []) {
                $family['status'] = 'unavailable';
                $family['stages'][] = ['stage' => 'evaluation', 'representation' => null, 'status' => 'unavailable', 'issues' => [[
                    'code' => 'missing_evaluation_stage', 'detector' => $this->detector, 'family' => $family['family'], 'stage' => 'evaluation', 'representation' => null,
                ]]];
            }
            ksort($family['counters'], SORT_STRING);
            $families[] = $family;
        }
        return new DetectorEvaluation($this->detector, $this->signals, $families, array_values(array_unique($this->diagnostics)));
    }

    private function issue(string $family, string $stage, ?string $representation, string $status, string $code, array $counters, array $details = []): void
    {
        $key = $this->ensureStage($family, $stage, $representation);
        $this->identifier($code);
        foreach ($counters as $name => $value) {
            $this->identifier($name);
            if ($value !== null && (!is_int($value) || $value < 0)) throw new \InvalidArgumentException('Issue counters must be nonnegative or unknown.');
        }
        $issue = ['code' => $code, 'detector' => $this->detector, 'family' => $family, 'stage' => $stage, 'representation' => $representation];
        if ($counters !== []) { ksort($counters, SORT_STRING); $issue['counters'] = $counters; }
        if ($this->candidate !== null && $this->scope !== null && in_array($family, $this->scope['families'], true)) $issue['candidate'] = $this->candidate;
        $issue = [...$issue, ...$details];
        $current = &$this->families[$family]['stages'][$key];
        $current['status'] = DetectorEvaluation::aggregateStatus([$current['status'], $status]);
        $current['issues'][] = $issue;
        $this->families[$family]['status'] = DetectorEvaluation::aggregateStatus([$this->families[$family]['status'], $status]);
        if ($status === 'failed') $this->diagnostics[] = 'Detector '.$this->detector.' family '.$family.' stage '.$stage.' failed ('.$code.').';
    }

    private function ensureStage(string $family, string $stage, ?string $representation): string
    {
        $this->family($family);
        $this->identifier($stage);
        if ($representation !== null && !in_array($representation, ['raw', 'dom_text', 'dialog'], true)) throw new \InvalidArgumentException('Unknown evaluation representation.');
        $key = $stage.'|'.($representation ?? '');
        $this->families[$family]['stages'][$key] ??= ['stage' => $stage, 'representation' => $representation, 'status' => 'completed', 'issues' => []];
        return $key;
    }

    private function family(string $family): void
    {
        if (!isset($this->families[$family])) throw new \InvalidArgumentException('Undeclared detector family.');
    }

    private function identifier(mixed $value): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > 128
            || strspn($value[0], 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') !== 1
            || strspn($value, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.:-') !== strlen($value)) {
            throw new \InvalidArgumentException('Invalid evaluation identifier.');
        }
    }
}
