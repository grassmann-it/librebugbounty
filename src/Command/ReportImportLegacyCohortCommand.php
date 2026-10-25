<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\LegacyCohortService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:report:import-legacy-cohort', description: 'Explicitly import the authorized fixed E2 manifest into the selected report source.')]
final class ReportImportLegacyCohortCommand extends Command
{
    public function __construct(private readonly LegacyCohortService $cohort) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('manifest', InputArgument::REQUIRED)->addArgument('source-instance-id', InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->cohort->importManifest((string) $input->getArgument('manifest'), (string) $input->getArgument('source-instance-id'));
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
        return ($result['missing'] === [] && $result['pocChanged'] === []) ? Command::SUCCESS : Command::FAILURE;
    }
}
