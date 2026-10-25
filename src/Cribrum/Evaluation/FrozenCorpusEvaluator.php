<?php

namespace App\Cribrum\Evaluation;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;

/** Offline only: no HTTP client, database or dataset mutation. Manifests may be private. */
final class FrozenCorpusEvaluator
{
    public function evaluate(string $manifestPath, string $candidateDirectory): array
    {
        $root = realpath(dirname($manifestPath));
        $manifestBytes = file_get_contents($manifestPath);
        if ($manifestBytes === false || $root === false) throw new \InvalidArgumentException('Cannot read manifest.');
        $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['schemaVersion'] ?? null) !== 1 || !is_array($manifest['records'] ?? null)) throw new \InvalidArgumentException('Invalid frozen corpus schema.');
        // Empty pack path is deliberate: a developer's live private packs are never loaded.
        $empty = $root.'/__no_private_packs__';
        if (file_exists($empty)) throw new \InvalidArgumentException('Reserved empty pack directory exists.');
        $baseline = new CribrumClassifier($empty);
        $shadow = new CribrumClassifier($empty, $candidateDirectory);
        $activation = new CribrumClassifier($empty, $candidateDirectory, 'active');
        $sourceHashes = [];
        // Capture the whole classifier core and detector set so newly registered
        // detectors or changed input/compiler semantics cannot escape provenance.
        $project = dirname(__DIR__, 3);
        $dependencies = [
            __FILE__, $project.'/bin/cribrum-eval-frozen.php',
            ...glob(dirname(__DIR__).'/*.php'),
            ...glob(dirname(__DIR__).'/Detector/*.php'),
            ...glob($project.'/resources/cribrum/packs/*.json'),
            ...glob($candidateDirectory.'/*.json'),
        ];
        foreach ($dependencies as $path) $sourceHashes[str_replace($project.'/', '', $path)] = hash_file('sha256', $path);
        ksort($sourceHashes);
        if (isset($manifest['baseline']['coreSha256']) && $manifest['baseline']['coreSha256'] !== ($sourceHashes['resources/cribrum/packs/core.json'] ?? null)) throw new \InvalidArgumentException('Frozen baseline pack hash differs.');
        $results = []; $failures = 0; $hashes = []; $families = []; $ids = [];
        foreach ($manifest['records'] as $record) {
            $id = $record['id'] ?? null;
            if (!is_string($id) || isset($ids[$id])) throw new \InvalidArgumentException('Missing or duplicate case ID.');
            $ids[$id] = true;
            $raw = $this->artifact($root, $record['body'], $record['bodySha256']);
            $metadataBytes = $this->artifact($root, $record['metadata'], $record['metadataSha256']);
            $metadata = json_decode($metadataBytes, true, 512, JSON_THROW_ON_ERROR);
            if (($metadata['body_sha256'] ?? null) !== $record['bodySha256'] || ($metadata['request_pass'] ?? null) !== 2 || ($metadata['capture_status'] ?? null) !== 'captured' || !is_string($metadata['capture_finished_at'] ?? null) || ($metadata['error'] ?? null) !== null) throw new \InvalidArgumentException('Not a finished frozen pass-2 pair.');
            foreach (['httpStatus' => 'http_status', 'contentType' => 'content_type', 'bodyTruncated' => 'body_truncated'] as $key => $sourceKey) {
                if (!array_key_exists($key, $record) || $record[$key] !== ($metadata[$sourceKey] ?? null)) throw new \InvalidArgumentException('Frozen metadata differs: '.$key);
            }
            $input = ObservationInput::fromArray([
                'url' => $metadata['input_url'], 'rawHtml' => $raw,
                'httpStatus' => $metadata['http_status'], 'contentType' => $metadata['content_type'],
                'finalUrl' => $metadata['final_url'] ?? null, 'payload' => $metadata['payload'] ?? null,
                'coverage' => ['status' => $metadata['body_truncated'] ? 'partial' : 'complete', 'rawTruncated' => $metadata['body_truncated']],
            ]);
            $before = $baseline->classify($input)->toArray();
            $after = $shadow->classify($input)->toArray();
            $preview = $activation->classify($input)->toArray();
            $codes = []; $problems = [];
            foreach ($after['signals'] as $signal) {
                if (($signal['kind'] ?? null) !== 'observation_evidence') continue;
                try { EvidenceSignal::validateAgainstInput($signal, $input); }
                catch (\Throwable $e) { $problems[] = 'representation: '.$e->getMessage(); }
                $codes[] = $signal['code'];
                if (($signal['mode'] ?? null) !== 'shadow') $problems[] = 'candidate signal is not shadow';
            }
            $codes = array_values(array_unique($codes)); sort($codes);
            foreach ($record['expect']['requiredCodes'] ?? [] as $code) if (!in_array($code, $codes, true)) $problems[] = 'missing '.$code;
            foreach ($record['expect']['forbiddenCodes'] ?? [] as $code) if (in_array($code, $codes, true)) $problems[] = 'forbidden '.$code;
            if ($record['expect']['sqlContextRequired'] ?? false) {
                $found = false;
                foreach ($after['signals'] as $signal) if (($signal['code'] ?? null) === 'sql_error_observed' && ($signal['attributes']['assessment'] ?? null) === 'context_required') $found = true;
                if (!$found) $problems[] = 'missing context_required assessment';
            }
            if ($before['suggestions'] !== $after['suggestions']) $problems[] = 'unexplained active claim change in shadow';
            foreach ($after['diagnostics'] as $diagnostic) $problems[] = 'classifier diagnostic: '.$diagnostic;
            $claims = array_column($before['suggestions'], 'claim');
            $collisions = [];
            foreach (['sql_injection' => 'sql_input_structure_indication', 'local_file_inclusion' => 'lfi_suspected', 'remote_file_inclusion' => 'rfi_suspected'] as $claim => $support) {
                if (in_array($claim, $claims, true) && !in_array($support, $codes, true)) $collisions[] = ['legacyClaim' => $claim, 'missingQualifiedFact' => $support];
            }
            if ($problems !== []) ++$failures;
            $hashes[$record['bodySha256']] = true;
            $families[$record['templateFamily'] ?? 'unspecified'] = true;
            $results[] = ['id' => $id, 'split' => $record['split'] ?? 'unspecified', 'bodySha256' => $record['bodySha256'], 'metadataSha256' => $record['metadataSha256'], 'codes' => $codes, 'factDelta' => ['added' => array_values(array_diff($codes, array_column($before['signals'], 'code'))), 'removed' => array_values(array_diff(array_column($before['signals'], 'code'), $codes))], 'problems' => $problems, 'legacyOverclaimCollisions' => $collisions, 'activeClaimDelta' => ['changed' => $this->changedClaims($before['suggestions'], $preview['suggestions']), 'added' => array_values(array_diff(array_column($preview['suggestions'], 'claim'), $claims)), 'removed' => array_values(array_diff($claims, array_column($preview['suggestions'], 'claim')))], 'baseline' => $before, 'candidateShadow' => $after, 'activationPreview' => $preview];
        }
        return ['schemaVersion' => 1, 'manifestId' => $manifest['id'] ?? null, 'manifestSha256' => hash('sha256', $manifestBytes), 'implementationSha256' => $sourceHashes, 'baselineConfiguration' => $baseline->configuration(), 'candidateConfiguration' => $shadow->configuration(), 'summary' => ['cases' => count($results), 'uniqueBodies' => count($hashes), 'templateFamilies' => count($families), 'failedCases' => $failures, 'legacyCollisionCases' => count(array_filter($results, static fn ($r) => $r['legacyOverclaimCollisions'] !== []))], 'limitations' => $manifest['limitations'] ?? [], 'records' => $results];
    }

    private function changedClaims(array $before, array $after): array
    {
        $before = array_column($before, null, 'claim');
        $after = array_column($after, null, 'claim');
        $changed = [];
        foreach (array_intersect(array_keys($before), array_keys($after)) as $claim) if ($before[$claim] !== $after[$claim]) $changed[] = ['claim' => $claim, 'before' => $before[$claim], 'after' => $after[$claim]];
        return $changed;
    }

    private function artifact(string $root, string $relative, string $hash): string
    {
        $path = realpath($root.'/'.$relative);
        if ($path === false || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path)) throw new \InvalidArgumentException('Artifact outside frozen corpus or missing.');
        $bytes = file_get_contents($path);
        if ($bytes === false || !hash_equals($hash, hash('sha256', $bytes))) throw new \InvalidArgumentException('Frozen artifact hash mismatch.');
        return $bytes;
    }
}
