<?php

namespace App\Cribrum\Detector;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;

/** Bounded, claim-free observations. Product names alone never establish a leak or block. */
final class ContextTriageDetector
{
    public const ID = 'context-triage-v1';
    private const MAX_MATCHES = 12;

    // Evaluation state belongs to one synchronous invocation and is discarded on exit.
    private ?DetectorRun $run = null;
    private ?string $representation = null;
    private string $family = 'disclosure';
    private string $stage = 'evaluation';

    public function detect(ObservationInput $input, string $mode = 'shadow'): array
    {
        return $this->evaluate($input, $mode)->signals;
    }

    public function evaluate(ObservationInput $input, string $mode = 'shadow'): DetectorEvaluation
    {
        $this->run = $run = new DetectorRun($input, self::ID, ['disclosure', 'error_response', 'waf']);
        $raw = $input->rawHtml ?? $input->domText;
        $this->representation = $input->rawHtml !== null ? 'raw' : 'dom_text';
        try {
            if ($raw === null) {
                foreach (['disclosure', 'error_response', 'waf'] as $family) $run->unavailable($family, 'representation', $this->representation, 'representation_missing');
                return $run->finish();
            }
            if (!mb_check_encoding($raw, 'UTF-8')) {
                foreach (['disclosure', 'error_response', 'waf'] as $family) $run->unavailable($family, 'representation', $this->representation, 'representation_invalid');
                return $run->finish();
            }
            $this->evaluateBody($input, $raw, $mode);
            return $run->finish();
        } finally {
            $this->run = null;
            $this->representation = null;
        }
    }

    /** Diagnose delivered runtime and internal details without query or product scans. */
    public function evaluateDiagnostics(ObservationInput $input, string $mode = 'shadow'): DetectorEvaluation
    {
        $this->run = $run = new DetectorRun($input, self::ID, ['disclosure', 'error_response']);
        $raw = $input->rawHtml ?? $input->domText;
        $this->representation = $input->rawHtml !== null ? 'raw' : 'dom_text';
        try {
            if ($raw === null || !mb_check_encoding($raw, 'UTF-8')) {
                foreach (['disclosure', 'error_response'] as $family) $run->unavailable($family, 'representation', $this->representation, $raw === null ? 'representation_missing' : 'representation_invalid');
                return $run->finish();
            }
            $this->evaluateBody($input, $raw, $mode, true);
            return $run->finish();
        } finally {
            $this->run = null;
            $this->representation = null;
        }
    }

    private function evaluateBody(ObservationInput $input, string $raw, string $mode, bool $diagnosticsOnly = false): void
    {
        $run = $this->run;
        $representation = $this->representation;
        $emit = function (string $code, array $match, array $attributes) use ($run, $raw, $input, $mode, $representation, $diagnosticsOnly): void {
            if ($code === 'internal_information_exposed' && (self::inDiagnosticExample($raw, is_string($match[0]) ? $match[1] : $match[0][1]) || ($diagnosticsOnly && !$this->diagnosticEligible($raw, is_string($match[0]) ? $match[1] : $match[0][1])))) { $this->excluded(); return; }
            $spans = [];
            foreach (is_string($match[0]) ? [$match] : $match as $part) {
                $start = mb_strlen(substr($raw, 0, $part[1]), 'UTF-8');
                $span = EvidenceSignal::span($raw, $start, mb_strlen($part[0], 'UTF-8'));
                $span['representation'] = $representation;
                $spans[] = $span;
            }
            $run->add(EvidenceSignal::make($code, $input, $spans, $attributes, [], 'context-triage-v1', $mode));
        };
        $find = function (string $pattern) use ($raw): iterable {
            $offset = 0;
            $count = 0;
            while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $this->candidate($raw, $matches[0]);
                ++$count;
                $offset = $matches[0][1] + max(1, strlen($matches[0][0]));
                yield $matches[0];
            }
            $this->matchBudget($count, $offset, $raw);
        };

