<?php
namespace App\Tests;

use App\Service\CribrumClaimExport;
use App\Service\CribrumFindingQuery;
use App\Service\CribrumFindingSummary;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class CribrumClaimFreshnessTest extends TestCase
{
    public function testBoundSourceFreshnessIsSharedBySqlAndExportSummary(): void
    {
        $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
        $db->executeStatement('CREATE TABLE finding(id TEXT,poc_revision INTEGER,poc_url TEXT,url TEXT)');
        $db->executeStatement('CREATE TABLE finding_claim(finding_id TEXT,claim TEXT,state TEXT,report_selected INTEGER,severity TEXT,source TEXT,revision INTEGER,observation_id TEXT,updated_at TEXT)');
        $db->executeStatement('CREATE TABLE http_observation(id TEXT,finding_id TEXT,poc_revision INTEGER,metadata TEXT,http_status INTEGER,content_type TEXT,created_at TEXT)');
        $db->executeStatement('CREATE TABLE finding_report_current(finding_id TEXT,is_current INTEGER,snapshot_json TEXT)');
        $fixtures=['bound'=>['classifier',1,1,'https://test/current',1],'old_revision'=>['classifier',1,0,'https://test/current',0],'old_url'=>['classifier',1,1,'https://test/old',0],'stale_report'=>['classifier',0,1,'https://test/current',0],'manual'=>['manual',0,0,'https://test/old',1],'migration'=>['migration',0,null,null,1],'legacy_unknown'=>['classifier',null,null,null,1]];
        foreach ($fixtures as $id=>[$source,$report,$observationRevision,$requestUrl,$expected]) {
            $db->insert('finding',['id'=>$id,'poc_revision'=>1,'poc_url'=>'https://test/current','url'=>'https://test/intake']);
            $db->insert('finding_claim',['finding_id'=>$id,'claim'=>'sql_injection','state'=>'suspected','report_selected'=>1,'source'=>$source,'revision'=>1,'observation_id'=>$observationRevision===null?null:'o-'.$id,'updated_at'=>'2026-10-24']);
            if ($observationRevision!==null) $db->insert('http_observation',['id'=>'o-'.$id,'finding_id'=>$id,'poc_revision'=>$observationRevision,'metadata'=>json_encode(['requestUrl'=>$requestUrl,'observedAt'=>'2020-01-01']),'created_at'=>'2026-10-24']);
            if ($report!==null) $db->insert('finding_report_current',['finding_id'=>$id,'is_current'=>$report,'snapshot_json'=>json_encode(['caseSummary'=>['hints'=>['claim:sql_injection']]])]);
        }
        $claims=CribrumClaimExport::forFindings($db,array_keys($fixtures));
        foreach ($fixtures as $id=>[$source,$report,$observationRevision,$requestUrl,$expected]) {
            $count=(int)$db->fetchOne('SELECT '.CribrumFindingQuery::additionalCount().' FROM finding f WHERE id=?',[$id]);
            $summary=CribrumFindingSummary::derive($report===null?null:['caseSummary'=>['hints'=>['claim:sql_injection']]],$claims[$id],$report===null?null:(bool)$report);
            self::assertSame($expected,$count,$id.' SQL'); self::assertSame($expected,$summary['additionalCount'],$id.' DTO');
            self::assertSame((bool)$expected,$claims[$id][0]['claimCurrent']);
            if (!$expected) self::assertCount(1,$summary['history']);
        }
        self::assertNull($claims['legacy_unknown'][0]['observationCurrent']);
        self::assertFalse($claims['old_url'][0]['observationCurrent']);
        self::assertSame('2020-01-01',$claims['bound'][0]['observation']['capturedAt']);
        $db->executeStatement('DROP TABLE http_observation');
        self::assertNull(CribrumClaimExport::forFindings($db,['legacy_unknown'])['legacy_unknown'][0]['observationCurrent']);
        self::assertSame(1,(int)$db->fetchOne('SELECT '.CribrumFindingQuery::additionalCount('f',true,false)." FROM finding f WHERE id='legacy_unknown'"));
    }
}
