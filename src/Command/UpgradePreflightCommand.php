<?php

namespace App\Command;

use App\Service\UpgradePreflightService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:upgrade:preflight', description: 'Read-only, bounded upgrade permission diagnostics; never repairs files.')]
final class UpgradePreflightCommand extends Command
{
    public function __construct(private readonly UpgradePreflightService $preflight)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Read-only upgrade preflight (current process permissions; no repair or write probes).');
        $success = true;
        foreach ($this->preflight->inspect() as $result) {
            $output->writeln(sprintf('[%s] %s: %s', $result['state'], $result['path'], $result['message']), OutputInterface::OUTPUT_RAW);
            if ($result['state'] !== 'ok') {
                $success = false;
                $output->writeln('  '.$result['remedy'].' See '.$result['doc'], OutputInterface::OUTPUT_RAW);
            }
        }

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
