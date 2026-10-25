<?php

declare(strict_types=1);
namespace App\Service;

use App\Value\CribrumBadgeCatalog as Catalog;

/** Correlated SQLite expressions shared by list/count/export. No response bodies. */
final class CribrumFindingQuery
{
    private static function alias(string $alias): string { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias)) throw new \InvalidArgumentException('Invalid SQL alias.'); return $alias; }
    public static function canonicalSql(string $expression): string
    {
        $raw="CASE WHEN $expression LIKE 'claim:%' THEN substr($expression,7) ELSE $expression END";
        $sql='CASE '.$raw;
        foreach (Catalog::ALIASES as $from=>$to) $sql.=" WHEN '$from' THEN '$to'";
        return $sql.' ELSE '.$raw.' END';
    }
    public static function freshness(string $alias='f',bool $reportsAvailable=true): string
    {
        $alias=self::alias($alias);
        return $reportsAvailable?"COALESCE((SELECT CASE WHEN rp.is_current=1 THEN 'current' ELSE 'stale' END FROM finding_report_current rp WHERE rp.finding_id=$alias.id),'missing')":"'missing'";
    }
    /** NULL means legacy/unknown binding, never proof of a mismatch. */
    public static function observationCurrentSql(string $claimAlias='bc',string $findingAlias='f',bool $observationsAvailable=true): string
    {
        $c=self::alias($claimAlias); $f=self::alias($findingAlias);
        if (!$observationsAvailable) return 'NULL';
        $meta="CASE WHEN json_valid(bo.metadata) THEN bo.metadata ELSE '{}' END";
        $url="COALESCE(json_extract($meta,'$.requestUrl'),json_extract($meta,'$.classificationContext.url'))";
        return "(SELECT CASE WHEN bo.poc_revision<>$f.poc_revision OR ($url IS NOT NULL AND $url<>COALESCE($f.poc_url,$f.url)) THEN 0 WHEN bo.poc_revision=$f.poc_revision THEN 1 ELSE NULL END FROM http_observation bo WHERE bo.id=$c.observation_id AND bo.finding_id=$c.finding_id)";
    }
    /** Live manual/migration intent survives independently of classifier evaluation freshness. */
    public static function claimCurrentSql(string $claimAlias='bc',string $findingAlias='f',bool $reportsAvailable=true,bool $observationsAvailable=true): string
    {
        $c=self::alias($claimAlias); $f=self::alias($findingAlias);
        return "($c.state NOT IN ('rejected','resolved','stale') AND ($c.source<>'classifier' OR (".self::freshness($f,$reportsAvailable)."<>'stale' AND ".self::observationCurrentSql($c,$f,$observationsAvailable).' IS NOT 0)))';
    }
    public static function badgeRows(string $alias='f',bool $reportsAvailable=true,bool $history=false,bool $historyAvailable=false,bool $claimHistoryAvailable=false,bool $observationsAvailable=true): string
    {
        $alias=self::alias($alias); $terminal="'rejected','resolved','stale'";
        $fresh=self::freshness($alias,$reportsAvailable);
        $claimCurrent=self::claimCurrentSql('bc',$alias,$reportsAvailable,$observationsAvailable);
        $sql="SELECT ".self::canonicalSql('bc.claim')." AS code, bc.state AS state, CASE WHEN NOT $claimCurrent THEN 'historical' WHEN $fresh='missing' THEN 'unknown' ELSE 'current' END AS freshness, 'claim' AS origin FROM finding_claim bc WHERE bc.finding_id=$alias.id".($history?'':" AND $claimCurrent");
        if ($reportsAvailable) {
            foreach ([['$.components','json_extract(bj.value,\'$.code\')',"COALESCE(json_extract(bj.value,'$.certainty'),'observed')"],['$.tags','bj.value',null],['$.caseSummary.hints','bj.value',null]] as [$path,$code,$state]) {
                $state ??= "CASE WHEN $code IN ('sql_input_structure_indication','sqli_suspected','lfi_suspected','rfi_suspected') THEN 'suspected' WHEN $code IN ('xss_execution_confirmed','xss_confirmed','execution_observed') THEN 'confirmed' ELSE 'observed' END";
                $state="CASE WHEN $code LIKE 'claim:%' THEN COALESCE((SELECT hint_state.state FROM finding_claim hint_state WHERE hint_state.finding_id=$alias.id AND hint_state.claim=substr($code,7)),$state) ELSE $state END";
                $hintCurrent="($code NOT LIKE 'claim:%' OR NOT EXISTS (SELECT 1 FROM finding_claim hint_claim WHERE hint_claim.finding_id=$alias.id AND hint_claim.claim=substr($code,7) AND NOT ".self::claimCurrentSql('hint_claim',$alias,$reportsAvailable,$observationsAvailable).'))';
                $sql.=' UNION ALL SELECT '.self::canonicalSql($code)." AS code, $state AS state, CASE WHEN rp.is_current=1 AND $hintCurrent THEN 'current' ELSE 'historical' END AS freshness, 'report' AS origin FROM finding_report_current rp JOIN json_each(rp.snapshot_json,'$path') bj WHERE rp.finding_id=$alias.id".($history?'':" AND rp.is_current=1 AND $hintCurrent");
            }
            $sql.=" UNION ALL SELECT 'waf_block','observed',CASE WHEN rp.is_current=1 THEN 'current' ELSE 'historical' END,'report' FROM finding_report_current rp WHERE rp.finding_id=$alias.id AND EXISTS (SELECT 1 FROM json_each(COALESCE(json_extract(rp.snapshot_json,'$.wafReview.pending'),json_extract(rp.snapshot_json,'$.wafBlockReview.pending'),'{}')))".($history?'':' AND rp.is_current=1');
        }
        if ($history && $historyAvailable) {
            foreach ([['$.components',"json_extract(bj.value,'$.code')","COALESCE(json_extract(bj.value,'$.certainty'),'observed')"],['$.tags','bj.value',"'observed'"],['$.caseSummary.hints','bj.value',"'observed'"]] as [$path,$code,$state]) {
                $sql.=' UNION ALL SELECT '.self::canonicalSql($code)." AS code, $state AS state, 'historical' AS freshness, 'report_history' AS origin FROM finding_report_revision hr JOIN json_each(hr.snapshot_json,'$path') bj WHERE hr.finding_id=$alias.id";
            }
        }
        if ($history && $claimHistoryAvailable) {
            $sql.=' UNION ALL SELECT '.self::canonicalSql('hc.claim')." AS code, hc.state AS state, 'historical' AS freshness, 'claim_history' AS origin FROM finding_claim_revision hc WHERE hc.finding_id=$alias.id";
        }
        return $sql;
    }
    public static function additionalCount(string $alias='f',bool $reportsAvailable=true,bool $observationsAvailable=true): string
    {
        $codes=implode(',',array_map(static fn(string $c):string=>"'$c'",Catalog::additionalCodes()));
        return '(SELECT COUNT(DISTINCT cb.code) FROM ('.self::badgeRows($alias,$reportsAvailable,false,false,false,$observationsAvailable).") cb WHERE cb.code IN ($codes))";
    }
    public static function hasExtras(string $alias='f',bool $reportsAvailable=true,bool $observationsAvailable=true): string { return '('.self::additionalCount($alias,$reportsAvailable,$observationsAvailable).'>0)'; }
    /** Semantic first import is unknown; only a comparison with a prior revision supplies time. */
    public static function changedAt(string $alias='f',bool $reportsAvailable=true,bool $historyAvailable=true,bool $claimHistoryAvailable=false): string
    {
        $alias=self::alias($alias);
        // Sorted sets match RecheckTransitionService::reportMeaning, omitting IDs/pack versions.
        $meaning=static function(string $r):string {
            $parts=[];
            foreach (['$.tags'=>"value",'$.caseSummary.hints'=>"value",'$.components'=>"json_extract(value,'$.code') || ':' || COALESCE(json_extract(value,'$.certainty'),'observed')"] as $path=>$value) $parts[]="COALESCE((SELECT json_group_array(v) FROM (SELECT DISTINCT $value AS v FROM json_each($r.snapshot_json,'$path') ORDER BY v)),'[]')";
            return implode(" || '|' || ",$parts);
        };
        $report=(!$reportsAvailable || !$historyAvailable)?'NULL':"(SELECT MAX(json_extract(cr.snapshot_json,'$.decidedAt')) FROM finding_report_revision cr JOIN finding_report_revision pr ON pr.finding_id=cr.finding_id AND pr.revision=(SELECT MAX(p2.revision) FROM finding_report_revision p2 WHERE p2.finding_id=cr.finding_id AND p2.revision<cr.revision) WHERE cr.finding_id=$alias.id AND (".$meaning('cr').')<>('.$meaning('pr').') AND EXISTS (SELECT 1 FROM finding_report_current rp WHERE rp.finding_id='.$alias.'.id AND rp.is_current=1))';
        if (!$claimHistoryAvailable) return $report;
        $claim="(SELECT MAX(hc.created_at) FROM finding_claim_revision hc JOIN finding_claim_revision pc ON pc.finding_id=hc.finding_id AND pc.claim=hc.claim AND pc.revision=(SELECT MAX(c2.revision) FROM finding_claim_revision c2 WHERE c2.finding_id=hc.finding_id AND c2.claim=hc.claim AND c2.revision<hc.revision) WHERE hc.finding_id=$alias.id AND (hc.state<>pc.state OR hc.report_selected<>pc.report_selected))";
        return '(SELECT value FROM (SELECT '.$report.' AS value UNION ALL SELECT '.$claim.') WHERE value IS NOT NULL ORDER BY julianday(value) DESC LIMIT 1)';
    }
}
