<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** Shared public CLI vocabulary; query parsing remains FindingListService's job. */
final class CribrumSelectionOptions
{
    public static function configure(Command $command): void
    {
        $command->addOption('cribrum', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Cribrum code; repeat for OR within this facet.')
            ->addOption('extra-findings', null, InputOption::VALUE_REQUIRED, 'Additional findings: yes or no.')
            ->addOption('new-findings', null, InputOption::VALUE_REQUIRED, 'New semantic findings since review: yes or no.')
            ->addOption('cribrum-freshness', null, InputOption::VALUE_REQUIRED, 'Projection: current, missing or stale.')
            ->addOption('cribrum-history', null, InputOption::VALUE_NONE, 'Explicitly include historical badge statements.')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'default, additional_first, cribrum_changed or additional_count.')
            ->addOption('claim', null, InputOption::VALUE_REQUIRED, 'Claim code.')
            ->addOption('claim-state', null, InputOption::VALUE_REQUIRED, 'State of the same matching claim.')
            ->addOption('report-kind', null, InputOption::VALUE_REQUIRED, 'Report kind.');
    }

    /** @return array<string, string|list<string>> */
    public static function query(InputInterface $input): array
    {
        $query = [];
        foreach (['cribrum', 'extra-findings', 'new-findings', 'cribrum-freshness', 'sort', 'claim', 'claim-state', 'report-kind'] as $name) {
            $value = $input->getOption($name);
            if ($value !== null && $value !== [] && $value !== '') $query[str_replace('-', '_', $name)] = $value;
        }
        if ($input->getOption('cribrum-history')) $query['cribrum_history'] = '1';
        return $query;
    }
}
