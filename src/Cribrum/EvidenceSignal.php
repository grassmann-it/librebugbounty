<?php

namespace App\Cribrum;

/** Context-free observation evidence, never a finding-bound BehaviorFact. */
final class EvidenceSignal
{
    public const SCHEMA_VERSION = 1;
    public const VERSION = '1';

    public static function make(string $code, ObservationInput $input, array $spans, array $attributes = [], array $relations = [], string $producer = 'triage-v1', string $mode = 'shadow'): array
    {
        foreach ($spans as $i => &$span) {
            if (!is_array($span)) self::fail('Each span must be an object.');
            $span['id'] ??= 's'.$i;
            $representation = $span['representation'] ?? 'raw';
            $raw = self::representation($input, $representation);
            if ($raw === null) self::fail('Span representation is unavailable.');
            $expected = self::span($raw, $span['start'] ?? -1, $span['length'] ?? 0);
            $expected['representation'] = $representation;
            foreach ($expected as $key => $value) {
                if (array_key_exists($key, $span) && $span[$key] !== $value) self::fail('Span does not match input: '.$key);
                $span[$key] = $value;
            }
        }
        unset($span);
        foreach ($relations as &$relation) {
            if (!is_array($relation)) self::fail('Each relation must be an object.');
            foreach (['from', 'to'] as $key) {
                if (isset($relation[$key]) && is_int($relation[$key])) {
                    $relation[$key] = $spans[$relation[$key]]['id'] ?? throw new \InvalidArgumentException('Unknown relation span.');
                }
            }
        }
        unset($relation);
        $signal = [
            'kind' => 'observation_evidence', 'schemaVersion' => self::SCHEMA_VERSION,
            'code' => $code, 'mode' => $mode, 'producer' => $producer, 'producerVersion' => self::VERSION,
            // Null explicitly means no raw artifact, rather than hashing synthetic text.
            'artifactSha256' => $input->rawHtml === null ? null : hash('sha256', $input->rawHtml),
            'spans' => array_values($spans), 'attributes' => $attributes, 'relations' => array_values($relations),
            // Legacy signal transport keys: these do not imply a claim suggestion.
            'pack' => $producer, 'version' => self::VERSION, 'rule' => $code,
            'representation' => $spans[0]['representation'] ?? 'raw',
            'phrase' => $spans[0]['excerpt'] ?? '', 'shadow' => $mode === 'shadow',
        ];
        $signal['evidenceId'] = self::id($signal);
        return self::validate($signal);
    }

    /** Unicode codepoint offsets into exact, unmodified valid UTF-8 bytes. */
    public static function span(string $raw, int $start, int $length): array
    {
        self::utf8($raw);
        $size = mb_strlen($raw, 'UTF-8');
        if ($start < 0 || $length <= 0 || $start > $size || $length > $size - $start) self::fail('Span is outside its representation.');
        $excerpt = mb_substr($raw, $start, $length, 'UTF-8');
        return [
            'representation' => 'raw', 'representationSha256' => hash('sha256', $raw),
            'start' => $start, 'length' => $length, 'excerpt' => $excerpt,
            'representationLength' => $size, 'excerptSha256' => hash('sha256', $excerpt),
        ];
    }

