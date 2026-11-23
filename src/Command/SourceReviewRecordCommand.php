<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SourceReviewService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:source-review:record', description: 'Record an actual AI source reading against its reservation; never alters security claims or manual assessment.')]
final class SourceReviewRecordCommand extends Command
{
    public function __construct(private readonly SourceReviewService $reviews) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('lease', InputArgument::REQUIRED, 'Reservation token from app:source-review:claim.')
            ->addOption('outcome', null, InputOption::VALUE_REQUIRED, implode(', ', SourceReviewService::OUTCOMES))
            ->addOption('summary', null, InputOption::VALUE_REQUIRED, 'Concise source-reading result; not a security confirmation.')
            ->addOption('summary-file', null, InputOption::VALUE_REQUIRED, 'UTF-8 text file instead of --summary.')
            ->addOption('classifier-version', null, InputOption::VALUE_REQUIRED, 'Classifier version actually evaluated by the reviewer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $summary = $input->getOption('summary'); $file = $input->getOption('summary-file');
            if (($summary === null) === ($file === null)) throw new \InvalidArgumentException('Provide exactly one of --summary and --summary-file.');
            if ($file !== null) {
                if (!is_file($file) || !is_readable($file) || filesize($file) > 16000) throw new \InvalidArgumentException('Summary file must be readable and at most 16000 bytes.');
                $summary = file_get_contents($file);
                if ($summary === false) throw new \InvalidArgumentException('Summary file could not be read.');
            }
            $receipt = $this->reviews->record((string) $input->getArgument('lease'), (string) $input->getOption('outcome'), (string) $summary, (string) $input->getOption('classifier-version'));
            $output->writeln(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        } catch (\InvalidArgumentException $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');
            return Command::INVALID;
        } catch (\UnexpectedValueException $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