        // Actual source locations and call frames, including HTML/JSON-embedded diagnostics.
        // No HTML/XML parser, external entities, recursive JSON search, or unbounded .* patterns.
        $patterns = [
            'stack_trace' => [
                '~\#\d{1,5}\s+(?:[A-Za-z]:[\\\\/]|(?:\\\\?/)(?:var|home|homepages|customers|srv|opt|usr|app|www|mnt|data|Users|Applications|private|tmp|is|hp|html)(?:\\\\?/))[^\r\n<>]{1,350}?\(\d{1,8}\)\s*:\s*[^\r\n<>]{1,180}~i',
                '~\bat\s+[\w$]+(?:[.$][\w$<>]+)+\s*\([\w.$ -]{1,100}\.(?:java|cfm|cfc):\d{1,8}\)~i',
                '~\b(?:at|bei|à|en|in)\s+[\w.]+\([^\r\n<>]{0,180}\)\s+(?:in|dans|en)\s+(?:[A-Za-z]:[\\\\/]|/)[^\r\n<>]{1,280}?\.(?:cs|vb):\s*(?:(?:line|Zeile|ligne|línea)\s*)?\d{1,8}~iu',
                '~<tr\b[^>]{0,200}>\s*<td\b[^>]{0,100}>\s*\#?\d{1,5}\s*</td>[\s\S]{0,250}?<td\b[^>]{0,100}>\s*(?:[A-Za-z]:[\\\\/]|/(?:var|home|srv|app|www|opt)/)[^<>]{1,280}\.(?:php|cs|java)(?::|\()\d{1,8}\)?\s*</td>[\s\S]{0,250}?</tr>~i',
                '~<(?:frame|call)\b[^>]{0,100}>\s*<file>\s*(?:[A-Za-z]:[\\\\/]|/(?:var|home|srv|app|www|opt)/)[^<>]{1,280}</file>\s*<line>\d{1,8}</line>\s*<(?:function|method)>[^<>]{1,180}</(?:function|method)>\s*</(?:frame|call)>~i',
            ],
            'internal_path' => [
                '~(?:<b>\s*)?(?:Warning|Notice|Fatal\s+error|Parse\s+error)(?:\s*</b>)?\s*:\s*(?:Undefined (?:index|array key|variable)|Trying to access array offset|file_put_contents\(|mysqli_connect\()[^\r\n]{0,600}?\bin\s+(?:<b>\s*)?(?:[A-Za-z]:[\\/]|/(?:var|container|home|homepages|customers|srv|opt|usr|app|www|mnt|data|Users|Applications|private|tmp|is|hp|html)/)[^<>\r\n]{1,300}?(?:\s*</b>)?\s+on\s+line\s+(?:<b>\s*)?[1-9]\d{0,7}(?:\s*</b>)?~i',
                // WordPress PHP diagnostics may be appended to unfinished HTML. Match a
                // bounded error object, preserving raw JSON escapes and byte provenance.
                <<<'REGEX'
~"error"\s*:\s*\{\s*"type"\s*:\s*(?:1|2|4|8|64)\s*,\s*"message"\s*:\s*"(?&jsonChar){1,1000}"\s*,\s*"file"\s*:\s*"(?:\\?/)(?:var|home|homepages|customers|srv|opt|usr|app|www|mnt|data|Users|Applications|private|tmp|is|hp|html)(?:\\?/)(?&jsonChar){1,500}?\.php"\s*,\s*"line"\s*:\s*[1-9]\d{0,7}\s*\}(?(DEFINE)(?<jsonChar>[^"\\\r\n]|\\.))~i
REGEX,
                '~(?:Fatal\s+error|Warning|Notice|Parse\s+error|Uncaught\s+[\w\\\\]+(?:Exception|Error))[^\r\n]{0,600}?\bin\s+(?:<b>)?(?:[A-Za-z]:[\\\\/]|(?:\\\\?/)(?:var|home|homepages|customers|srv|opt|usr|app|www|mnt|data|Users|Applications|private|tmp|is|hp)(?:\\\\?/))[^<>\r\n]{1,300}?(?:</b>)?\s+(?:on\s+line|at\s+line)\s*(?:<b>)?\d{1,8}~i',
                '~(?:Source\s+File|Quelldatei|Fichier\s+source)\s*:\s*(?:<[^>]{1,80}>\s*){0,3}(?:[A-Za-z]:[\\\\/]|/(?:var|home|srv|app|www|opt)/)[^<>\r\n]{1,280}?(?:\s|<[^>]{1,80}>){1,8}(?:Line|Zeile|Ligne)\s*:\s*\d{1,8}~iu',
            ],
            'source_location' => [
                '~(?:Microsoft\s+(?:VBScript|JET|OLE\s+DB)|Active\s+Server\s+Pages)[^<>\r\n]{0,220}?(?:error|Fehler)[\s\S]{0,350}?/(?:[\w.-]+/){0,12}[\w.-]+\.asp\s*,\s*(?:line|Zeile)\s+\d{1,8}~i',
            ],
        ];
        $this->family = 'disclosure';
        $seen = [];
        foreach ($patterns as $kind => $family) {
            foreach ($family as $index => $pattern) {
                $this->stage('disclosure', 'signature.'.$kind.'.'.$index, $representation, function () use ($find, $pattern, $kind, $index, &$seen, $emit, $raw, $input): void {
                    foreach ($find($pattern) as $match) {
                        $key = $kind.':'.$match[1];
                        if ($kind === 'internal_path' && $index === 0 && (!$this->diagnosticEligible($raw, $match[1]) || self::inDiagnosticExample($raw, $match[1]))) { $this->excluded(); continue; }
                        if (isset($seen[$key])) { $this->excluded(); continue; }
                        $seen[$key] = true;
                        if ($kind === 'source_location') {
                            if (!$this->diagnosticEligible($raw, $match[1])) { $this->excluded(); continue; }
                            $emit('error_response', $match, ['source' => 'response_body', 'httpStatus' => $input->httpStatus, 'runtimeDetails' => ['family' => 'classic_asp', 'diagnosticKind' => 'runtime_error', 'contextKind' => 'source_location']]);
                            continue;
                        }
                        $emit('internal_information_exposed', $match, ['detailKind' => $kind, 'concreteInternals' => true, 'source' => 'response_body']);
                    }
                });
            }
        }
        foreach (['concreteDetails', 'additionalInternals', 'structuredPhpFrames', 'sourceBlocks', 'backendSourceWindows', 'jspSourceWindows', 'structuredScriptFrames'] as $helper) {
            $this->stage('disclosure', $helper, $representation, function () use ($helper, $raw, $emit): void {
                foreach ($this->$helper($raw) as [$kind, $match]) {
                    $emit('internal_information_exposed', $match, ['detailKind' => $kind, 'concreteInternals' => true, 'source' => 'response_body']);
                }
            });
        }
        if (!$diagnosticsOnly) {
        $this->stage('disclosure', 'signature.query.0', $representation, function () use ($input, $mode, $run): void {
            $this->adoptEvaluation((new SqlTriageDetector())->evaluateQueryDisclosures($input, $mode), 'disclosure', 'query_disclosure');
        });
        }
        $this->family = 'error_response';
        if (!$diagnosticsOnly) {
        $this->stage('error_response', 'signature', $representation, function () use ($find, $emit, $input): void {
            $first = true;
            foreach ($find('~(?:Fatal\s+error|Parse\s+error|Uncaught\s+[\w\\\\]+(?:Exception|Error)|internal_server_error|Internal\s+Server\s+Error|Unable\s+to\s+load\s+renderer\s+class|Server\s+Error\s+in\s+[^<>\r\n]{1,100}\s+Application|HTTP\s+(?:403|404|500|502|503|504)|(?:403\s+Forbidden|404\s+Not\s+Found|502\s+Bad\s+Gateway|503\s+Service\s+Unavailable|504\s+Gateway\s+Time-?out)|<(?:title|h1|h2)\b[^>]{0,100}>\s*(?:Bad\s+Gateway|Gateway\s+Time-?out|Service\s+Unavailable|Forbidden|Not\s+Found|Server\s+Error)\b|Error\s+(?:503|Guru\s+Meditation))~i') as $match) {
                if ($first) $emit('error_response', $match, ['source' => 'response_body', 'httpStatus' => $input->httpStatus]);
                $first = false;
            }
        });
        }
        $this->stage('error_response', 'runtimeDetails', $representation, function () use ($raw, $emit, $input): void {
            foreach ($this->runtimeDetails($raw) as [$match, $details]) {
                $emit('error_response', $match, ['source' => 'response_body', 'httpStatus' => $input->httpStatus, 'runtimeDetails' => $details]);
            }
        });
        $this->stage('error_response', 'runtimeFamilies', $representation, function () use ($input, $mode): void {
            $this->adoptEvaluation((new RuntimeDiagnosticFamilies())->evaluate($input, $mode), 'error_response', 'runtime_family');
        });
        if ($diagnosticsOnly) return;
        $this->family = 'waf';

        $this->stage('waf', 'diagnosticCatalogs', $representation, function () use ($raw, $emit): void {
            foreach ($this->diagnosticCatalogs($raw) as [$match, $attrs]) $emit('product_context', $match, $attrs);
        });
        $vendors = [
            'cloudflare' => '~Cloudflare|cf-chl-|/cdn-cgi/challenge-platform/~i',
            'sucuri' => '~Sucuri(?:\s+Website\s+Firewall)?~i',
            'imperva' => '~Imperva|Incapsula~i',
            'akamai' => '~Akamai~i',
            'aws_waf' => '~AWS\s+WAF~i',
            'cloudfront' => '~CloudFront~i',
            'safeline' => '~\bSafeLine\b~i',
            'go_away' => '~\bgo-away\b~i',
            'admin_tools' => '~\bAdmin\s+Tools\b~i',
            'wordfence' => '~\bWordfence\b|/wp-content/plugins/wordfence/~i',
            'modsecurity' => '~\bMod[_ -]?Security\b~i',
            'ninjafirewall' => '~\bNinjaFirewall\b~i',
            'checkpoint' => '~\bCheck\s+Point\b~i',
            'azure_waf' => '~\bAzure\s+WAF\b~i',
            'secupress' => '~\bSecuPress\b~i',
            // Body signatures only; no inferred server headers or security impact.
            'iis' => '~\b(?:Microsoft-IIS(?:/[\d.]+)?|IIS\s+(?:Windows\s+Server|Detailed\s+Error|[\d.]+)|Internet\s+Information\s+Services)\b~i',
            'apache' => '~\bApache(?:/[\d.]+|\s+HTTP\s+Server)?\b~i',
            'nginx' => '~\bnginx(?:/[\d.]+)?\b~i',
            'varnish' => '~\bVarnish(?:\s+cache\s+server)?\b~i',
            'squid' => '~\bSquid(?:/[\d.]+|\s+Proxy)?\b~i',
            'haproxy' => '~\bHAProxy\b~i',
            'envoy' => '~\benvoy\b~i',
        ];
        foreach ($vendors as $vendor => $pattern) {
            $this->stage('waf', 'presence.'.$vendor, $representation, function () use ($find, $pattern, $emit, $vendor): void {
                $first = true;
                foreach ($find($pattern) as $match) {
                    if ($first) $emit('product_context', $match, ['vendor' => $vendor, 'source' => 'response_body', 'blockEstablished' => false]);
                    $first = false;
                }
            });
        }
        $blocks = [
            ['cloudflare', 'waf_block', '~Sorry,\s+you\s+have\s+been\s+blocked[\s\S]{0,2000}?Cloudflare|Cloudflare[\s\S]{0,500}?(?:Error\s+1020|Access\s+denied)|Error\s+1020[\s\S]{0,500}?Cloudflare~i'],
            ['cloudflare', 'waf_challenge', '~(?:Just\s+a\s+moment|Checking\s+your\s+browser|Verify\s+you\s+are\s+human)[\s\S]{0,3000}?(?:cf-chl-|/cdn-cgi/challenge-platform/|Cloudflare)|(?:cf-chl-|/cdn-cgi/challenge-platform/)[\s\S]{0,1000}?(?:Verify\s+you\s+are\s+human|Checking\s+your\s+browser)~i'],
            ['sucuri', 'waf_block', '~Access\s+Denied\s*[-–:]?\s*Sucuri\s+Website\s+Firewall|Sucuri\s+WebSite\s+Firewall\s*[-–:]?\s*Access\s+Denied~iu'],
            ['imperva', 'waf_block', '~(?:Imperva|Incapsula)[\s\S]{0,700}?(?:Request\s+unsuccessful|Access\s+denied|incident\s+ID)|Request\s+unsuccessful[\s\S]{0,700}?(?:Imperva|Incapsula)~i'],
            ['akamai', 'waf_block', '~Akamai[\s\S]{0,700}?Access\s+Denied[\s\S]{0,700}?Reference\s*#[\w.]+|Access\s+Denied[\s\S]{0,700}?Reference\s*#[\w.]+[\s\S]{0,700}?Akamai~i'],
            ['aws_waf', 'waf_block', '~(?:AWS\s+WAF[\s\S]{0,400}?(?:request\s+(?:was\s+)?blocked|access\s+denied)|request\s+(?:was\s+)?blocked[\s\S]{0,400}?AWS\s+WAF)~i'],
            ['safeline', 'waf_block', '~(?:SafeLine[\s\S]{0,500}?(?:(?:request|access|you)\s+(?:(?:was|has\s+been|have\s+been|is)\s+)?blocked|access\s+denied)|(?:(?:request|access|you)\s+(?:(?:was|has\s+been|have\s+been|is)\s+)?blocked|access\s+denied)[\s\S]{0,500}?SafeLine)~i'],
            ['wordfence', 'waf_block', '~(?:Your\s+access\s+to\s+this\s+site\s+has\s+been\s+limited|Your\s+access\s+to\s+this\s+service\s+has\s+been\s+limited|A\s+potentially\s+unsafe\s+operation\s+has\s+been\s+detected)[\s\S]{0,3000}?Wordfence|Wordfence[\s\S]{0,500}?(?:has\s+blocked\s+your\s+request|request\s+(?:was\s+)?blocked)~i'],
            ['modsecurity', 'waf_block', '~(?:Mod[_ -]?Security[\s\S]{0,500}?(?:Access\s+denied|request\s+(?:was\s+)?(?:blocked|rejected)|has\s+(?:blocked|rejected)\s+(?:this|your)\s+request)|(?:Access\s+denied|request\s+(?:was\s+)?(?:blocked|rejected)|Not\s+Acceptable)[\s\S]{0,500}?Mod[_ -]?Security)~i'],
            ['ninjafirewall', 'waf_block', '~(?:NinjaFirewall[\s\S]{0,500}?(?:has\s+blocked\s+(?:your|this)\s+request|request\s+(?:was\s+)?blocked|Access\s+denied|your\s+request\s+cannot\s+be\s+processed\.\s*(?:<br\s*/?>\s*)?For\s+security\s+reasons,\s+it\s+was\s+blocked\s+and\s+logged\.)|(?:request\s+(?:was\s+)?blocked|Access\s+denied|your\s+request\s+cannot\s+be\s+processed\.\s*(?:<br\s*/?>\s*)?For\s+security\s+reasons,\s+it\s+was\s+blocked\s+and\s+logged\.)[\s\S]{0,500}?NinjaFirewall)~i'],
            ['checkpoint', 'waf_block', "~Check\\s+Point's\\s+(?:<b>\\s*)?Application\\s+Security(?:\\s*</b>)?\\s+has\\s+detected\\s+an\\s+attack\\s+and\\s+blocked\\s+it\\.~i"],
            ['azure_waf', 'waf_challenge', <<<'REGEX'
~Azure\s+WAF[\s\S]{0,10000}?window\.challengeType\s*=\s*[`"']jsc[`"']\s*;[\s\S]{0,3000}?window\.specialRoute\s*=\s*[`"']appgwazwaf[`"']\s*;[\s\S]{0,500}?/appgwazwaf/jsc/assets/challenge[\w.-]*\.js~i
REGEX],
            ['secupress', 'waf_block', <<<'REGEX'
~<h1>SecuPress<\\?/h1>\s*<h2>403\s+Forbidden<\\?/h2>[\s\S]{0,800}?User-Agents\s+interdits~i
REGEX],
            ['unknown', 'waf_block', '~The\\s+Website\\s+Application\\s+Firewall\\s+protecting\\s+your\\s+site\\s+blocked\\s+this\\s+request\\s+as\\s+unwanted\\.~i'],
            ['unknown', 'waf_block', '~(?:request|access)\s+(?:was\s+|has\s+been\s+)?blocked\s+by\s+(?:the\s+|a\s+)?(?:web\s+application\s+firewall|WAF)~i'],
        ];
        foreach ($blocks as $index => [$vendor, $reason, $pattern]) {
            $this->stage('waf', 'block.'.$vendor.'.'.$index, $representation, function () use ($find, $pattern, $raw, $vendor, $reason, $emit): void {
                $emitted = false;
                foreach ($find($pattern) as $match) {
                    if ($vendor === 'unknown' && !$this->diagnosticEligible($raw, $match[1])) { $this->excluded(); continue; }
                    if (in_array($vendor, ['checkpoint', 'azure_waf', 'secupress'], true) && $this->inCodeExample($raw, $match[1])) { $this->excluded(); continue; }
                    if ($vendor === 'safeline' && ($this->inInactiveWafText($raw, $match[1]) || CheckedRegex::match('~</?(?:script|style|template|textarea|noscript)\b|<!--~i', $match[0]) === 1)) { $this->excluded(); continue; }
                    if (!$emitted) $emit('coverage_limited', $match, ['reason' => $reason, 'vendor' => $vendor, 'wafBlock' => true, 'blockEvidence' => 'explicit_response_body', 'source' => 'response_body', 'headersAvailable' => false]);
                    $emitted = true;
                }
            });
        }
        $this->stage('waf', 'additionalWafContexts', $representation, function () use ($raw, $emit): void {
            foreach ($this->additionalWafContexts($raw) as [$vendor, $reason, $match]) {
                $emit('coverage_limited', $match, ['reason' => $reason, 'vendor' => $vendor, 'wafBlock' => true, 'blockEvidence' => 'explicit_response_body', 'source' => 'response_body', 'headersAvailable' => false]);
            }
        });
    }

    /** Coherent delivered frames; source snippets use a separate, stricter guard. */
    private function additionalInternals(string $raw): iterable
    {
        $separator = '(?:[\t\r\n ]|<br\\s*/?>|</?li\\b[^>]{0,100}>){1,180}';
        $javaFrame = '(?:[A-Za-z][\\w.-]{0,100}(?:/[\\w.+-]{1,40})?/)?[A-Za-z_$][\\w$]*(?:[.$][\\w$<>]+){2,}\\((?:[\\w$.-]{1,100}\\.java:[1-9]\\d{0,7}|Unknown Source|Native Method)\\)';
        $netFrame = '(?:(?:at|bei|à|en|v)\\s+)?[A-Za-z_][\\w]*+(?:\\.[A-Za-z_][\\w]*+){1,14}\\([^\\r\\n<>]{0,220}\\)(?:[ \\t]+\\+\\d{1,10})?';
        $netStart = '(?:(?=(?:at|bei|à|en|v)\\s)|(?<![\\w])[^\\WA-Za-z_]*+\\K)';
        $patterns = [
            ['java_paragraph', '~<p\\b[^>]{0,120}>\\s*(?&frame)\\s*</p>(?:\\s*<p\\b[^>]{0,120}>\\s*(?&frame)\\s*</p>){1,11}(?(DEFINE)(?<frame>'.$javaFrame.'))~u'],
            ['java', '~(?<![\\w$./])(?&frame)(?:(?&separator)(?&frame)){1,11}(?(DEFINE)(?<frame>'.$javaFrame.')(?<separator>'.$separator.'))~u'],
            // Try only each word's first supported identifier or a native frame
            // prefix. Keep the old span after digit/Unicode prefixes via \K;
            // retrying every word suffix made long response bodies quadratic.
            ['net', '~'.$netStart.'(?&frame)(?:(?&separator)(?&frame)){1,11}(?(DEFINE)(?<frame>'.$netFrame.')(?<separator>'.$separator.'))~u'],
            ['iphp', <<<'REGEX'
~(?<number>[1-9]\d{0,3})\.\s*(?:<b>)?[A-Za-z_][\w]*(?:(?:::|->)[A-Za-z_][\w]*)?\([^<>\r\n]{0,160}\)(?:</b>)?\s+in\s+(?:<b>)?iCMS://[\w./-]{1,260}\.php(?:</b>)?\s+on\s+line\s+(?:<b>)?[1-9]\d{0,7}(?:</b>)?(?:\s|<br\s*/?>){1,100}(?<next>[1-9]\d{0,3})\.\s*(?:<b>)?[A-Za-z_][\w]*(?:(?:::|->)[A-Za-z_][\w]*)?\([^<>\r\n]{0,160}\)(?:</b>)?\s+in\s+(?:<b>)?iCMS://[\w./-]{1,260}\.php(?:</b>)?\s+on\s+line\s+(?:<b>)?[1-9]\d{0,7}(?:</b>)?~
REGEX],
            ['yii', <<<'REGEX'
~<div\b[^>]{0,160}\bclass=["']element["'][^>]{0,160}>\s*<span\b[^>]{0,100}\bclass=["']item-number["'][^>]{0,100}>[1-9]\d{0,3}\.?</span>\s*<span\b[^>]{0,100}\bclass=["']text["'][^>]{0,100}>\s*in\s+(?:/(?:var|home|homepages|customers|srv|opt|usr|app|www|mnt|data|Users|Applications|private|tmp|is|hp)/|[A-Za-z]:[\\/])[^<>\r\n]{1,350}\.php</span>\s*<span\b[^>]{0,100}\bclass=["']at["'][^>]{0,100}>\s*at\s+line\s*<span\b[^>]{0,100}\bclass=["']line["'][^>]{0,100}>[1-9]\d{0,7}</span>\s*</span>\s*<span\b[^>]{0,100}\bclass=["']call["'][^>]{0,100}>(?:(?!</div>)[\s\S]){0,1800}?</span>\s*</div>~i
REGEX],
        ];
        foreach ($patterns as [$family, $pattern]) {
            $offset = 0;
            $count = 0;
            while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $this->candidate($raw, $m[0]);
                $offset = $m[0][1] + strlen($m[0][0]);
                if (in_array($family, ['java', 'java_paragraph'], true) && CheckedRegex::match('~\\.java:[1-9]\\d{0,7}~', $m[0][0]) !== 1) { $this->excluded(); continue; }
                if (in_array($family, ['java', 'java_paragraph'], true) && !$this->diagnosticEligible($raw, $m[0][1])) { $this->excluded(); continue; }
                if ($family === 'net' && (CheckedRegex::match('~\\b(?:System|Microsoft|ASP)\\.~', $m[0][0]) !== 1
                    || CheckedRegex::match('~(?:\\b(?:at|bei|à|en|v)\\s+|\\)\\s+\\+\\d)~u', $m[0][0]) !== 1)) { $this->excluded(); continue; }
                if ($family === 'iphp' && (int) $m['next'][0] !== (int) $m['number'][0] + 1) { $this->excluded(); continue; }
                if ($family === 'yii' && CheckedRegex::match('~(?:[A-Za-z_][\\w]*\\\\)+[A-Za-z_][\\w]*(?:::|-&gt;|->)[A-Za-z_][\\w]*(?:</a>)?\\s*\\(~', $m[0][0]) !== 1) { $this->excluded(); continue; }
                yield ['stack_trace', $m[0]];
                ++$count;
            }
            $this->matchBudget($count, $offset, $raw);
        }
        $phpHeaders = [];
        $previousEnd = null;
        foreach ($this->phpDebugFrames($raw) as [$header, $source]) {
            if ($previousEnd !== null && $header[1] - $previousEnd > 4000) {
                if (count($phpHeaders) >= 2) yield ['stack_trace', $phpHeaders];
                $phpHeaders = [];
            }
            $phpHeaders[] = $header;
            $previousEnd = $source[1] + strlen($source[0]);
            if (count($phpHeaders) === 12) { yield ['stack_trace', $phpHeaders]; $phpHeaders = []; }
        }
        if (count($phpHeaders) >= 2) yield ['stack_trace', $phpHeaders];
        // A populated IIS detail row, bound to its own Detailed Error page. A
        // requested URL, banner or a prose mention of Physical Path is insufficient.
        $iisMarkerEnd = $this->iisDetailMarkerEnd($raw);
        if ($iisMarkerEnd !== null) {
            $pattern = '~<tr\\b[^>]{0,160}>\\s*<th\\b[^>]{0,100}>\\s*Physical\\s+Path\\s*</th>\\s*<td\\b[^>]{0,100}>(?:\\s|&nbsp;|&#0*160;){0,80}(?:[A-Za-z]:[\\\\/]|/(?:var|home|srv|app|www|opt|data)/)[^<>\\r\\n]{1,350}</td>\\s*</tr>~i';
            $offset = 0;
            $count = 0;
            while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $this->candidate($raw, $m[0]);
                $offset = $m[0][1] + strlen($m[0][0]);
                if ($m[0][1] < $iisMarkerEnd || $this->inDetailExample($raw, $m[0][1])) { $this->excluded(); continue; }
                yield ['internal_path', $m[0]];
                ++$count;
            }
            $this->matchBudget($count, $offset, $raw);
        }
    }

