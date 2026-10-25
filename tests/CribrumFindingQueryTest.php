<?php
namespace App\Tests;

use App\Service\CribrumFindingQuery as Query;
use App\Service\CribrumFindingSummary as Summary;
use PHPUnit\Framework\TestCase;

final class CribrumFindingQueryTest extends TestCase
{
    public function testClaimChangesRequirePriorMeaningAndIgnoreIdenticalRefresh(): void
    {
        $db=new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE finding(id TEXT); CREATE TABLE finding_claim_revision(finding_id TEXT,claim TEXT,revision INTEGER,state TEXT,report_selected INTEGER,created_at TEXT)');
        $db->exec("INSERT INTO finding VALUES('changed'),('first'); INSERT INTO finding_claim_revision VALUES('changed','sql_injection',1,'observed',0,'2020-01-01'),('changed','sql_injection',2,'suspected',0,'2021-01-01'),('changed','sql_injection',3,'suspected',0,'2022-01-01'),('first','sql_injection',1,'suspected',0,'2023-01-01')");
        $rows=$db->query('SELECT f.id,'.Query::changedAt('f',false,false,true).' AS changed FROM finding f')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame('2021-01-01',$rows['changed']); self::assertNull($rows['first']);
    }
    public function testSqlAndSummaryAgreeAcrossDedupMissingStaleAndSemanticRefresh(): void
    {
        $db=new \PDO('sqlite::memory:'); $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE finding(id TEXT,poc_revision INTEGER DEFAULT 1,poc_url TEXT,url TEXT); CREATE TABLE http_observation(id TEXT,finding_id TEXT,poc_revision INTEGER,metadata TEXT); CREATE TABLE finding_claim(finding_id TEXT,claim TEXT,state TEXT,source TEXT DEFAULT \'migration\',observation_id TEXT); CREATE TABLE finding_report_revision(finding_id TEXT,revision INTEGER,snapshot_json TEXT); CREATE TABLE finding_report_current(finding_id TEXT,is_current INTEGER,snapshot_json TEXT)');
        $one=['tags'=>['sql_error_observed','product_context'],'components'=>[['code'=>'sql_error_observation','certainty'=>'observed','evidenceRefs'=>[['id'=>'one']]]],'caseSummary'=>['hints'=>['sql_error_observed']],'decidedAt'=>'2020-01-01'];
        $two=$one; $two['tags'][]='sql_input_structure_indication'; $two['decidedAt']='2021-01-01';
        $three=$two; $three['components'][0]['evidenceRefs']=[['id'=>'different']]; $three['decidedAt']='2022-01-01';
        foreach (['fresh','stale','missing','first'] as $id) $db->prepare('INSERT INTO finding(id) VALUES(?)')->execute([$id]);
        foreach (['fresh'=>[$three,1],'stale'=>[$three,0],'first'=>[$one,1]] as $id=>[$snapshot,$current]) $db->prepare('INSERT INTO finding_report_current VALUES(?,?,?)')->execute([$id,$current,json_encode($snapshot)]);
        foreach ([1=>$one,2=>$two,3=>$three] as $revision=>$snapshot) $db->prepare('INSERT INTO finding_report_revision VALUES(?,?,?)')->execute(['fresh',$revision,json_encode($snapshot)]);
        $db->prepare('INSERT INTO finding_report_revision VALUES(?,?,?)')->execute(['first',1,json_encode($one)]);
        $db->exec("INSERT INTO finding_claim(finding_id,claim,state) VALUES('fresh','sql_injection','suspected'),('missing','local_file_inclusion','suspected'),('stale','remote_file_inclusion','resolved')");
        $rows=$db->query('SELECT f.id,'.Query::additionalCount().' AS extras,'.Query::changedAt().' AS changed,'.Query::freshness().' AS freshness FROM finding f ORDER BY f.id')->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        self::assertSame(2,(int)$rows['fresh']['extras']); self::assertSame('2021-01-01',$rows['fresh']['changed']);
        self::assertSame(1,(int)$rows['missing']['extras']); self::assertSame('missing',$rows['missing']['freshness']);
        self::assertSame(0,(int)$rows['stale']['extras']); self::assertNull($rows['stale']['changed']); self::assertNull($rows['first']['changed']);
        self::assertSame(Summary::derive($three,[['claim'=>'sql_injection','state'=>'suspected']])['additionalCount'],(int)$rows['fresh']['extras']);
        self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM finding f WHERE f.id='stale' AND EXISTS (SELECT 1 FROM (".Query::badgeRows('f',true,true,true).") cb WHERE cb.code='remote_file_inclusion')")->fetchColumn());
    }
}
