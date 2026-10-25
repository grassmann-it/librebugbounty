<?php

namespace App\Command;

use App\Service\ExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:export:json', description: 'Export domains, findings, evidence metadata, and retest runs as JSON.')]
final class ExportJsonCommand extends Command
{
    public function __construct(private readonly ExportService $exportService, private readonly \App\Service\FindingListService $lists)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Restrict to a hostname.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Restrict to a finding status.')
            ->addOption('contacted-only', null, InputOption::VALUE_NONE, 'Only export findings that have been marked contacted.')
        ;
        CribrumSelectionOptions::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $query = CribrumSelectionOptions::query($input);
        if ($query !== []) {
            try {
                [$filter] = $this->lists->parse($query + array_filter([
                    'domain' => $input->getOption('domain'),
                    'exact_domain' => $input->getOption('domain') ? '1' : null,
                    'legacy_status' => $input->getOption('status'),
                    'contact' => $input->getOption('contacted-only') ? 'yes' : null,
                ], static fn ($value) => $value !== null));
                $data = $this->exportService->exportSelection($filter);
            } catch (\InvalidArgumentException $error) {
                $output->writeln('<error>'.$error->getMessage().'</error>');
                return Command::INVALID;
            }
        } else {
            $data = $this->exportService->export(
                $input->getOption('domain') ?: null,
                $input->getOption('status') ?: null,
                (bool) $input->getOption('contacted-only'),
            );
        }
        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
