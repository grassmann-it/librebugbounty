<?php

declare(strict_types=1);
namespace App\Value;

/** Presentation vocabulary only: this never grants report eligibility. */
final class CribrumBadgeCatalog
{
    public const ALIASES = [
        'xss_confirmed'=>'xss','xss_execution_confirmed'=>'xss','execution_observed'=>'xss',
        'sqli_suspected'=>'sql_injection','sql_input_structure_indication'=>'sql_injection',
        'sql_error_observation'=>'sql_error_observed',
        'lfi_suspected'=>'local_file_inclusion','rfi_suspected'=>'remote_file_inclusion',
        'internal_information_exposed'=>'information_disclosure',
    ];
    private const ENTRIES = [
        'xss'=>['XSS','XSS','vulnerability',10,false],
        'sql_injection'=>['SQL-Injection','SQL injection','vulnerability',20,true],
        'local_file_inclusion'=>['Local File Inclusion','Local file inclusion','vulnerability',30,true],
        'remote_file_inclusion'=>['Remote File Inclusion','Remote file inclusion','vulnerability',31,true],
        'information_disclosure'=>['Interne Informationen offengelegt','Internal information exposed','observation',40,true],
        'error_disclosure'=>['Fehlermeldung offengelegt','Error message exposed','observation',41,true],
        'stack_trace'=>['Stacktrace offengelegt','Stack trace exposed','observation',42,true],
        'sql_error_observed'=>['SQL-Fehler','SQL error','observation',43,true],
        'database_runtime_error'=>['Datenbank-Laufzeitfehler','Database runtime error','observation',43,true],
        'input_in_sql_error'=>['Eingabe im SQL-Fehler','Input in SQL error','observation',44,true],
        'xss_payload_in_sql_error'=>['XSS-Payload im SQL-Fehler','XSS payload in SQL error','observation',45,true],
        'file_include_diagnostic'=>['Datei-Include-Diagnose','File include diagnostic','observation',46,true],
        'sensitive_file_content_observed'=>['Sensibler Dateiinhalt','Sensitive file content','observation',47,true],
        'error_response'=>['Fehlerantwort','Error response','observation',48,true],
        'input_reflected'=>['Eingabe reflektiert','Input reflected','observation',49,false],
        'waf_block'=>['WAF-Block','WAF block','observation',50,true],
        'coverage_limited'=>['Prüfung eingeschränkt','Limited coverage','context',80,false],
        'sql_context_required'=>['SQL-Kontext offen','SQL context unresolved','context',81,true],
        'context_required'=>['Kontextprüfung offen','Context review needed','context',82,false],
        'disclosure_basis_incomplete'=>['Disclosure-Beleg unvollständig','Incomplete disclosure evidence','context',83,false],
        'invalid_or_stale_fact'=>['Beleg nicht aktuell','Evidence not current','context',84,false],
        'unknown_fact'=>['Nicht eingeordneter Beleg','Unclassified evidence','context',85,false],
        'product_context'=>['Produktkontext','Product context','context',90,false],
    ];
    public static function canonicalCode(string $code): string
    {
        if (str_starts_with($code,'claim:')) $code=substr($code,6);
        return self::ALIASES[$code] ?? $code;
    }
    public static function entry(string $code,string $locale='de'): array
    {
        $code=self::canonicalCode($code); $row=self::ENTRIES[$code] ?? null;
        return ['id'=>$code,'code'=>$code,'label'=>$row===null ? (($locale==='en'?'Unclassified hint: ':'Nicht eingeordneter Hinweis: ').$code) : $row[$locale==='en'?1:0],
            'group'=>$row[2] ?? 'unknown','rank'=>$row[3] ?? 100,'known'=>$row!==null,'filterable'=>$row!==null,'additional'=>$row[4] ?? false];
    }
    public static function entries(string $locale='de'): array { return array_map(static fn(string $code):array=>self::entry($code,$locale),array_keys(self::ENTRIES)); }
    public static function choices(string $locale='de'): array { return array_column(self::entries($locale),'label','code'); }
    public static function additionalCodes(): array { return array_column(array_filter(self::entries(),static fn(array $e):bool=>$e['additional']),'code'); }
    public static function stateLabel(string $state,string $locale='de'): string
    {
        $labels=['observed'=>['beobachtet','observed'],'suspected'=>['Verdacht','suspected'],'confirmed'=>['bestätigt','confirmed'],'rejected'=>['abgelehnt','rejected'],'resolved'=>['behoben','resolved'],'stale'=>['aktuell nicht belegt','not currently evidenced'],'unknown'=>['nicht eingeordnet','unclassified']];
        return $labels[$state][$locale==='en'?1:0] ?? ($locale==='en'?'unclassified':'nicht eingeordnet');
    }
}
