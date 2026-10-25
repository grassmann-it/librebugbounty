<?php

namespace App\Cribrum\Detector;

/** Result of one detector invocation. Run/pack selection is supplied by the engine. */
final readonly class DetectorEvaluation
{
    public string $status;

    public function __construct(
        public string $detector,
        public array $signals,
        public array $familyOutcomes,
        public array $diagnostics,
    ) {
        $this->status = self::aggregateStatus(array_column($familyOutcomes, 'status'));
    }

    public function toArray(): array
    {
        // Evidence is transported separately; never duplicate active IDs in coverage.
        return ['detector' => $this->detector, 'familyOutcomes' => $this->familyOutcomes];
    }

    public static function aggregateStatus(array $statuses): string
    {
        foreach (['failed', 'limited', 'unavailable'] as $status) if (in_array($status, $statuses, true)) return $status;
        return 'completed';
    }
}
