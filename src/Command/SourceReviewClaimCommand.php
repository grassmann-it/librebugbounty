<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SourceReviewService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:source-review:claim', description: 'Reserve retained local sources for AI reading. Never invokes a browser or marks them reviewed.')]
final class SourceReviewClaimCommand extends Command
{
    public function __construct(private readonly SourceReviewService $reviews) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('reviewer', null, InputOption::VALUE_REQUIRED, 'Accurate worker/model label, for example Codex / model-name.')
            ->addOption('finding', null, InputOption::VALUE_REQUIRED, 'Restrict to one finding UUID.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of reservations (1–20).', '1')
            ->addOption('lease-seconds', null, InputOption::VALUE_REQUIRED, 'Reservation lifetime (30–3600 seconds).', '900');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $limit = $input->getOption('limit'); $seconds = $input->getOption('lease-seconds');
            if (!is_string($limit) || !ctype_digit($limit) || !is_string($seconds) || !ctype_digit($seconds)) throw new \InvalidArgumentException('Limit and lease duration must be whole numbers.');
            $claims = $this->reviews->claim((string) $input->getOption('reviewer'), (int) $limit, $input->getOption('finding'), (int) $seconds);
            $output->writeln(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