    /** New PHP renderings qualify within one closed list/table/trace, with two named calls. */
    private function structuredPhpFrames(string $raw): iterable
    {
        $method = <<<'REGEX'
[A-Za-z_][A-Za-z0-9_\\]{0,200}(?:::|->|-&gt;)[A-Za-z_]\w{0,100}
REGEX;
        $containers = [
            ['list', '~<ul\b[^>]{0,300}>~i', '</ul>', 65536],
            ['table', '~<table\b[^>]{0,300}>~i', '</table>', 65536],
            ['symfony', '~<pre\b[^>]{0,200}\bclass=["\']stacktrace["\'][^>]{0,100}>~i', '</pre>', 32768],
            ['comment', '~<!--~', '-->', 65536],
        ];
        foreach ($containers as [$kind, $pattern, $closing, $window]) {
            $offset = $count = 0;
            while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $container, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $opening = $container[0];
                $offset = $opening[1] + strlen($opening[0]);
                if (!$this->diagnosticEligible($raw, $opening[1], $kind === 'comment')) { $this->excluded(); continue; }
                if ($kind === 'symfony' && !$this->hasOpeningClass($raw, $opening[1], 'stacktrace')) { $this->excluded(); continue; }
                $preview = substr($raw, $offset, 4096);
                $marker = match ($kind) {
                    'list' => CheckedRegex::match('~<li\b[^>]{0,150}>\s*<b>'.$method.'~i', $preview) === 1,
                    'table' => CheckedRegex::match('~Call\s+stack~i', $preview) === 1,
                    'comment' => str_contains($preview, 'Stack trace:'),
                    default => true,
                };
                if (!$marker) continue;
                $this->candidate($raw, $opening);
                $end = stripos($raw, $closing, $offset);
                if ($end === false) { $this->limit('php_frame_container', 'representation_invalid', []); break; }
                $offset = $end + strlen($closing);
                if ($offset - $opening[1] > $window) { $this->limit('php_frame_container', 'context_budget', ['limit' => $window]); continue; }
                $container[0][0] = substr($raw, $opening[1], $offset - $opening[1]);
                $body = $container[0][0];
                $headers = [];
                if ($kind === 'list') {
                    // Each header owns its method and location. Optional source/args
                    // beneath it never provide a missing method for another item.
                    CheckedRegex::matchAll('~<li\b[^>]{0,150}>~i', $body, $items, PREG_OFFSET_CAPTURE);
                    foreach ($items[0] as [$opening, $at]) {
                        $itemEnd = stripos($body, '</li>', $at + strlen($opening));
                        if ($itemEnd === false) { $headers[] = null; continue; }
                        $item = substr($body, $at, min(12000, $itemEnd + 5 - $at));
                        $header = null;
                        if (CheckedRegex::match('~\A<li\b[^>]{0,150}>\s*<b>'.$method.'</b>\s*-\s*<a\b[^>]{0,1500}>\[line\s+[1-9]\d{0,7}\s*-\s*(?:[\w.-]+/){0,24}[\w.-]+\.php\]</a>~i', $item, $m) === 1
                            || CheckedRegex::match('~\A<li\b[^>]{0,150}>\s*<b>'.$method.'\([^<>]{0,1400}\)</b>\s*<br\s*/?>\s*(?:[\w.-]+/){0,24}[\w.-]+\.php:[1-9]\d{0,7}~i', $item, $m) === 1) $header = [$m[0], $container[0][1] + $at];
                        $headers[] = $header;
                    }
                } elseif ($kind === 'table' && CheckedRegex::match('~Call\s+stack~i', $body) === 1) {
                    CheckedRegex::matchAll('~<tr\b[^>]{0,150}>(?:(?!</tr\b)[\s\S]){1,1300}?</tr\s*>~i', $body, $items, PREG_OFFSET_CAPTURE);
                    foreach ($items[0] as [$item, $at]) {
                        if (CheckedRegex::match('~\A<tr\b[^>]{0,150}>\s*<td\b[^>]{0,150}>\s*[1-9]\d{0,4}\s*</td>\s*<td\b[^>]{0,150}>\s*'.$method.'\([^<>]{0,300}\)\s*</td>\s*<td\b[^>]{0,150}>\s*JROOT/(?:[\w.-]+/){0,24}[\w.-]+\.php:[1-9]\d{0,7}\s*</td>\s*</tr>~i', $item, $m) === 1) $headers[] = [$m[0], $container[0][1] + $at];
                        else $headers[] = null;
                    }
                } else {
                    $linePattern = $kind === 'symfony'
                        ? '~\bat\s+'.$method.'\([^<>\r\n]{0,300}\)\s*\r?\n[ \t]*\((?:[\w.-]+/){1,24}[\w.-]+\.php:[1-9]\d{0,7}\)~'
                        : '~^\s*[1-9]\d{0,4}\.\s+'.$method.'\([^<>\r\n]{0,300}\)\s+(?:/(?:[\w.-]+/){1,24}|[A-Za-z]:[\\/])[^<>\r\n]{1,250}\.php:[1-9]\d{0,7}~m';
                    if ($kind === 'comment' && CheckedRegex::match('~Stack trace:\s*~', $body) !== 1) continue;
                    CheckedRegex::matchAll($linePattern, $body, $items, PREG_OFFSET_CAPTURE);
                    $previousEnd = null;
                    foreach ($items[0] as [$item, $at]) {
                        if ($previousEnd !== null && trim(substr($body, $previousEnd, $at - $previousEnd)) !== '') $headers[] = null;
                        $headers[] = [$item, $container[0][1] + $at];
                        $previousEnd = $at + strlen($item);
                    }
                }
                $group = [];
                foreach ([...$headers, null] as $header) {
                    if ($header !== null) { $group[] = $header; continue; }
                    if (count($group) >= 2) {
                        foreach (array_chunk($group, 12) as $chunk) {
                            if ($count >= self::MAX_MATCHES) { $this->limit('php_frame_groups', 'match_budget', ['limit' => self::MAX_MATCHES]); return; }
                            // A final singleton remains part of the already qualified group.
                            yield ['stack_trace', $chunk];
                            ++$count;
                        }
                    }
                    $group = [];
                }
            }
            $this->matchBudget($count, $offset, $raw);
        }
    }

    /** Headers and source windows stay separate even when rendered by one debug frame. */
    private function phpDebugFrames(string $raw): iterable
    {
        $pattern = '~<pre\b[^>]{0,300}>\s*<span\b[^>]{0,150}>[1-9]\d{0,4}</span>(?:(?!</pre\b)[\s\S]){1,700}?</pre>\s*<br\s*/?>\s*<span\b[^>]{0,200}>(?:/(?:[\w.-]+/){1,24}|[A-Za-z]:[\\/])[^<>\r\n]{1,300}\.php:</span>\s*<br\s*/?>~i';
        $offset = $count = 0;
        while ($count < 64 && $this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $this->candidate($raw, $m[0]);
            $offset = $m[0][1] + strlen($m[0][0]);
            ++$count;
            if (!$this->diagnosticEligible($raw, $m[0][1])) { $this->excluded(); continue; }
            $headerText = html_entity_decode(strip_tags($m[0][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (CheckedRegex::match(<<<'REGEX'
~\A\s*[1-9]\d{0,4}\s+(?:[A-Za-z_][A-Za-z0-9_\\]{0,200}(?:::|->))?[A-Za-z_]\w{0,100}\(\)~
REGEX, $headerText) !== 1) { $this->excluded(); continue; }
            $sourceStart = $sourceEnd = $offset;
            for ($part = 0; $part < 3; ++$part) {
                if ($this->scan('~\G\s*<pre\b[^>]{0,300}>\s*0*[1-9]\d{0,7}:~i', $raw, $pre, 0, $sourceEnd) !== 1) break;
                $end = stripos($raw, '</pre>', $sourceEnd + strlen($pre[0]));
                if ($end === false) { $this->limit('php_source_window', 'representation_invalid', []); break; }
                if ($end + 6 - $sourceStart > 8192) { $this->limit('php_source_window', 'context_budget', ['limit' => 8192]); break; }
                $sourceEnd = $end + 6;
            }
            $source = [substr($raw, $sourceStart, $sourceEnd - $sourceStart), $sourceStart];
            if (!$this->consecutiveBackendLines($source[0])) { $this->excluded(); continue; }
            $offset = $sourceEnd;
            yield [$m[0], $source];
        }
        if ($count >= 64 && $offset < strlen($raw)) $this->limit('php_debug_frames', 'match_budget', ['limit' => 64]);
    }

    private function backendSourceWindows(string $raw): iterable
    {
        $pattern = '~<div\b[^>]{0,200}\bclass=["\']info["\'][^>]{0,150}>\s*<h3>Source</h3>\s*(?<source><pre\b[^>]{0,200}>(?:(?!</pre\b)[\s\S]){1,1500}?</pre>)\s*</div>~i';
        $offset = $count = 0;
        while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $this->candidate($raw, $m[0]);
            $offset = $m[0][1] + strlen($m[0][0]);
            if (!$this->diagnosticEligible($raw, $m[0][1]) || !$this->hasOpeningClass($raw, $m[0][1], 'info') || !$this->consecutiveBackendLines($m['source'][0])) { $this->excluded(); continue; }
            yield ['source_code', $m['source']];
            ++$count;
        }
        $this->matchBudget($count, $offset, $raw);
        $count = 0;
        foreach ($this->phpDebugFrames($raw) as [, $source]) {
            if ($count >= self::MAX_MATCHES) { $this->limit('php_source_windows', 'match_budget', ['limit' => self::MAX_MATCHES, 'remainingCandidates' => null]); break; }
            yield ['source_code', $source];
            ++$count;
        }
    }

    /** Structured Ruby/Lua traces bind only to their real complete JSON fields. */
    private function structuredScriptFrames(string $raw): iterable
    {
        $start = strspn($raw, " \t\r\n");
        if (($raw[$start] ?? '') !== '{' || (strpos($raw, '"traceback"') === false && strpos($raw, '"details"') === false)) return;
        $json = substr($raw, $start);
        if (strlen($json) > 65536) { $this->limit('script_trace_json', 'context_budget', ['limit' => 65536]); return; }
        try { $object = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { $this->limit('script_trace_json', $error->getCode() === JSON_ERROR_DEPTH ? 'context_budget' : 'representation_invalid', []); return; }
        $ruby = $object['error']['details'] ?? null;
        if (is_array($ruby) && array_is_list($ruby) && ($object['error']['status_code'] ?? null) === 500) {
            if (count($ruby) > 64) $this->limit('ruby_frames', 'match_budget', ['limit' => 64]);
            $group = [];
            foreach (array_slice($ruby, 0, 64) as $i => $value) {
                if (is_string($value) && CheckedRegex::match('~\A(?:[\w.-]+/){1,24}[\w.-]+\.rb:[1-9]\d{0,7}:in `(?:[A-Za-z_][\w!?]*|block(?: \([1-9]\d? levels\))? in [A-Za-z_][\w!?]*)\x27\z~', $value) === 1) {
                    $field = JsonDiagnosticSpans::field($json, ['error', 'details', $i]);
                    if ($field !== null) $group[] = [$field['match'][0], $start + $field['match'][1]];
                } elseif ($group !== []) break;
            }
            if (count($group) >= 2) yield ['stack_trace', $group];
        }
        $traceback = $object['traceback'] ?? null;
        if (!is_string($traceback) || !str_starts_with(ltrim($traceback), 'stack traceback:')) return;
        $lines = preg_split('/\r?\n/', $traceback);
        $named = 0;
        foreach ($lines as $line) {
            if (CheckedRegex::match('~\A\s*(?:\.{0,3}/?|/)?(?:[\w.-]+/){0,24}[\w.-]+\.lua:[1-9]\d{0,7}: in function \x27[A-Za-z_][\w.]*\x27\s*\z~', $line) === 1) ++$named;
        }
        if ($named >= 2) {
            $field = JsonDiagnosticSpans::field($json, ['traceback']);
            if ($field !== null) yield ['stack_trace', [$field['match'][0], $start + $field['match'][1]]];
        }
    }

    private function jspSourceWindows(string $raw): iterable
    {
        $offset = $count = 0;
        $pattern = '~<p><b>Exception</b></p>\s*<pre>[^<>\r\n]{0,300}?org\.apache\.jasper\.JasperException:\s*An exception occurred processing \[[^<>\r\n]{1,220}\.jsp\] at line \[[1-9]\d{0,7}\]~i';
        while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $this->candidate($raw, $m[0]);
            $offset = $m[0][1] + strlen($m[0][0]);
            if (!$this->diagnosticEligible($raw, $m[0][1])) { $this->excluded(); continue; }
            $close = stripos($raw, '</pre>', $offset);
            if ($close === false) { $this->limit('jsp_source_window', 'representation_invalid', []); break; }
            if ($close - $offset > 8192) { $this->limit('jsp_source_window', 'context_budget', ['limit' => 8192]); $offset = $close + 6; continue; }
            $body = substr($raw, $offset, $close - $offset);
            $offset = $close + 6;
            CheckedRegex::matchAll('~(?:\A|\r?\n)([1-9]\d{0,7}):[^\r\n]{0,500}~', $body, $lines, PREG_OFFSET_CAPTURE);
            if (count($lines[0]) < 3) { $this->excluded(); continue; }
            $previous = null;
            $valid = true;
            foreach ($lines[1] as [$line]) {
                if ($previous !== null && (int) $line !== $previous + 1) $valid = false;
                $previous = (int) $line;
            }
            $first = $lines[0][0][1];
            $last = $lines[0][count($lines[0]) - 1];
            $source = substr($body, $first, $last[1] + strlen($last[0]) - $first);
            if (!$valid || CheckedRegex::match('~\b(?:int|String)\s+[A-Za-z_]\w*\s*=~', $source) !== 1 || CheckedRegex::match('~\brequest\.getParameter\(|\bInteger\.parseInt\(~', $source) !== 1) { $this->excluded(); continue; }
            yield ['source_code', [$source, $close - strlen($body) + $first]];
            ++$count;
        }
        $this->matchBudget($count, $offset, $raw);
    }

    private function consecutiveBackendLines(string $raw): bool
    {
        $text = CheckedRegex::replace('~<span\b[^>]{0,180}>\s*(\d{1,8})\s*</span>~i', '$1: ', $raw);
        $text = str_ireplace('</pre>', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        CheckedRegex::matchAll('~(?:\A|\r?\n)0*([1-9]\d{0,7})[.:][ \t]*(.*)~', $text, $lines, PREG_SET_ORDER);
        $previous = null;
        $run = $max = 0;
        $code = '';
        foreach ($lines as $line) {
            $number = (int) $line[1];
            $run = $previous !== null && $number === $previous + 1 ? $run + 1 : 1;
            $max = max($max, $run);
            $previous = $number;
            $code .= $line[2]."\n";
        }
        return $max >= 3 && CheckedRegex::match('~\$[A-Za-z_][\w]*[^\r\n]{0,200}(?:->|::|=|\(|;|\[)|\bthrow\s+new\s+[A-Za-z_\\\\]+\s*\(~', $code) === 1;
    }

    /** Named diagnostics bind to their own actual error region, never arbitrary page strings. */
    private function runtimeDetails(string $raw): iterable
    {
        $java = '(?:[A-Za-z][\\w.-]{0,100}(?:/[\\w.+-]{1,40})?/)?[A-Za-z_$][\\w$]*(?:[.$][\\w$<>]+){2,}\\([\\w$.-]{1,100}\\.java:[1-9]\\d{0,7}\\)';
        $patterns = [
            ['java', 'cookie_validation', '~<p\\b[^>]{0,120}>\\s*<b>\\s*(?<diagnostic>Control character in cookie value or attribute\\.)\\s*</b>\\s*</p>\\s*<div\\b[^>]{0,350}>\\s*<h2\\b[^>]{0,100}>\\s*Stacktrace\\s*</h2>(?:(?!</div\\b)[\\s\\S]){0,1800}?<p\\b[^>]{0,120}>\\s*'.$java.'\\s*</p>\\s*<p\\b[^>]{0,120}>\\s*'.$java.'\\s*</p>~i'],
            ['java', 'transformer_creation', '~<div\\b[^>]{0,300}\\bclass=["\'][^"\']{0,200}\\bcard-header\\b[^"\']{0,200}["\'][^>]{0,150}>\\s*(?<diagnostic>Error while creating Transformer!)\\s*</div>\\s*<div\\b[^>]{0,250}\\bclass=["\'][^"\']{0,150}\\bcard-body\\b[^"\']{0,150}["\'][^>]{0,150}>(?:(?!</div\\b)[\\s\\S]){0,1800}?<pre>\\s*<samp\\b[^>]{0,150}>\\s*'.$java.'(?:\\s|<br\\s*/?>){1,180}'.$java.'~i'],
            ['classic_asp', 'runtime_error', '~<p>\s*(?<diagnostic>Microsoft\s+(?:VBScript\s+runtime|OLE\s+DB\s+Provider\s+for\s+SQL\s+Server)\s*</font>\s*<font\b[^>]{0,120}>error\s+[\x27"][0-9a-f]{8}[\x27"]</font>\s*<p>\s*<font\b[^>]{0,120}>[^<>\r\n]{1,500}</font>\s*<p>\s*<font\b[^>]{0,120}>/(?:[\w.-]+/){0,12}[\w.-]+\.asp</font>\s*<font\b[^>]{0,120}>,\s*(?:line|Zeile)\s+[1-9]\d{0,7}</font>)~i'],
            ['doctrine_dbal', 'invalid_limit_offset', '~<p\\b[^>]{0,150}\\bclass=["\']exception["\'][^>]{0,150}>\\s*<tt><abbr\\b[^>]{0,150}\\btitle=["\']Doctrine\\\\DBAL\\\\DBALException["\'][^>]{0,100}>DBALException</abbr></tt>\\s+in\\s+<tt>[\\w.-]{1,100}\\.php</tt>\\s+line\\s+<tt>[1-9]\\d{0,7}</tt>:\\s*<br\\s*/?>\\s*<em><strong>(?<diagnostic>LIMIT argument offset=-[1-9]\\d{0,9} is not valid)</strong></em>\\s*</p>~i'],
            ['typo3', 'invalid_action_name', '~<strong\\b[^>]{0,180}>(?<diagnostic>(?:#[1-9]\\d{0,12}: )?The action [^<>\\r\\n]{1,1500}? is not allowed by this plugin\\.[^<>\\r\\n]{0,1200})</strong>(?:(?!<pre\\b|</div\\b)[\\s\\S]){0,800}?<span\\b[^>]{0,150}\\bclass=["\']ExceptionProperty["\'][^>]{0,100}>TYPO3\\\\CMS\\\\Extbase\\\\Mvc\\\\Exception\\\\InvalidActionNameException</span>\\s+thrown in file<br\\s*/?>~i'],
        ];
        foreach ($patterns as [$family, $kind, $pattern]) {
            $offset = $count = 0;
            while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $this->candidate($raw, $m[0]);
                $offset = $m[0][1] + strlen($m[0][0]);
                if (!$this->diagnosticEligible($raw, $m[0][1])
                    || ($kind === 'transformer_creation' && !$this->hasOpeningClass($raw, $m[0][1], 'card-header'))
                    || ($kind === 'invalid_limit_offset' && !$this->hasOpeningClass($raw, $m[0][1], 'exception'))) { $this->excluded(); continue; }
                yield [$m['diagnostic'], ['family' => $family, 'diagnosticKind' => $kind, 'contextKind' => $family === 'classic_asp' ? 'source_location' : 'runtime_diagnostic']];
                ++$count;
            }
            $this->matchBudget($count, $offset, $raw);
        }
        // Locate scripts throughout the representation, including late SSR data.
        $offset = $count = 0;
        foreach ($this->scriptElements($raw) as [$m, $close]) {
            if ($count >= self::MAX_MATCHES) break;
            $offset = $m[0][1] + strlen($m[0][0]);
            if (!$this->diagnosticEligible($raw, $m[0][1], false, true)) continue;
            $attrs = $this->htmlAttributes($m[1][0]);
            if (($attrs['id'] ?? '') !== '__NEXT_DATA__' || strtolower($attrs['type'] ?? '') !== 'application/json') continue;
            $this->candidate($raw, $m[0]);
            ++$count;
            if ($close === null) { $this->limit('next_json', 'representation_invalid', []); break; }
            if ($close - $offset > 65536) {
                $this->limit('next_json', 'context_budget', ['limit' => 65536]);
                $offset = $close + 8;
                continue;
            }
            $json = substr($raw, $offset, $close - $offset);
            try {
                json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                $this->limit('next_json', $error->getCode() === JSON_ERROR_DEPTH ? 'context_budget' : 'representation_invalid', $error->getCode() === JSON_ERROR_DEPTH ? ['depthLimit' => 32] : []);
                $offset = $close + 8;
                continue;
            }
            $error = JsonDiagnosticSpans::field($json, ['props', 'pageProps', 'error']);
            $status = JsonDiagnosticSpans::field($json, ['props', 'pageProps', 'statusCode']);
            if (($error['value'] ?? null) === 'Failed to create resultsState' && ($status['value'] ?? null) === 500) {
                $match = [$error['match'][0], $offset + $error['match'][1]];
                yield [$match, ['family' => 'next', 'diagnosticKind' => 'results_state_creation', 'contextKind' => 'structured_error', 'fieldPath' => 'props.pageProps.error', 'statusCode' => 500]];
            } else $this->excluded();
            $offset = $close + 8;
        }
        $this->matchBudget($count, $offset, $raw);
    }

    /** Read actual script openings, consuming quoted attributes and raw-text elements as data. */
    private function scriptElements(string $raw): iterable
    {
        $offset = 0;
        $length = strlen($raw);
        while (($open = strpos($raw, '<', $offset)) !== false) {
            $offset = $open + 1;
            if (substr_compare($raw, '<!--', $open, 4) === 0) {
                $close = strpos($raw, '-->', $open + 4);
                if ($close === false) return;
                $offset = $close + 3;
                continue;
            }
            if (CheckedRegex::match('~\\G</?([A-Za-z][\\w:-]*)\\b~', $raw, $tag, 0, $open) !== 1) continue;
            $quote = null;
            for ($end = $open + strlen($tag[0]); $end < $length; ++$end) {
                $byte = $raw[$end];
                if ($quote !== null) {
                    if ($byte === $quote) $quote = null;
                } elseif ($byte === '"' || $byte === "'") $quote = $byte;
                elseif ($byte === '>') break;
            }
            if ($end === $length) return;
            $offset = $end + 1;
            if ($raw[$open + 1] === '/') continue;
            $name = strtolower($tag[1]);
            if (!in_array($name, ['script', 'style', 'textarea', 'title'], true)) continue;
            $found = CheckedRegex::match('~</'.$name.'\\s*>~i', $raw, $closing, PREG_OFFSET_CAPTURE, $offset) === 1;
            $close = $found ? $closing[0][1] : null;
            if ($name === 'script') {
                yield [[0 => [substr($raw, $open, $end + 1 - $open), $open], 1 => [substr($raw, $open + strlen($tag[0]), $end - $open - strlen($tag[0])), $open + strlen($tag[0])]], $close];
            }
            if (!$found) return;
            $offset = $close + strlen($closing[0][0]);
        }
    }

    /** Actual attribute names and first values; quoted data cannot manufacture attributes. */
    private function htmlAttributes(string $raw): array
    {
        CheckedRegex::matchAll(<<<'REGEX'
~[\t\n\f\r ]+([^\s"'<>/=]+)(?:[\t\n\f\r ]*=[\t\n\f\r ]*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?~
REGEX, $raw, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($matches as $attribute) {
            $name = strtolower($attribute[1]);
            $attrs[$name] ??= html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attrs;
    }

    /** Only a complete, active title/heading can identify an IIS detail page. */
    private function iisDetailMarkerEnd(string $raw): ?int
    {
        $offset = 0;
        $length = strlen($raw);
        while (($open = strpos($raw, '<', $offset)) !== false) {
            $offset = $open + 1;
            if (substr_compare($raw, '<!--', $open, 4) === 0) {
                $close = strpos($raw, '-->', $open + 4);
                if ($close === false) return null;
                $offset = $close + 3;
                continue;
            }
            if (CheckedRegex::match('~\G</?([A-Za-z][\w:-]*)\b~', $raw, $tag, 0, $open) !== 1) continue;
            $name = strtolower($tag[1]);
            // Consume each complete tag, including quoted angle brackets. A
            // title-looking value inside an attribute is still attribute data.
            $quote = null;
            for ($end = $open + strlen($tag[0]); $end < $length; ++$end) {
                $byte = $raw[$end];
                if ($quote !== null) {
                    if ($byte === $quote) $quote = null;
                } elseif ($byte === '"' || $byte === "'") {
                    $quote = $byte;
                } elseif ($byte === '>') {
                    break;
                }
            }
            if ($end === $length) return null;
            $offset = $end + 1;
            if ($raw[$open + 1] === '/') continue;
            if (in_array($name, ['title', 'h1', 'h2', 'h3'], true)
                && CheckedRegex::match('~\G\s*IIS\s+(?:[\d.]+\s*(?:[-–]\s*)?)?Detailed\s+Error\b[^<>]{0,200}</'.$name.'\s*>~iu', $raw, $marker, 0, $offset) === 1
                && !$this->inDetailExample($raw, $offset)) return $offset + strlen($marker[0]);
            // Raw-text contents cannot supply markup; a quoted <title> in a
            // script also cannot hide a later, independent real title.
            if (in_array($name, ['script', 'style', 'textarea', 'title'], true)) {
                if (CheckedRegex::match('~</'.$name.'\s*>~i', $raw, $close, PREG_OFFSET_CAPTURE, $offset) !== 1) return null;
                $offset = $close[0][1] + strlen($close[0][0]);
            }
        }
        return null;
    }

    /** Tokenize source as data; never execute it or retain its literal values. */
    private function sourceBlocks(string $raw): iterable
    {
        $offset = 0;
        $count = 0;
        while ($count < self::MAX_MATCHES && $this->scan('~<\\?(?:php(?=\\s)|(?=\\s))~i', $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $this->candidate($raw, $m[0]);
            $start = $m[0][1];
            $offset = $start + strlen($m[0][0]);
            $short = strlen($m[0][0]) === 2;
            $candidate = substr($raw, $start, 8193);
            $normalized = $short ? '<?php '.substr($candidate, 2) : $candidate;
            $tokens = token_get_all($normalized);
            $bytes = 0;
            $end = null;
            $code = [];
            foreach ($tokens as $token) {
                $text = is_array($token) ? $token[1] : $token;
                if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                    $end = $bytes + 2 - ($short ? 4 : 0);
                    break;
                }
                $bytes += strlen($text);
                if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code[] = $token;
            }
            if ($end === null) {
                if (strlen($candidate) > 8192) $this->limit('source_window', 'context_budget', ['limit' => 8192, 'remainingBytes' => null]);
                else $this->excluded();
                $offset = $start + strlen($candidate);
                continue;
            }
            // Skip nested openers in strings/comments of this block, even when
            // the surrounding source does not pass the disclosure gate.
            $offset = $start + $end;
            if ($end > 8192) {
                $this->limit('source_window', 'context_budget', ['limit' => 8192]);
                continue;
            }
            if ($this->inSourceExample($raw, $start)) { $this->excluded(); continue; }
            try {
                token_get_all(substr($normalized, 0, $end + ($short ? 4 : 0)), TOKEN_PARSE);
            } catch (\ParseError) {
                $this->excluded();
                continue;
            }
            if (!$this->hasConnectedDatabaseFunction($code)) { $this->excluded(); continue; }
            yield ['source_code', [substr($raw, $start, $end), $start]];
            ++$count;
        }
        $this->matchBudget($count, $offset, $raw);
    }

    private function inSourceExample(string $raw, int $offset): bool
    {
        if ($this->inDetailExample($raw, $offset)) return true;
        $prefix = substr($raw, 0, $offset);
        $line = substr($prefix, (strrpos($prefix, "\n") ?: -1) + 1);
        if (CheckedRegex::match('~(?:^|>)[\\t ]*\\z~', $line) !== 1) return true;
        $comment = strrpos($prefix, '/*');
        $closed = strrpos($prefix, '*/');
        return $comment !== false && ($closed === false || $comment > $closed);
    }

    private function inDetailExample(string $raw, int $offset): bool
    {
        if ($this->inInactiveWafText($raw, $offset)) return true;
        $prefix = substr($raw, 0, $offset);
        foreach (['pre', 'example'] as $tag) {
            $open = strripos($prefix, '<'.$tag);
            $close = strripos($prefix, '</'.$tag);
            if ($open !== false && ($close === false || $open > $close)) return true;
        }
        if ($this->inSourceExampleAncestor($prefix)) return true;
        return false;
    }

    private function inSourceExampleAncestor(string $prefix): bool
    {
        $stack = [];
        $offset = 0;
        $pattern = <<<'REGEX'
~<(/?)([A-Za-z][\w:-]*)(\s+(?&attributeByte){0,2000})?\s*/?>(?(DEFINE)(?<attributeByte>[^"'<>]|"[^"<>]{0,1000}"|'[^'<>]{0,1000}'))~
REGEX;
        while (CheckedRegex::match($pattern, $prefix, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $tag = strtolower($m[2][0]);
            if ($m[1][0] === '/') {
                for ($i = count($stack) - 1; $i >= 0; --$i) {
                    if ($stack[$i][0] !== $tag) continue;
                    $stack = array_slice($stack, 0, $i);
                    break;
                }
                continue;
            }
            if (in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true) || str_ends_with($m[0][0], '/>')) continue;
            $example = false;
            CheckedRegex::matchAll(<<<'REGEX'
~[\t\n\f\r ]+([^\s"'<>/=]+)(?:[\t\n\f\r ]*=[\t\n\f\r ]*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?~
REGEX, $m[3][0] ?? '', $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($attributes as $attribute) {
                if (!in_array(strtolower($attribute[1]), ['class', 'id'], true)) continue;
                $value = html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (CheckedRegex::match('~(?:^|[\\s_-])(?:example|examples|sample|tutorial|documentation)(?:$|[\\s_-])~i', $value) === 1) $example = true;
            }
            $stack[] = [$tag, $example];
            // Malformed, excessively nested example markup remains ineligible.
            if (count($stack) > 128) {
                $this->limit('example_ancestor_depth', 'context_budget', ['limit' => 128]);
                return true;
            }
        }
        foreach ($stack as [, $example]) if ($example) return true;
        return false;
    }

    private function hasConnectedDatabaseFunction(array $tokens): bool
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
            // Only a complete named function with its own scope can bind literals
            // to calls. Closures, declarations and separate functions cannot combine.
            if (!is_array($tokens[$i + 1] ?? null) || $tokens[$i + 1][0] !== T_STRING || ($tokens[$i + 2] ?? null) !== '(') continue;
            $j = $i + 3;
            while ($j < $count && $tokens[$j] !== ')') ++$j;
            if (($tokens[++$j] ?? null) !== '{') continue;
            $depth = 1;
            $assigned = [];
            $connected = false;
            $database = false;
            for (++$j; $j < $count && $depth > 0; ++$j) {
                $token = $tokens[$j];
                if ($token === '{') ++$depth;
                if ($token === '}') --$depth;
                if (is_array($token) && in_array($token[0], [T_FUNCTION, T_FN], true)) { $connected = $database = false; break; }
                if (is_array($token) && $token[0] === T_VARIABLE && ($tokens[$j + 1] ?? null) === '=') {
                    unset($assigned[$token[1]]);
                    if ($depth === 1 && is_array($tokens[$j + 2] ?? null) && $tokens[$j + 2][0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$j + 3] ?? null) === ';') $assigned[$token[1]] = true;
                }
                if (!is_array($token) || $token[0] !== T_STRING || ($tokens[$j + 1] ?? null) !== '(') continue;
                $name = strtolower($token[1]);
                if (!in_array($name, ['mysql_connect', 'mysql_select_db'], true)) continue;
                $previous = $tokens[$j - 1] ?? null;
                if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) continue;
                $args = [];
                $k = $j + 2;
                while (is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_VARIABLE) {
                    $args[] = $tokens[$k][1];
                    ++$k;
                    if (($tokens[$k] ?? null) !== ',') break;
                    ++$k;
                }
                if (($tokens[$k] ?? null) !== ')') continue;
                if ($name === 'mysql_connect' && count($args) === 3 && count(array_unique($args)) === 3 && count(array_intersect_key($assigned, array_flip($args))) === 3) $connected = true;
                if ($name === 'mysql_select_db' && $connected && count($args) >= 1 && count($args) <= 2 && isset($assigned[$args[0]]) && count($assigned) >= 4) $database = true;
            }
            if ($depth === 0 && $connected && $database) return true;
            $i = max($i, $j - 1);
        }
        return false;
    }

    private function additionalWafContexts(string $raw): iterable
    {
        yield from $this->additionalAccessChallenges($raw);
        $patterns = [
            ['go_away', 'waf_challenge', <<<'REGEX'
~<link\b(?=[^>]{0,2500}\brel=["']stylesheet["'])[^>]{0,2500}\bhref=["'][^"'<>]{0,250}/git/go-away/[^"'<>]{0,250}/challenge/resource-load/verify-challenge\?[^"'<>]{0,2000}["'][^>]{0,200}>[\s\S]{0,800}?<main\b[^>]{0,100}>\s*<div\b[^>]{0,100}>\s*<h1\b[^>]{0,100}>\s*Checking\s+your\s+browser\s*</h1>\s*<p\b[^>]{0,100}>\s*Loading\s+challenge\s*<em>resource-load</em>[\s\S]{0,700}?Your\s+browser\s+is\s+solving\s+a\s+short\s+proof-of-work\s+challenge\s+before\s+the\s+page\s+is\s+loaded\.~i
REGEX],
            ['admin_tools', 'waf_block', '~\bThis\s+request\s+is\s+blocked\s+by\s+Admin\s+Tools\b\.?~i'],
            ['admin_tools', 'waf_block', '~We\s+have\s+detected\s+suspicious\s+activity\s+from\s+your\s+IP\s+address\.\s+Your\s+access\s+to\s+this\s+site\s+is\s+temporarily\s+suspended\.[^<>\r\n]{0,550}?being\s+blocked\s+by\s+Admin\s+Tools\.~i'],
        ];
        $seen = [];
        foreach ($patterns as [$vendor, $reason, $pattern]) {
            $offset = 0;
            while ($this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $this->candidate($raw, $m[0]);
                $offset = $m[0][1] + strlen($m[0][0]);
                if ($this->inInactiveWafText($raw, $m[0][1])) { $this->excluded(); continue; }
                if (!isset($seen[$vendor])) yield [$vendor, $reason, $m[0]];
                $seen[$vendor] = true;
                break;
            }
        }
        // The active v2 installer binds loader and callback in one script object.
        // Translation dictionaries, product objects and asset names alone do not qualify.
        $offset = 0;
        while ($this->scan('~<script\b([^>]{0,500})>([\s\S]{1,6000}?)</script\s*>~i', $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $this->candidate($raw, $m[0]);
            $offset = $m[0][1] + strlen($m[0][0]);
            if ($this->inInactiveWafText($raw, $m[0][1]) || !$this->hasExecutableScriptType($m[1][0])) { $this->excluded(); continue; }
            $script = $m[2][0];
            // Tokenize quoted values and comments before checking operations. Only
            // literal script/loader arguments remain readable; quoted code is inert.
            $script = CheckedRegex::replaceCallback(<<<'REGEX'
~("(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'|`(?:[^`\\]|\\.)*`)|//[^\r\n]*|/\*[\s\S]*?\*/~
REGEX, static function (array $part): string {
                if (!isset($part[1]) || $part[1] === '') return ' ';
                $value = substr($part[1], 1, -1);
                return in_array($value, ['script', 'https://waf.chaitin.com/challenge/v2/challenge.js', '/.safeline/challenge/v2/challenge.js'], true) ? $part[1] : "'__literal__'";
            }, $script);
            if (CheckedRegex::match('~\bfunction\s+install\s*\(\s*url\s*,\s*err\s*\)~', $script) !== 1
                || CheckedRegex::match('~\bdocument\.createElement\(\s*["\']script["\']\s*\)~', $script) !== 1
                || CheckedRegex::match('~\b([A-Za-z_$][\w$]*)\.src\s*=\s*url\s*;\s*\1\.onload\s*=\s*function\s*\(\s*\)\s*\{\s*SafeLineChallenge\s*\(~', $script) !== 1
                || CheckedRegex::match('~\bdocument\.head\.appendChild\s*\(~', $script) !== 1
                || CheckedRegex::match('~\binstall\s*\(\s*["\'](?:https://waf\.chaitin\.com/challenge/v2/challenge\.js|/\.safeline/challenge/v2/challenge\.js)["\']~', $script) !== 1) continue;
            yield ['safeline', 'waf_challenge', $m[0]];
            break;
        }
    }

    private function hasExecutableScriptType(string $attributes): bool
    {
        // Inspect actual attributes, consuming quoted values so data-* text cannot
        // supply a type. The first type wins, as with duplicate HTML attributes.
        CheckedRegex::matchAll(<<<'REGEX'
~[\t\n\f\r ]+([^\s"'<>/=]+)(?:[\t\n\f\r ]*=[\t\n\f\r ]*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?~
REGEX, $attributes, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $attribute) {
            if (strtolower($attribute[1]) !== 'type') continue;
            $type = strtolower(trim(html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), "\t\n\f\r "));
            return $type === '' || CheckedRegex::match('~\A(?:module|(?:application|text)/(?:x-)?(?:java|ecma)script|text/(?:javascript1\.[0-5]|jscript|livescript))\z~', $type) === 1;
        }
        return true;
    }

    private function inInactiveWafText(string $raw, int $offset): bool
    {
        if ($this->inCodeExample($raw, $offset)) return true;
        $prefix = substr($raw, 0, $offset);
        // HTTP source alone does not establish that a noscript variant is active.
        foreach (['script', 'style', 'template', 'textarea', 'noscript'] as $tag) {
            $open = strripos($prefix, '<'.$tag);
            $close = strripos($prefix, '</'.$tag);
            if ($open !== false && ($close === false || $open > $close)) return true;
        }
        $open = strrpos($prefix, '<!--');
        $close = strrpos($prefix, '-->');
        return $open !== false && ($close === false || $open > $close);
    }

    private function diagnosticCatalogs(string $raw): iterable
    {
        $count = 0;
        foreach ($this->scriptElements($raw) as [$element, $close]) {
            if ($close === null || !$this->diagnosticEligible($raw, $element[0][1], false, true) || !$this->hasExecutableScriptType($element[1][0])) continue;
            $start = $element[0][1] + strlen($element[0][0]);
            $script = substr($raw, $start, $close - $start);
            $offset = 0;
            while ($this->scan('~(?:\A|[;\r\n])[\t ]*\K\$\.lang\s*=\s*\{\s*locale\s*:\s*["\'][A-Za-z_-]{2,20}["\']\s*,\s*language\s*:\s*\{~', $script, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $m[0][1] + strlen($m[0][0]);
                if (!$this->javascriptCodeAt($script, $m[0][1])) continue;
                $match = [$m[0][0], $start + $m[0][1]];
                $this->candidate($raw, $match);
                if (++$count > self::MAX_MATCHES) { $this->limit('diagnostic_catalog', 'match_budget', ['limit' => self::MAX_MATCHES]); return; }
                $objectStart = $offset - 1;
                $candidate = substr($script, $objectStart, 786433);
                $objectEnd = JsonDiagnosticSpans::valueEnd($candidate, 0);
                if ($objectEnd > 786432) { $this->limit('diagnostic_catalog', 'context_budget', ['limit' => 786432]); continue; }
                $json = substr($candidate, 0, $objectEnd);
                try { $catalog = json_decode($json, false, 8, JSON_THROW_ON_ERROR); }
                catch (\JsonException $error) {
                    $this->limit('diagnostic_catalog', $error->getCode() === JSON_ERROR_DEPTH ? 'context_budget' : 'representation_invalid', $error->getCode() === JSON_ERROR_DEPTH ? ['depthLimit' => 8] : []);
                    continue;
                }
                if (!$catalog instanceof \stdClass) { $this->excluded(); continue; }
                $entries = get_object_vars($catalog);
                if (count($entries) > 12000) { $this->limit('diagnostic_catalog', 'context_budget', ['entryLimit' => 12000]); continue; }
                if (count($entries) < 2 || count(array_filter($entries, 'is_string')) !== count($entries)) { $this->excluded(); continue; }
                $rangeStart = $start + $objectStart;
                yield [$match, ['contextKind' => 'diagnostic_catalog', 'catalogKind' => 'i18n', 'entryCount' => count($entries), 'catalogRange' => ['representation' => $this->representation, 'start' => mb_strlen(substr($raw, 0, $rangeStart), 'UTF-8'), 'length' => mb_strlen($json, 'UTF-8'), 'sha256' => hash('sha256', $json)], 'source' => 'response_body', 'blockEstablished' => false]];
                $offset = $objectStart + $objectEnd;
            }
        }
    }

    /** Only lexical code can assign a catalog; strings, template strings and comments are data. */
    private function javascriptCodeAt(string $script, int $target): bool
    {
        $quote = null;
        $comment = null;
        $regexClass = false;
        $canStartRegex = true;
        $pendingParen = null;
        $parenContexts = [];
        $braceContexts = [];
        $pendingBlock = false;
        $previousToken = null;
        for ($i = 0; $i < $target; ++$i) {
            $byte = $script[$i];
            $next = $script[$i + 1] ?? '';
            if ($comment === '//') { if ($byte === "\n" || $byte === "\r") $comment = null; continue; }
            if ($comment === '/*') { if ($byte === '*' && $next === '/') { $comment = null; ++$i; } continue; }
            if ($quote !== null) {
                if ($byte === '\\') { ++$i; continue; }
                if ($quote === '/') {
                    if ($byte === '[') $regexClass = true;
                    elseif ($byte === ']') $regexClass = false;
                    elseif ($byte === '/' && !$regexClass) { $quote = null; $canStartRegex = false; }
                } elseif ($byte === $quote) { $quote = null; $canStartRegex = false; }
                continue;
            }
            if ($byte === '"' || $byte === "'" || $byte === '`') { $quote = $byte; continue; }
            if ($byte === '/' && ($next === '/' || $next === '*')) { $comment = '/'.$next; ++$i; continue; }
            if ($byte === '/' && $canStartRegex) { $quote = '/'; $regexClass = false; continue; }
            if (ctype_space($byte)) continue;
            if (ctype_alpha($byte) || $byte === '_' || $byte === '$') {
                $begin = $i;
                while ($i + 1 < $target && (ctype_alnum($script[$i + 1]) || $script[$i + 1] === '_' || $script[$i + 1] === '$')) ++$i;
                $word = substr($script, $begin, $i + 1 - $begin);
                if (in_array($word, ['if', 'while', 'for', 'with', 'switch', 'catch'], true)) $pendingParen = 'control';
                elseif ($word === 'function') $pendingParen = 'function';
                if (in_array($word, ['else', 'do', 'try', 'finally', 'class'], true)) $pendingBlock = true;
                $previousToken = $word;
                $canStartRegex = in_array($word, ['return', 'throw', 'case', 'delete', 'void', 'typeof', 'instanceof', 'in', 'of', 'yield', 'await'], true);
                continue;
            }
            if ($byte === '(') {
                $parenContexts[] = $pendingParen ?? 'expression';
                $pendingParen = null;
                $pendingBlock = false;
                $canStartRegex = true;
            } elseif ($byte === ')') {
                $context = array_pop($parenContexts);
                $canStartRegex = $context === 'control';
                $pendingBlock = $context === 'control' || $context === 'function';
            } elseif ($byte === '{') {
                $braceContexts[] = $pendingBlock || $previousToken === null || $previousToken === ';' || $previousToken === '}';
                $pendingBlock = false;
                $canStartRegex = true;
            } elseif ($byte === '}') {
                $canStartRegex = array_pop($braceContexts) === true;
                $pendingBlock = false;
            } elseif ($byte === '=' && $next === '>') {
                ++$i;
                $pendingBlock = true;
                $canStartRegex = true;
            } elseif (ctype_digit($byte) || $byte === ']') $canStartRegex = false;
            elseif (($byte === '+' || $byte === '-') && $next === $byte) { ++$i; $canStartRegex = false; }
            else $canStartRegex = $byte !== '.';
            $previousToken = $byte;
        }
        return $quote === null && $comment === null;
    }

    private function hasJavascriptOperation(string $script, string $pattern): bool
    {
        $offset = 0;
        while (CheckedRegex::match($pattern, $script, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            if ($this->javascriptCodeAt($script, $match[0][1])) return true;
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        return false;
    }

    private function hasHcaptchaRenderer(string $body): bool
    {
        foreach ($this->scriptElements($body) as [$element, $close]) {
            if ($close === null || !$this->diagnosticEligible($body, $element[0][1], false, true) || !$this->hasExecutableScriptType($element[1][0])) continue;
            $start = $element[0][1] + strlen($element[0][0]);
            if ($close - $start > 4096) { $this->limit('hcaptcha_renderer', 'context_budget', ['limit' => 4096]); continue; }
            if ($this->hasJavascriptOperation(substr($body, $start, $close - $start), '~\bhcaptcha\.render\(\s*[A-Za-z_$][\w$]*\s*,\s*\{~')) return true;
        }
        return false;
    }

    /** Access gates require coherent local page purpose and challenge operations. */
    private function additionalAccessChallenges(string $raw): iterable
    {
        $offset = $count = 0;
        while ($count < self::MAX_MATCHES && $this->scan('~<main\b[^>]{0,300}>~i', $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = $m[0][1] + strlen($m[0][0]);
            if (!$this->diagnosticEligible($raw, $m[0][1])) continue;
            $preview = substr($raw, $offset, 4096);
            if (CheckedRegex::match('~Security\s+check|Bot\s+controle~i', strip_tags($preview)) !== 1) continue;
            $this->candidate($raw, $m[0]);
            ++$count;
            $close = stripos($raw, '</main>', $offset);
            if ($close === false) { $this->limit('access_gate', 'representation_invalid', []); break; }
            if ($close + 7 - $m[0][1] > 20000) { $this->limit('access_gate', 'context_budget', ['limit' => 20000]); $offset = $close + 7; continue; }
            $body = substr($raw, $m[0][1], $close + 7 - $m[0][1]);
            $offset = $close + 7;
            if (CheckedRegex::match('~<h1\b[^>]{0,150}>(?:(?!</h1>)[\s\S]){0,400}?Security\s+check(?:(?!</h1>)[\s\S]){0,200}?</h1>~i', $body) === 1
                && CheckedRegex::match('~To protect the website from automated traffic, please confirm that you are a person\.~', strip_tags($body)) === 1
                && CheckedRegex::match('~<form\b[^>]{0,300}\bclass=["\']challenge["\'][^>]{0,300}>[\s\S]{1,1600}?\bname=["\']return_url["\'][\s\S]{0,1600}?</form>~i', $body) === 1
                && $this->hasHcaptchaRenderer($body)) {
                yield ['hcaptcha', 'waf_challenge', [$body, $m[0][1]]];
                continue;
            }
            if (CheckedRegex::match('~<h1\b[^>]{0,100}>\s*Bot\s+controle\s*</h1>~i', $body) !== 1 || !str_contains($body, 'proof-of-work')) continue;
            foreach ($this->scriptElements($raw) as [$element, $scriptClose]) {
                if ($element[0][1] < $offset || $element[0][1] - $offset > 500 || $scriptClose === null || !$this->hasExecutableScriptType($element[1][0])) continue;
                $scriptStart = $element[0][1] + strlen($element[0][0]);
                if ($scriptClose - $scriptStart > 20000) { $this->limit('pow_gate', 'context_budget', ['limit' => 20000]); break; }
                $script = substr($raw, $scriptStart, $scriptClose - $scriptStart);
                if ($this->hasJavascriptOperation($script, '~\bvar\s+VERIFY_PATH\s*=\s*["\']/__pow_gate_verify["\']~')
                    && $this->hasJavascriptOperation($script, '~\bvar\s+DIFFICULTY\s*=\s*[1-9]\d{0,2}\s*;~')
                    && $this->hasJavascriptOperation($script, '~\bnew\s+Worker\(URL\.createObjectURL\(~')
                    && $this->hasJavascriptOperation($script, '~\bworker\.onmessage\s*=\s*function\s*\(e\)~')
                    && $this->hasJavascriptOperation($script, '~\be\.data\.nonce\b~') && $this->hasJavascriptOperation($script, '~\bfetch\(VERIFY_PATH,~') && $this->hasJavascriptOperation($script, '~\bworker\.postMessage\(\{ token: TOKEN, difficulty: DIFFICULTY \}\)~')) {
                    yield ['unknown', 'waf_challenge', [[$body, $m[0][1]], [$element[0][0], $element[0][1]]]];
                }
                break;
            }
        }
        $this->matchBudget($count, $offset, $raw);
    }

    /** Additional formats require local, concrete objects rather than page-wide markers. */
    private function concreteDetails(string $raw): iterable
    {
        $patterns = [
            'stack_trace' => [
                // A Symfony frame header: its own absolute editor location and matching line.
                <<<'REGEX'
~<div\b[^>]{0,300}\bclass=["'][^"']{0,180}\btrace-line-header\b[^"']{0,180}["'][^>]{0,300}>(?&headerByte){0,3000}?<span\b[^>]{0,180}\bclass=["'][^"']{0,100}\btrace-file-path\b[^"']{0,100}["'][^>]{0,180}>\s*in\s*<a\b[^>]{0,200}\bhref=["']file:///(?:var|home|homepages|customers|srv|opt|usr|app|www|mnt|data|Users|Applications|private|tmp)/[^"'<>\r\n]{1,350}\.php\#L(?<line>[1-9]\d{0,7})["'][^>]{0,200}>[\s\S]{0,500}?</a>\s*\(line\s+\k<line>\)(?(DEFINE)(?<headerByte>(?!</div\b)[\s\S]))~i
REGEX,
                // Two successive Module/line/function entries; HTML list items or raw
                // traceback lines also survive inside delivered comments/hidden values.
                <<<'REGEX'
~\bModule\s+[A-Za-z_][\w.]{0,179},\s+line\s+[1-9]\d{0,7},\s+in\s+[A-Za-z_][\w.]{0,119}(?:\s*</li>\s*<li>\s*|(?:\r?\n[ \t]{4,}[^\r\n<>]{0,350}){0,3}\r?\n[ \t]{2,})Module\s+[A-Za-z_][\w.]{0,179},\s+line\s+[1-9]\d{0,7},\s+in\s+[A-Za-z_][\w.]{0,119}\b~
REGEX,
            ],
            'internal_path' => [
                // Prefixless include failure. Preserve the exact raw entity quotes;
                // neither a require() operation nor an input relation is inferred.
                <<<'REGEX'
~\bFailed\s+opening\s+required\s+(?<quote>'|"|&#0*39;|&#x0*27;|&apos;|&quot;|&#0*34;|&#x0*22;)(?:/(?:var|home|homepages|customers|srv|opt|usr|app|www|mnt|data|Users|Applications|private|tmp)/|[A-Za-z]:[\\/])[^<>\r\n'"\s]{1,500}?\k<quote>\s*\(include_path\s*=\s*(?<searchquote>'|"|&#0*39;|&#x0*27;|&apos;|&quot;|&#0*34;|&#x0*22;)[^<>\r\n]{0,500}?\k<searchquote>\)~i
REGEX,
            ],
        ];
        foreach ($patterns as $kind => $family) {
            foreach ($family as $pattern) {
                $offset = 0;
                $count = 0;
                while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                    $this->candidate($raw, $m[0]);
                    $offset = $m[0][1] + strlen($m[0][0]);
                    if ($this->inCodeExample($raw, $m[0][1])) { $this->excluded(); continue; }
                    yield [$kind, $m[0]];
                    ++$count;
                }
                $this->matchBudget($count, $offset, $raw);
            }
        }
        // Pair properly nested comments with the same concrete internal template.
        // Unmatched/mismatched comments and public asset paths never establish this.
        $pattern = '~<!--\s*(start|end)\s+file:(/(?:opt|var|home|srv|app|www|mnt)/[^<>\r\n]{1,350}?/templates?/[^<>\r\n]{1,180}?\.(?:html|tpl|twig))\s*-->~i';
        $offset = 0;
        $stack = [];
        $count = 0;
        while ($count < self::MAX_MATCHES && $this->scan($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $this->candidate($raw, $m[0]);
            $offset = $m[0][1] + strlen($m[0][0]);
            if ($this->inCodeExample($raw, $m[0][1])) { $this->excluded(); continue; }
            if (strtolower($m[1][0]) === 'start') {
                if (count($stack) >= 32) {
                    $this->limit('template_stack', 'context_budget', ['limit' => 32]);
                    $stack = [];
                }
                $stack[] = [$m[2][0], $m[0][1]];
                continue;
            }
            $start = array_pop($stack);
            if ($start === null || $start[0] !== $m[2][0]) {
                $stack = [];
                continue;
            }
            if ($offset - $start[1] > 16000) {
                $this->limit('template_window', 'context_budget', ['limit' => 16000]);
                continue;
            }
            yield ['internal_path', [substr($raw, $start[1], $offset - $start[1]), $start[1]]];
            ++$count;
        }
        $this->matchBudget($count, $offset, $raw);
    }

    private function hasOpeningClass(string $raw, int $offset, string $class): bool
    {
        $quote = null;
        $length = strlen($raw);
        for ($end = $offset; $end < $length; ++$end) {
            $byte = $raw[$end];
            if ($quote !== null) { if ($byte === $quote) $quote = null; }
            elseif ($byte === '"' || $byte === "'") $quote = $byte;
            elseif ($byte === '>') break;
        }
        if ($end === $length) return false;
        $attrs = $this->htmlAttributes(substr($raw, $offset, $end - $offset));
        return in_array($class, preg_split('/\s+/', $attrs['class'] ?? ''), true);
    }

    /** A diagnostic phrase in a JSON string or explicitly introduced example is data. */
    private static function inDiagnosticExample(string $raw, int $offset): bool
    {
        $trimmed = trim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && json_validate($trimmed)) {
            $data = json_decode($trimmed, true, 32);
            if (!is_array($data)) return true;
            $rootOffset = strpos($raw, $trimmed);
            if ($rootOffset === false) return true;
            // Structured frame helpers already validate content; bind this allowance
            // to their exact root fields, never a copied labels/details object.
            if (($data['error']['status_code'] ?? null) === 500 && is_array($data['error']['details'] ?? null)) {
                foreach (array_slice($data['error']['details'], 0, 64, true) as $index => $_value) {
                    $field = JsonDiagnosticSpans::field($trimmed, ['error', 'details', $index]);
                    if ($field !== null && $rootOffset + $field['match'][1] === $offset) return false;
                }
            }
            if (is_string($data['traceback'] ?? null) && str_starts_with(ltrim($data['traceback']), 'stack traceback:')) {
                $field = JsonDiagnosticSpans::field($trimmed, ['traceback']);
                if ($field !== null && $rootOffset + $field['match'][1] === $offset) return false;
            }
            // A standalone PHP error object is itself an observed response, unlike a
            // same-shaped object nested under a localization/catalog field.
            if (array_keys($data) === ['error'] && is_array($data['error'])
                && in_array($data['error']['type'] ?? null, [1, 2, 4, 8, 64], true)
                && is_string($data['error']['file'] ?? null) && is_int($data['error']['line'] ?? null)) {
                $rootOffset = strpos($raw, $trimmed);
                return $rootOffset === false || CheckedRegex::match('~^\{\s*("error"\s*:\s*\{)~', $trimmed, $rootError, PREG_OFFSET_CAPTURE) !== 1
                    || $rootOffset + $rootError[1][1] !== $offset;
            }
            if (($data['code'] ?? null) !== 'internal_server_error' || ($data['data']['status'] ?? null) !== 500) return true;
            $error = $data['data']['error'] ?? null;
            if (!is_array($error) || !in_array($error['type'] ?? null, [1, 2, 4, 8, 64], true) || !is_string($error['file'] ?? null) || !is_int($error['line'] ?? null)) return true;
            $rootOffset = strpos($raw, $trimmed);
            if ($rootOffset === false) return true;
            return CheckedRegex::match('~"data"\s*:\s*\{\s*"status"\s*:\s*500\s*,\s*("error"\s*:\s*\{)~', $trimmed, $envelope, PREG_OFFSET_CAPTURE) !== 1
                || $rootOffset + $envelope[1][1] !== $offset;
        }
        $prefix = substr($raw, max(0, $offset - 600), min(600, $offset));
        $paragraph = strripos($prefix, '<p');
        if ($paragraph !== false && (($closing = strripos($prefix, '</p>')) === false || $closing < $paragraph)) {
            $opening = strpos($prefix, '>', $paragraph);
            if ($opening !== false && CheckedRegex::match('~^\s*(?:<[^>]{1,80}>\s*){0,3}(?:Example|Sample|For example)\s*:~i', substr($prefix, $opening + 1)) === 1) return true;
        }
        return CheckedRegex::match('~(?:^|[\r\n])\s*(?:Example|Sample|For example)\s*:[^\r\n]{0,500}$~i', $prefix) === 1;
    }

    private function diagnosticEligible(string $raw, int $offset, bool $allowComment = false, bool $tokenizedOpening = false): bool
    {
        // Regex carriers with [^>] attributes cannot obtain markup from a quoted
        // angle bracket. Script consumers already own a fully tokenized opening.
        if (!$tokenizedOpening && ($raw[$offset] ?? '') === '<' && substr_compare($raw, '<!--', $offset, 4) !== 0) {
            $quote = null;
            for ($end = $offset, $length = strlen($raw); $end < $length; ++$end) {
                $byte = $raw[$end];
                if ($quote !== null) { if ($byte === $quote) $quote = null; }
                elseif ($byte === '"' || $byte === "'") $quote = $byte;
                elseif ($byte === '>') break;
            }
            if (strpos($raw, '>', $offset) !== $end) return false;
        }
        return HtmlDiagnosticContext::eligible($raw, $offset, $allowComment, function (): void {
            $this->limit('html_ancestor_depth', 'context_budget', ['limit' => 128]);
        });
    }

    private function inTraceExample(string $raw, int $offset): bool
    {
        $prefix = substr($raw, 0, $offset);
        $open = strripos($prefix, '<code');
        $close = strripos($prefix, '</code');
        return $open !== false && ($close === false || $open > $close);
    }

    private function inCodeExample(string $raw, int $offset): bool
    {
        // Limit the guard to the local inert example. Other matches on the page
        // remain eligible, and application diagnostics are evaluated independently.
        $prefix = substr($raw, 0, $offset);
        foreach (['code', 'samp'] as $tag) {
            $open = strripos($prefix, '<'.$tag);
            $close = strripos($prefix, '</'.$tag);
            if ($open !== false && ($close === false || $open > $close)) return true;
        }
        return false;
    }

    private function adoptEvaluation(DetectorEvaluation $evaluation, string $family, string $prefix): void
    {
        foreach ($evaluation->signals as $signal) $this->run->add($signal);
        foreach ($evaluation->familyOutcomes as $outcome) {
            foreach (['candidatesConsidered', 'candidatesExcluded'] as $counter) {
                if (isset($outcome['counters'][$counter])) $this->run->count($family, $counter, $outcome['counters'][$counter]);
            }
            foreach ($outcome['stages'] as $stage) {
                foreach ($stage['issues'] as $issue) {
                    $this->run->candidate();
                    $candidate = $issue['candidate'] ?? null;
                    if ($candidate !== null) $this->run->candidate($candidate['representation'], $candidate['start'], $candidate['length']);
                    $status = $stage['status'];
                    if (in_array($status, ['limited', 'unavailable', 'failed'], true)) $this->run->$status($family, $prefix.'.'.$issue['stage'], $issue['representation'], $issue['code'], $issue['counters'] ?? []);
                }
            }
        }
    }

    private function stage(string $family, string $stage, ?string $representation, callable $work): mixed
    {
        $previous = $this->stage;
        $this->stage = $stage;
        try {
            return $this->run->stage($family, $stage, $representation, $work);
        } finally {
            $this->stage = $previous;
        }
    }

    private function candidate(string $raw, array $match): void
    {
        $this->run->candidate($this->representation, mb_strlen(substr($raw, 0, $match[1]), 'UTF-8'), mb_strlen($match[0], 'UTF-8'));
        $this->run->count($this->family, 'candidatesConsidered');
    }

    private function excluded(): void
    {
        $this->run->count($this->family, 'candidatesExcluded');
    }

    /** A subsequent failed search must not inherit the last qualified candidate. */
    private function scan(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int
    {
        $this->run->candidate();
        return CheckedRegex::match($pattern, $subject, $matches, $flags, $offset);
    }

    private function limit(string $stage, string $code, array $counters): void
    {
        $this->run->limited($this->family, $stage, $this->representation, $code, $counters);
    }

    private function matchBudget(int $count, int $offset, string $raw): void
    {
        if ($count >= self::MAX_MATCHES && $offset < strlen($raw)) {
            $this->run->limited($this->family, $this->stage.'.match_budget', $this->representation, 'match_budget', ['limit' => self::MAX_MATCHES, 'budgetCount' => $count, 'remainingCandidates' => null]);
        }
    }
}
