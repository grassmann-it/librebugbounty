<?php

declare(strict_types=1);
namespace App\Service;

use App\Dto\FindingReadView;
use App\Value\CribrumBadgeCatalog as Catalog;

/** Read-only, information-preserving summary. Claims never become mail components here. */
final class CribrumFindingSummary
{
    public static function fromReadView(FindingReadView|array $view,string $locale='de'): array
    {
        $get=static fn(string $key,mixed $default=null):mixed=>is_array($view)?($view[$key] ?? $default):($view->$key ?? $default);
        $snapshot=$get('reportProjection');
        if (!is_array($snapshot) && is_string($get('report_snapshot'))) $snapshot=json_decode($get('report_snapshot'),true);
        $summary=self::derive($snapshot,$get('cribrumClaims',[]),$get('reportProjectionCurrent'),$locale);
        $summary['new']=(bool)array_intersect(['classification_changed','waf_block'],$get('noticeReasons',[]));
        return $summary;
    }
    public static function fromProjection(array $snapshot,?array $claims=null,string $locale='de'): array { return self::derive($snapshot,$claims,null,$locale); }
    public static function derive(?array $snapshot,?array $claims=null,?bool $isCurrent=null,string $locale='de',array $facts=[]): array
    {
        $staleReasons=['basis_changed','source_mismatch','policy_changed','poc_changed','projection_failed'];
        $current=$snapshot!==null && ($isCurrent ?? !array_intersect($staleReasons,$snapshot['reasonCodes'] ?? []));
        $status=$snapshot===null?'missing':($current?'current':'stale');
        $buckets=['badges'=>[],'history'=>[],'shadow'=>[]];
        $add=function(string $raw,string $state,string $origin,array $refs=[],?string $observed=null,?string $recognized=null,bool $selected=false,string $bucket='badges',?string $changed=null) use (&$buckets,$locale,$status):void {
            $entry=Catalog::entry($raw,$locale); $code=$entry['code'];
            if (!$entry['known']) $state='unknown';
            $key=$code; $existing=$buckets[$bucket][$key] ?? null;
            $rank=['unknown'=>0,'observed'=>1,'suspected'=>2,'confirmed'=>3,'stale'=>0,'rejected'=>0,'resolved'=>0];
            if ($existing!==null) {
                $refs=array_values(array_unique([...$existing['evidenceRefs'],...$refs],SORT_REGULAR));
                if (($rank[$existing['state']] ?? 0)>($rank[$state] ?? 0)) $state=$existing['state'];
                $selected=$selected || $existing['reportSelected']; $observed=$existing['observedAt']===null?$observed:($observed===null?$existing['observedAt']:min($existing['observedAt'],$observed));
                $recognized=$recognized ?? $existing['recognizedAt'];
                $origin=implode(', ',array_unique([...explode(', ',$existing['origin']),$origin]));
            }
            $buckets[$bucket][$key]=$entry+['state'=>$state,'certainty'=>$state,'stateLabel'=>Catalog::stateLabel($state,$locale),
                'freshness'=>$bucket==='shadow'?'shadow':($bucket==='history'?'historical':($status==='missing'?'unknown':'current')),
                'origin'=>$origin,'source'=>$origin,'evidenceRefs'=>$refs,'observedAt'=>$observed,'recognizedAt'=>$recognized,'detectedAt'=>$recognized,
                'qualifiedComponent'=>$origin==='report_component' || ($existing['qualifiedComponent'] ?? false),'changedAt'=>$changed,'new'=>false,'reportSelected'=>$selected,'selected'=>$selected,'conflict'=>false,
                'filterUrl'=>'/studio?'.http_build_query(['cribrum'=>[$code]]),'detailAnchor'=>'cribrum-'.preg_replace('/[^a-z0-9_-]/i','-',$code)];
        };
        $observed=$snapshot['observation']['observedAt'] ?? $snapshot['observation']['metadata']['observedAt'] ?? null;
        $recognized=$snapshot['decidedAt'] ?? null;
        $target=$current?'badges':'history';
        foreach ($snapshot['components'] ?? [] as $component) if (is_array($component)) $add((string)($component['code'] ?? ''),(string)($component['certainty'] ?? 'observed'),'report_component',$component['evidenceRefs'] ?? [],$observed,$recognized,true,$target);
        foreach ([...($snapshot['tags'] ?? []),...($snapshot['caseSummary']['hints'] ?? [])] as $code) if (is_string($code)) {
            $state=in_array($code,['sql_input_structure_indication','sqli_suspected','lfi_suspected','rfi_suspected'],true)?'suspected':(in_array($code,['xss_execution_confirmed','xss_confirmed','execution_observed'],true)?'confirmed':'observed');
            $hintTarget=$target;
            if (str_starts_with($code,'claim:')) foreach ($claims ?? $snapshot['claims'] ?? [] as $hintClaim) {
                if (is_array($hintClaim) && ($hintClaim['claim'] ?? null)===substr($code,6)) {
                    $state=(string)($hintClaim['state'] ?? $state);
                    if (($hintClaim['claimCurrent'] ?? true)===false || in_array($state,['rejected','resolved','stale'],true) || (($hintClaim['source'] ?? null)!=='manual' && ($hintClaim['observationCurrent'] ?? true)===false)) $hintTarget='history';
                    break;
                }
            }
            $add($code,$state,'report_observation',[],$observed,$recognized,false,$hintTarget);
        }
        foreach ($facts as $fact) if (is_array($fact) && is_string($fact['code'] ?? null)) {
            $bucket=($fact['mode'] ?? '')==='shadow'?'shadow':$target;
            // Only selected facts are part of the current interpretation.
            if ($bucket!=='shadow' && !in_array($fact['factId'] ?? null,$snapshot['factIds'] ?? [],true)) {
                if (Catalog::entry($fact['code'])['known']) continue;
                $bucket='history';
            }
            $add($fact['code'],in_array($fact['code'],['lfi_suspected','rfi_suspected','sql_input_structure_indication'],true)?'suspected':'observed','behavior_fact',isset($fact['factId'])?[['kind'=>'behavior_fact','id'=>$fact['factId']]]:[],$fact['capturedAt'] ?? null,$recognized,false,$bucket);
        }
        foreach (($claims ?? ($snapshot['claims'] ?? [])) as $claim) {
            if (is_object($claim)) $claim=get_object_vars($claim);
            $code=(string)($claim['claim'] ?? ''); if ($code==='') continue;
            $state=(string)($claim['state'] ?? 'unknown'); $bucket=in_array($state,['rejected','resolved','stale'],true)?'history':'badges';
            if (($claim['claimCurrent'] ?? true)===false || ($claims===null && !$current) || (($claim['source'] ?? null)!=='manual' && ($claim['observationCurrent'] ?? true)===false)) $bucket='history';
            if (($claim['mode'] ?? null)==='shadow') $bucket='shadow';
            $selected=(bool)($claim['reportSelected'] ?? $claim['report_selected'] ?? false);
            $observationId=$claim['observation']['id'] ?? $claim['observationId'] ?? $claim['observation_id'] ?? null;
            $add($code,$state,'claim:'.($claim['source'] ?? 'unknown'),$observationId!==null?[['kind'=>'http_observation','id'=>$observationId]]:[],$claim['observation']['capturedAt'] ?? $claim['capturedAt'] ?? null,$claim['updatedAt'] ?? $claim['updated_at'] ?? null,$selected,$bucket);
            $canonical=Catalog::canonicalCode($code);
            if (($claim['source'] ?? null)==='manual' && $bucket==='history' && isset($buckets[$target][$canonical])) {
                $buckets[$target][$canonical]['conflict']=true; $buckets['history'][$canonical]['conflict']=true;
            }
        }
        foreach ($snapshot['reviewedFactExclusions'] ?? [] as $review) {
            if (!is_array($review) || ($review['decision'] ?? null) !== 'not_applicable' || !is_string($review['code'] ?? null)) continue;
            $refs = is_array($review['evidenceRefs'] ?? null) ? $review['evidenceRefs'] : [];
            if (is_string($review['observationId'] ?? null)) $refs[] = ['kind' => 'http_observation', 'id' => $review['observationId']];
            $add($review['code'], 'rejected', 'manual:fact_review', $refs, $observed, $recognized, false, 'history', $review['reviewedAt'] ?? null);
        }
        foreach ($snapshot['wafReview']['pending'] ?? $snapshot['wafBlockReview']['pending'] ?? [] as $pending) $add('waf_block','observed','waf_review',$pending['evidenceRefs'] ?? [],$pending['observedAt'] ?? null,$recognized,false,$target);
        foreach ($buckets as &$bucket) { uasort($bucket,static fn(array $a,array $b):int=>[$a['rank'],$a['code']]<=>[$b['rank'],$b['code']]); $bucket=array_values($bucket); } unset($bucket);
        $additional=count(array_filter($buckets['badges'],static fn(array $b):bool=>$b['additional']));
        return $buckets+['additionalCount'=>$additional,'hasExtras'=>$additional>0,'changedAt'=>$snapshot['semanticChangedAt'] ?? null,'projectionStatus'=>$status,'freshness'=>$status,'new'=>false];
    }
}
