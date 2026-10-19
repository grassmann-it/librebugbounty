<?php

namespace App\Cribrum;

final class CribrumClassifier
{
    private const VERSION = 'cribrum-1.0.0';

    private readonly string $privateDirectory;
    private readonly RulePackRepository $repository;

    public function __construct(?string $privateDirectory = null)
    {
        $this->privateDirectory = $privateDirectory ?? dirname(__DIR__, 2).'/var/cribrum/packs';
        $this->repository = new RulePackRepository($this->privateDirectory);
    }

    public function classify(ObservationInput $input): ClassificationResult
    {
        $loaded = $this->repository->load();
        $diagnostics = $loaded['diagnostics'];
        $packs = [];
        foreach ($loaded['packs'] as $pack) $packs[$pack->id] = $pack->version;

        $raw = $input->rawHtml;
        $text = $input->domText ?? ($raw === null ? null : trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $signals = [];
        $merged = [];
        $context = $this->context($input, $raw, $text);
        foreach ($loaded['packs'] as $pack) {
            foreach ($pack->rules as $rule) {
                try {
                    $match = $this->match($rule, $input, $raw, $text, $context);
                } catch (\Throwable $error) {
                    $diagnostics[] = 'Rule '.$pack->id.'/'.$rule->id.' failed: '.$error->getMessage();
                    continue;
                }
                if ($match === null) continue;
                ['phrase' => $phrase, 'representation' => $representation] = $match;
                $signals[] = [
                    'pack' => $pack->id,
                    'version' => $pack->version,
                    'rule' => $rule->id,
                    'representation' => $representation,
                    'phrase' => $phrase,
                    'shadow' => $rule->shadow,
                ];
                if ($rule->shadow) continue;
                $span = $this->span($representation, $representation === 'raw' ? $raw : $text, $phrase);
                $suggestion = new ClaimSuggestion($rule->claim, $rule->state, $rule->score, [$pack->id.'/'.$rule->id], [$rule->reason], $span === null ? [] : [$span]);
                $key = $rule->claim;
                $previous = $merged[$key] ?? null;
                if ($previous === null
                    || ($previous->state !== Taxonomy::OBSERVED && $suggestion->state === Taxonomy::OBSERVED)
                    || ($previous->state === $suggestion->state && $suggestion->score > $previous->score)) {
                    $merged[$key] = $suggestion;
                } elseif ($suggestion->score === $previous->score && $suggestion->state === $previous->state) {
                    $merged[$key] = $this->merge($previous, $suggestion);
                }
            }
        }

        if ($context['payloadLooksUrl'] && isset($merged[Taxonomy::LOCAL_FILE_INCLUSION], $merged[Taxonomy::REMOTE_FILE_INCLUSION])) {
            unset($merged[Taxonomy::LOCAL_FILE_INCLUSION]);
        }

        foreach ($this->xss($input, $raw, $text) as $suggestion) {
            if (!isset($merged[$suggestion->claim]) || $suggestion->score >= $merged[$suggestion->claim]->score) {
                $merged[$suggestion->claim] = $suggestion;
            }
        }
        foreach ($this->xssSignals($input, $raw, $text) as $signal) $signals[] = $signal;

        $suggestions = array_values($merged);
        usort($suggestions, static function (ClaimSuggestion $a, ClaimSuggestion $b): int {
            return [$a->claim, $a->state, $b->score, implode("\n", $a->ruleIds)] <=> [$b->claim, $b->state, $a->score, implode("\n", $b->ruleIds)];
        });
        usort($signals, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
        sort($diagnostics, SORT_STRING);
        $diagnostics = array_values(array_unique($diagnostics));

        return new ClassificationResult($suggestions, $signals, $packs, $diagnostics, self::VERSION);
    }

    /** @return array{phrase:string,representation:string}|null */
    private function match(Rule $rule, ObservationInput $input, ?string $raw, ?string $text, array $context): ?array
    {
        if ($rule->minStatus !== null && ($input->httpStatus === null || $input->httpStatus < $rule->minStatus)) return null;
        $subject = $rule->representation === 'raw' ? $raw : $text;
        if ($subject === null) return null;
        foreach ($rule->context as $required) {
            if (!$context[$required]) return null;
        }
        foreach ($rule->excludes as $exclude) {
            if (mb_stripos($subject, $exclude) !== false) return null;
        }
        if ($rule->any !== []) {
            foreach ($rule->any as $phrase) {
                if (mb_stripos($subject, $phrase) !== false) return ['phrase' => $phrase, 'representation' => $rule->representation];
            }
            return null;
        }
        foreach ($rule->all as $phrase) {
            if (mb_stripos($subject, $phrase) === false) return null;
        }

        return ['phrase' => $rule->all[0], 'representation' => $rule->representation];
    }

    /** @return array<string,bool> */
    private function context(ObservationInput $input, ?string $raw, ?string $text): array
    {
        $needles = [];
        foreach ([...$this->queryValues($input->url), ...$this->queryValues($input->finalUrl ?? ''), $input->payload, $input->expectedEvidence] as $needle) {
            if (is_string($needle) && $needle !== '') $needles[] = $needle;
        }
        $haystacks = array_values(array_filter([$raw, $text], static fn (?string $value): bool => $value !== null));
        $needleInText = false;
        foreach ($needles as $needle) {
            foreach ($haystacks as $haystack) {
                if (mb_strlen($needle) >= 3 && mb_stripos($haystack, $needle) !== false) {
                    $needleInText = true;
                    break 2;
                }
            }
        }
        $filePhrases = ['failed to open stream', 'no such file or directory', 'failed opening for inclusion', 'failed opening', 'open_basedir restriction in effect'];
        $fileError = false;
        foreach ($filePhrases as $phrase) {
            foreach ($haystacks as $haystack) {
                if (mb_stripos($haystack, $phrase) !== false) { $fileError = true; break 2; }
            }
        }

        return [
            'payloadPresent' => $needles !== [],
            'payloadInText' => $needleInText,
            'payloadLooksUrl' => array_any(array_filter($needles, static fn (string $needle): bool => preg_match('/^(?:https?|ftp):\/\//iD', $needle) === 1), static fn (string $needle): bool => array_any($haystacks, static fn (string $haystack): bool => mb_strlen($needle) >= 3 && mb_stripos($haystack, $needle) !== false)),
            'payloadPathLike' => array_any($needles, static fn (string $needle): bool => preg_match('/(?:\.\.\/|\.\\\\|\/(?:etc|proc|var|usr)\/)/', $needle) === 1),
            'fileError' => $fileError,
        ];
    }

    /** @return list<string> */
    private function queryValues(?string $url): array
    {
        $query = parse_url((string) $url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') return [];
        $values = [];
        parse_str($query, $values);
        $result = [];
        foreach ($values as $value) if (is_string($value) && $value !== '') $result[] = $value;

        return $result;
    }

    /** @return array{representation:string,start:int,length:int,excerpt:string}|null */
    private function span(string $representation, ?string $subject, string $phrase): ?array
    {
        if ($subject === null) return null;
        $offset = mb_stripos($subject, $phrase);
        if ($offset === false) return null;
        $start = max(0, $offset - 60);
        $length = min(160, mb_strlen($subject) - $start);
        $excerpt = mb_substr($subject, $start, $length);

        return [
            'representation' => $representation,
            'start' => $offset,
            'length' => mb_strlen($phrase),
            'excerpt' => $excerpt,
        ];
    }

    private function merge(ClaimSuggestion $a, ClaimSuggestion $b): ClaimSuggestion
    {
        $ruleIds = array_values(array_unique([...$a->ruleIds, ...$b->ruleIds]));
        sort($ruleIds, SORT_STRING);
        $reasons = array_values(array_unique([...$a->reasons, ...$b->reasons]));
        sort($reasons, SORT_STRING);
        $spans = [];
        foreach ([...$a->spans, ...$b->spans] as $span) {
            $spans[json_encode($span)] = $span;
        }
        ksort($spans);

        return new ClaimSuggestion($a->claim, $a->state, $a->score, $ruleIds, $reasons, array_values($spans));
    }

    private function usableMarker(string $marker): bool
    {
        return mb_strlen($marker) >= 3 && count(array_unique(mb_str_split($marker))) >= 2;
    }

    /** @return list<ClaimSuggestion> */
    private function xss(ObservationInput $input, ?string $raw, ?string $text): array
    {
        $marker = trim((string) $input->expectedEvidence);
        if (!$this->usableMarker($marker)) return [];
        if ($input->dialogSeen && $input->dialogText !== null && mb_stripos($input->dialogText, $marker) !== false) {
            return [new ClaimSuggestion(Taxonomy::XSS, Taxonomy::OBSERVED, 99, ['builtin/dialog-marker'], ['The expected marker appeared in a native dialog.'], [])];
        }
        $plainRaw = $raw !== null && mb_stripos($raw, $marker) !== false;
        $plainText = $text !== null && mb_stripos($text, $marker) !== false;
        $decodedRaw = $raw === null ? null : html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$plainRaw && $decodedRaw !== null && $plainText && mb_stripos($decodedRaw, $marker) !== false) {
            return [];
        }
        if ($plainRaw || $plainText) {
            $spans = [];
            foreach ([['raw', $raw], ['text', $text]] as [$representation, $subject]) {
                $span = $this->span($representation, $subject, $marker);
                if ($span !== null) $spans[] = $span;
            }
            return [new ClaimSuggestion(Taxonomy::XSS, Taxonomy::SUSPECTED, $plainText ? 72 : 60, ['builtin/reflection'], ['The expected marker appeared in the response without a native dialog.'], $spans)];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function xssSignals(ObservationInput $input, ?string $raw, ?string $text): array
    {
        $marker = trim((string) $input->expectedEvidence);
        if (!$this->usableMarker($marker) || $raw === null || $text === null || mb_stripos($text, $marker) === false) return [];
        $plain = mb_stripos($raw, $marker) !== false;
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$plain && mb_stripos($decoded, $marker) !== false) {
            return [['pack' => 'builtin', 'version' => self::VERSION, 'rule' => 'reflection/entity-encoded', 'representation' => 'raw', 'phrase' => $marker, 'shadow' => false]];
        }

        return [];
    }
}
