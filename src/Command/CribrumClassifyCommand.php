<?php

namespace App\Command;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Service\CribrumProbeSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:cribrum:classify', description: 'Classify one URL or a local HTTP response without creating a case. URL mode performs one HTTP GET; file/stdin mode is offline.')]
final class CribrumClassifyCommand extends Command
{
    public function __construct(private readonly CribrumClassifier $classifier, private readonly CribrumProbeSource $source)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'URL to fetch, or context only when --response is provided.')
            ->addOption('response', null, InputOption::VALUE_REQUIRED, 'Read a decoded HTTP response or body from a local file; use - for stdin. No network request.')
            ->addOption('dom', null, InputOption::VALUE_REQUIRED, 'Optional captured DOM text file; use - for stdin.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'HTTP status for a body-only input, or an explicit override.')
            ->addOption('content-type', null, InputOption::VALUE_REQUIRED, 'Content-Type for a body-only input, or an explicit override.')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'Original payload context (does not change the requested URL).')
            ->addOption('marker', null, InputOption::VALUE_REQUIRED, 'Expected reflection marker. HTTP input cannot prove browser execution.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Include all rule hits and exclusions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        try {
            if (!in_array($format, ['text', 'json'], true)) throw new \InvalidArgumentException('Use --format=text or --format=json.');
            $url = $input->getArgument('url');
            $file = $input->getOption('response');
            $domFile = $input->getOption('dom');
            if ($url === null && $file === null) throw new \InvalidArgumentException('Provide a URL or --response=FILE (or --response=- for stdin).');
            if ($url !== null) CribrumProbeSource::validateUrl($url);
            if ($file === '-' && $domFile === '-') throw new \InvalidArgumentException('Only one input may read from stdin.');
            $status = $input->getOption('status');
            if ($status !== null && !preg_match('/^[1-5][0-9]{2}$/D', $status)) throw new \InvalidArgumentException('--status must be an HTTP status between 100 and 599.');
            $type = $input->getOption('content-type');
            if ($type !== null && ($type === '' || preg_match('/[\x00-\x1f\x7f]/', $type))) throw new \InvalidArgumentException('--content-type must be a non-empty header value.');
            // Read/validate all local options before any target request.
            $dom = $domFile === null ? null : $this->read($domFile, CribrumProbeSource::DOM_LIMIT, $input);
            if ($dom !== null) CribrumProbeSource::validateText($dom);
            $capture = $file !== null
                ? $this->source->parse($this->read($file, CribrumProbeSource::RAW_LIMIT + CribrumProbeSource::HEADER_LIMIT, $input))
                : $this->source->fetch($url);
            $observation = new ObservationInput(
                $url ?? 'https://response.invalid/', $capture['body'], $dom,
                $type ?? $capture['contentType'], $status === null ? $capture['httpStatus'] : (int) $status,
                $capture['finalUrl'], $input->getOption('payload'), $input->getOption('marker'), false, null,
            );
            $rules = $input->getOption('explain') ? [] : null;
            $result = $this->classifier->classify($observation, $rules);
            $warnings = ['HTTP/DOM text only: JavaScript is not executed and no native dialog is observed. Classification is not a manual assessment.'];
            if ($url === null) $warnings[] = 'No URL context supplied; URL-parameter-dependent rules may not match.';
            if ($observation->httpStatus === null) $warnings[] = 'HTTP status is unknown; status-dependent rules may not match. Use --status or provide HTTP headers.';
            if ($capture['httpStatus'] >= 300 && $capture['httpStatus'] < 400) $warnings[] = 'Redirect response: redirects are not followed. Supply the destination URL explicitly to test it.';
            $report = [
                'source' => ['mode' => $file === null ? 'url' : ($file === '-' ? 'stdin' : 'file'),
                    'url' => $observation->url, 'finalUrl' => $observation->finalUrl,
                    'httpStatus' => $observation->httpStatus, 'contentType' => $observation->contentType,
                    'rawBytes' => strlen($observation->rawHtml), 'domBytes' => $dom === null ? null : strlen($dom)],
                'classification' => $result->toArray(), 'warnings' => $warnings,
            ];
            if ($rules !== null) $report['rules'] = $rules;
            if ($format === 'json') {
                $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                $this->render($report, $input, $output);
            }

            return $result->diagnostics === [] ? Command::SUCCESS : Command::FAILURE;
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            if ($format === 'json') {
                $output->writeln(json_encode(['error' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                (new SymfonyStyle($input, $output))->error($this->safe($error->getMessage()));
            }

            return $error instanceof \InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }

    private function read(string $path, int $limit, InputInterface $input): string
    {
        if ($path === '-') {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $stream ??= fopen('php://stdin', 'rb');
        } else {
            if (!is_file($path) || !is_readable($path)) throw new \RuntimeException('Input must be a readable local file.');
            $stream = @fopen($path, 'rb');
        }
        if ($stream === false) throw new \RuntimeException('Could not read input.');
        try {
            $contents = stream_get_contents($stream, $limit + 1);
            if ($contents === false) throw new \RuntimeException('Could not read input.');
            if (strlen($contents) > $limit) throw new \InvalidArgumentException('Input exceeds the '.$limit.' byte limit.');
            return $contents;
        } finally {
            if ($path !== '-') fclose($stream);
        }
    }

    private function render(array $report, InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);
        $source = $report['source'];
        $result = $report['classification'];
        $io->title('Cribrum classification');
        $io->text($this->safe($source['url']));
        $io->text(sprintf('Source: %s | HTTP: %s | Content-Type: %s | Body: %d bytes', $source['mode'],
            $source['httpStatus'] ?? 'unknown', $this->safe($source['contentType'] ?? 'unknown'), $source['rawBytes']));
        $io->text('Classifier: '.$this->safe($result['classifierVersion']));
        foreach ($result['packs'] as $id => $version) $io->text('Pack: '.$this->safe($id.'@'.$version));
        if ($result['suggestions'] === []) $io->text('No claim suggestions. This does not prove that the response is safe.');
        foreach ($result['suggestions'] as $suggestion) {
            $io->section($this->safe($suggestion['claim'].' / '.$suggestion['state'].' (score '.$suggestion['score'].')'));
            $io->text('Rules: '.$this->safe(implode(', ', $suggestion['ruleIds'])));
            foreach ($suggestion['reasons'] as $reason) $io->text($this->safe($reason));
            foreach ($suggestion['spans'] as $span) $io->text($this->safe($span['representation'].': '.$span['excerpt']));
        }
        foreach ($result['signals'] as $signal) {
            $io->text('Signal: '.$this->safe($signal['pack'].'/'.$signal['rule'].(!empty($signal['shadow']) ? ' (shadow)' : '')));
        }
        foreach ($report['rules'] ?? [] as $rule) {
            $io->text($this->safe($rule['pack'].'/'.$rule['rule'].': '.$rule['outcome'].' '.($rule['exclusion'] ?? $rule['error'] ?? '')));
        }
        foreach ([...$report['warnings'], ...$result['diagnostics']] as $warning) $io->warning($this->safe($warning));
    }

    private function safe(string $value): string
    {
        // Response excerpts must remain literal, not terminal/Console commands.
        return OutputFormatter::escape(preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $value) ?? '');
    }
}
