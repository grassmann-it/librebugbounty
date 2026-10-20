<?php

namespace App\Command;

use App\Service\CribrumReclassifyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:cribrum:reclassify', description: 'Re-run current Cribrum rule packs against stored observations. Local only; manual claim decisions are preserved.')]
final class CribrumReclassifyCommand extends Command
{
    public function __construct(private readonly CribrumReclassifyService $reclassify)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('finding', null, InputOption::VALUE_REQUIRED, 'Restrict reclassification to one finding ID.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Classify without writing claim suggestions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $finding = $input->getOption('finding');
        if ($finding !== null && (!is_string($finding) || trim($finding) === '')) {
            $io->error('--finding must be a non-empty finding ID.');

            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $summary = $this->reclassify->reclassify($finding === null ? null : trim($finding), $dryRun);
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        }
        $io->text(($dryRun ? 'Dry run: nothing was written. ' : '').sprintf(
            'Reclassified %d observations (%d suggestions, %d skipped without artifact).',
            $summary['processed'],
            $summary['suggestions'],
            $summary['skipped'],
        ));
        if ($summary['errors'] !== []) {
            $io->warning(array_merge(['Some observations could not be reclassified:'], $summary['errors']));

            return Command::FAILURE;
        }
        $io->success('Reclassification finished.');

        return Command::SUCCESS;
    }
}