    /** Validate stored structure/integrity. Use validateAgainstInput for source authenticity. */
    public static function validate(array $signal): array
    {
        if (($signal['kind'] ?? null) !== 'observation_evidence' || ($signal['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) self::fail('Unsupported observation evidence schema.');
        foreach (['code', 'producer', 'producerVersion'] as $key) {
            if (!is_string($signal[$key] ?? null) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D', $signal[$key])) self::fail('Invalid '.$key.'.');
        }
        if (!in_array($signal['mode'] ?? null, ['shadow', 'active'], true)) self::fail('Invalid evidence mode.');
        if (!array_key_exists('artifactSha256', $signal) || ($signal['artifactSha256'] !== null && !self::hash($signal['artifactSha256']))) self::fail('Invalid artifact hash.');
        foreach (['spans', 'attributes', 'relations'] as $key) if (!is_array($signal[$key] ?? null)) self::fail('Invalid '.$key.'.');
        if (!array_is_list($signal['spans']) || !array_is_list($signal['relations'])) self::fail('Spans and relations must be lists.');
        $ids = [];
        $representations = [];
        foreach ($signal['spans'] as $span) {
            if (!is_array($span) || !is_string($span['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $span['id']) || isset($ids[$span['id']])) self::fail('Invalid or duplicate span id.');
            $ids[$span['id']] = true;
            $representation = $span['representation'] ?? null;
            if (!in_array($representation, ['raw', 'dom_text', 'dialog'], true)) self::fail('Invalid representation.');
            if (!self::hash($span['representationSha256'] ?? null) || !self::hash($span['excerptSha256'] ?? null)) self::fail('Invalid span hash.');
            foreach (['start', 'length', 'representationLength'] as $key) if (!is_int($span[$key] ?? null)) self::fail('Span offsets must be integers.');
            if ($span['start'] < 0 || $span['length'] <= 0 || $span['representationLength'] < $span['start'] || $span['length'] > $span['representationLength'] - $span['start']) self::fail('Span is outside its representation.');
            if (!is_string($span['excerpt'] ?? null)) self::fail('Missing span excerpt.');
            self::utf8($span['excerpt']);
            if (mb_strlen($span['excerpt'], 'UTF-8') !== $span['length'] || !hash_equals($span['excerptSha256'], hash('sha256', $span['excerpt']))) self::fail('Span excerpt mismatch.');
            $basis = [$span['representationSha256'], $span['representationLength']];
            if (isset($representations[$representation]) && $representations[$representation] !== $basis) self::fail('Inconsistent representation basis.');
            $representations[$representation] = $basis;
            if ($representation === 'raw' && $span['representationSha256'] !== $signal['artifactSha256']) self::fail('Raw span must refer to artifact hash.');
        }
        foreach ($signal['relations'] as $relation) {
            if (!is_array($relation) || !is_string($relation['type'] ?? null) || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $relation['type'])) self::fail('Invalid relation type.');
            foreach (['from', 'to'] as $key) if (!is_string($relation[$key] ?? null) || !isset($ids[$relation[$key]])) self::fail('Relation references an unknown span.');
            if (isset($relation['transformations']) && !is_array($relation['transformations'])) self::fail('Invalid relation transformations.');
        }
        if (($signal['shadow'] ?? null) !== ($signal['mode'] === 'shadow') || ($signal['pack'] ?? null) !== $signal['producer'] || ($signal['version'] ?? null) !== self::VERSION || ($signal['rule'] ?? null) !== $signal['code'] || ($signal['representation'] ?? null) !== ($signal['spans'][0]['representation'] ?? 'raw') || ($signal['phrase'] ?? null) !== ($signal['spans'][0]['excerpt'] ?? '')) self::fail('Legacy transport keys disagree with evidence.');
        if (!self::hash($signal['evidenceId'] ?? null) || !hash_equals(self::id($signal), $signal['evidenceId'])) self::fail('Evidence integrity mismatch.');
        return $signal;
    }

    /** Re-bind stored evidence to its actual immutable capture before adapting to facts. */
    public static function validateAgainstInput(array $signal, ObservationInput $input): array
    {
        self::validate($signal);
        if ($signal['artifactSha256'] !== ($input->rawHtml === null ? null : hash('sha256', $input->rawHtml))) self::fail('Artifact hash does not match input.');
        foreach ($signal['spans'] as $span) {
            $raw = self::representation($input, $span['representation']);
            if ($raw === null) self::fail('Span representation unavailable.');
            $expected = self::span($raw, $span['start'], $span['length']);
            foreach (['representationSha256', 'representationLength', 'excerpt', 'excerptSha256'] as $key) if ($expected[$key] !== $span[$key]) self::fail('Span does not match source input.');
        }
        return $signal;
    }

    private static function representation(ObservationInput $input, string $name): ?string
    {
        return match ($name) { 'raw' => $input->rawHtml, 'dom_text' => $input->domText, 'dialog' => $input->dialogText, default => throw new \InvalidArgumentException('Unknown representation.') };
    }

    private static function id(array $signal): string
    {
        unset($signal['evidenceId']);
        return hash('sha256', json_encode(self::canonical($signal), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) self::fail('Evidence must contain finite JSON values.');
            return $value;
        }
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return array_map(self::canonical(...), $value);
    }

    private static function utf8(string $value): void
    {
        if (preg_match('//u', $value) !== 1) self::fail('Evidence must use valid UTF-8.');
    }

    private static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function fail(string $message): never
    {
        throw new \InvalidArgumentException($message);
    }
}
