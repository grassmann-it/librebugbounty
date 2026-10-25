<?php

namespace App\Command;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:domain:export', description: 'Export domains and optional status overviews.')]
final class DomainExportCommand extends Command
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly ?\App\Repository\FindingReadRepository $findings = null,
        private readonly ?\App\Service\FindingListService $lists = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Export domains in the normal view; discarded-only domains are excluded.')
            ->addOption('contacted-only', null, InputOption::VALUE_NONE, 'Only export domains that have at least one contacted finding.')
            ->addOption('overview', null, InputOption::VALUE_NONE, 'Group domains by raw legacy status fixed, contact timestamp, or neither; fixed does not prove a manual assessment.')
            ->addOption('authorized-only', null, InputOption::VALUE_NONE, 'Only include verified domains.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: plain or json.', 'plain')
        ;
        CribrumSelectionOptions::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $exportAll = (bool) $input->getOption('all');
        $contactedOnly = (bool) $input->getOption('contacted-only');
        $overview = (bool) $input->getOption('overview');
        $authorizedOnly = (bool) $input->getOption('authorized-only');
        $format = strtolower(trim((string) $input->getOption('format')));
        if (!in_array($format, ['plain', 'json'], true)) {
            throw new \InvalidArgumentException('Unsupported format. Use plain or json.');
        }

        if (($exportAll && $contactedOnly) || ($overview && ($exportAll || $contactedOnly))) {
            throw new \InvalidArgumentException('Use only one of --all, --contacted-only, or --overview.');
        }

        if ($overview) {
            $domains = $this->domains->findAllOrdered($authorizedOnly);
        } elseif ($exportAll) {
            $domains = $this->domains->findAllOrdered($authorizedOnly);
        } elseif ($contactedOnly) {
            $domains = $this->domains->findAllWithContactedFindings($authorizedOnly);
        } else {
            $domains = $this->domains->findAllWithoutContactedOrFixedFindings($authorizedOnly);
        }

        $selection = CribrumSelectionOptions::query($input);
        $members = [];
        if ($selection !== []) {
            if ($this->lists === null || $this->findings === null) {
                throw new \LogicException('Cribrum selection services unavailable.');
            }
            try {
                [$filter] = $this->lists->parse($selection);
            } catch (\InvalidArgumentException $error) {
                $io->error($error->getMessage());
                return Command::INVALID;
            }
            foreach ($this->findings->findPage($filter, max(1, $this->findings->count($filter))) as $finding) {
                $members[$finding->domain][] = $finding->id;
            }
            $domains = array_values(array_filter($domains, static fn (Domain $domain): bool => isset($members[$domain->getHostname()])));
            // First matching member defines the group's position in the global
            // order; member IDs retain that same deterministic finding order.
            $rank = array_flip(array_keys($members));
            usort($domains, static fn (Domain $a, Domain $b): int => $rank[$a->getHostname()] <=> $rank[$b->getHostname()]);
            if ($format === 'plain') $io->note('URL-only domain output contains no badge data; each domain follows its best matching finding.');
        }

        if ($format === 'json') {
            if ($overview) {
                throw new \InvalidArgumentException('Overview output is only available in plain format.');
            }

            $rows = array_map(static fn ($domain): array => [
                'hostname' => $domain->getHostname(),
                'scheme' => $domain->getScheme(),
                'authorized' => $domain->isAuthorized(),
                'findings' => $selection !== [] ? count($members[$domain->getHostname()]) : $domain->getFindings()->filter(static fn ($finding): bool => !$finding->isDiscarded())->count(),
                ...($selection !== [] ? ['matchingFindingIds' => $members[$domain->getHostname()]] : []),
            ], $domains);

            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if ($overview) {
            $this->renderOverview($output, $domains, $members);

            return Command::SUCCESS;
        }

        foreach ($domains as $domain) {
            $output->writeln($domain->getHostname());
        }

        if ($domains === []) {
            $io->success(
                $exportAll ? 'No domains found.' : ($contactedOnly ? 'No contacted domains found.' : 'No domains without a contact timestamp or raw status fixed found.')
            );
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<Domain> $domains
     */
    private function renderOverview(OutputInterface $output, array $domains, array $members = []): void
    {
        $groups = [
            'Altstatus fixed' => [],
            'marked contacted' => [],
            'uncontacted' => [],
        ];

        foreach ($domains as $domain) {
            if ($domain->getFindings()->filter(static fn ($finding): bool => !$finding->isDiscarded())->isEmpty()) {
                continue;
            }

            if ($this->hasFixedFinding($domain, $members[$domain->getHostname()] ?? null)) {
                $groups['Altstatus fixed'][] = $domain;

                continue;
            }

            if ($this->hasContactedFinding($domain, $members[$domain->getHostname()] ?? null)) {
                $groups['marked contacted'][] = $domain;

                continue;
            }

            $groups['uncontacted'][] = $domain;
        }

        foreach ($groups as $title => $groupDomains) {
            $output->writeln(sprintf('== %s (%d) ==', $title, count($groupDomains)));

            if ($groupDomains === []) {
                $output->writeln('(none)');
                $output->writeln('');
                continue;
            }

            foreach ($groupDomains as $domain) {
                $output->writeln($domain->getHostname());
            }

            $output->writeln('');
        }
    }

    private function hasFixedFinding(Domain $domain, ?array $matchingIds = null): bool
    {
        foreach ($domain->getFindings() as $finding) {
            if ($matchingIds !== null && !in_array($finding->getId(), $matchingIds, true)) continue;
            if (!$finding->isDiscarded() && $finding->getStatus() === 'fixed') {
                return true;
            }
        }

        return false;
    }

    private function hasContactedFinding(Domain $domain, ?array $matchingIds = null): bool
    {
        foreach ($domain->getFindings() as $finding) {
            if ($matchingIds !== null && !in_array($finding->getId(), $matchingIds, true)) continue;
            if (!$finding->isDiscarded() && $finding->getContactedAt() !== null) {
                return true;
            }
        }

        return false;
    }
}
