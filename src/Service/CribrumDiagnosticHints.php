<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Cause labels from immutable claim metadata, never new facts or report components. */
final class CribrumDiagnosticHints
{
    public const RULES = [
        'sql_error_observed' => [
            'builtin/sql_observation',
            'builtin/sql-serialized-native-diagnostic',
            'builtin/access-expression-diagnostic',
        ],
        'database_runtime_error' => ['builtin/database-result-runtime'],
    ];

    public static function available(Connection $connection): bool
    {
        foreach ([
            'finding_claim_revision' => ['finding_id', 'claim', 'revision', 'state', 'source', 'observation_id', 'rule_ids'],
            'http_observation' => ['id', 'finding_id', 'poc_revision', 'metadata', 'artifact_sha256'],
        ] as $table => $required) {
            $columns = $connection->fetchFirstColumn('SELECT name FROM pragma_table_info(?)', [$table]);
            if (array_diff($required, $columns) !== []) return false;
        }
        return true;
    }

    public static function rulesSql(string $claimAlias): string
    {
        $c = self::alias($claimAlias);
        // A later manual decision must not borrow an earlier classifier cause.
        return "(SELECT dh.rule_ids FROM finding_claim_revision dh WHERE dh.finding_id=$c.finding_id"
            ." AND dh.claim=$c.claim AND dh.revision=$c.revision AND dh.state=$c.state AND dh.source=$c.source"
            ." AND dh.observation_id=$c.observation_id LIMIT 1)";
    }

    /** @return list<string> */
    public static function codes(string $claim, string $state, ?string $rulesJson): array
    {
        if ($claim !== 'error_disclosure' || !in_array($state, ['observed', 'confirmed', 'rejected', 'resolved', 'stale'], true)) return [];
        $rules = $rulesJson === null ? [] : json_decode($rulesJson);
        if (!is_array($rules) || !array_is_list($rules)) return [];
        $rules = array_values(array_filter($rules, 'is_string'));
        $result = [];
        foreach (self::RULES as $code => $recognizedRules) if (array_intersect($recognizedRules, $rules) !== []) $result[] = $code;
        return $result;
    }

    public static function hasCodeSql(string $claimAlias, string $code): string
    {
        $c = self::alias($claimAlias);
        if (!isset(self::RULES[$code])) throw new \InvalidArgumentException('Unknown diagnostic hint.');
        $rules = self::rulesSql($c);
        $allowed = implode(',', array_map(static fn (string $rule): string => "'$rule'", self::RULES[$code]));
        $list="CASE WHEN json_valid($rules) THEN CASE WHEN json_type($rules)='array' THEN $rules ELSE '[]' END ELSE '[]' END";
        return "($c.claim='error_disclosure' AND $c.state IN ('observed','confirmed','rejected','resolved','stale')"
            ." AND EXISTS (SELECT 1 FROM json_each($list) dr WHERE dr.type='text' AND dr.value IN ($allowed)))";
    }

    public static function currentSourceSql(string $claimAlias, string $findingAlias, bool $reportsAvailable, bool $observationsAvailable): string
    {
        if (!$reportsAvailable || !$observationsAvailable) return '0';
        $c = self::alias($claimAlias); $f = self::alias($findingAlias);
        $meta = "CASE WHEN json_valid(so.metadata) THEN so.metadata ELSE '{}' END";
        $snapshot = "CASE WHEN json_valid(sr.snapshot_json) THEN sr.snapshot_json ELSE '{}' END";
        $pin = "json_extract($meta,'$.requestContextSha256')";
        $url = "COALESCE(json_extract($meta,'$.requestUrl'),json_extract($meta,'$.classificationContext.url'))";
        return "EXISTS (SELECT 1 FROM http_observation so JOIN finding_report_current sr ON sr.finding_id=so.finding_id"
            ." WHERE so.id=$c.observation_id AND so.finding_id=$f.id AND so.poc_revision=$f.poc_revision"
            ." AND $url=COALESCE($f.poc_url,$f.url) AND sr.is_current=1"
            ." AND length($pin)=64 AND $pin NOT GLOB '*[^0-9a-f]*'"
            ." AND $pin=json_extract($snapshot,'$.coverage.requestContextSha256')"
            ." AND so.artifact_sha256 IS NOT NULL AND so.artifact_sha256=json_extract($snapshot,'$.coverage.sourceRef.artifactSha256'))";
    }

    private static function alias(string $alias): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) throw new \InvalidArgumentException('Invalid SQL alias.');
        return $alias;
    }
}
