<?php

declare(strict_types=1);
namespace App\Command;

use App\Entity\Finding;
use App\Entity\HttpObservation;
use App\Service\ReportProjectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:report:refresh', description: 'Explicit offline report projection refresh from retained local evidence; no browser work.')]
final class ReportRefreshCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entities, private readonly ReportProjectionService $reports) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('finding',null,InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY,'Finding ID to refresh; repeat to select several IDs.')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Refresh at most this many findings in stable ID order (1–10000).')
            ->addOption('all',null,InputOption::VALUE_NONE,'Explicitly refresh the entire inventory.')
            ->addOption('dry-run',null,InputOption::VALUE_NONE,'Evaluate selected projections, then roll back every write.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io=new SymfonyStyle($input,$output);
        $ids=$input->getOption('finding'); $limit=$input->getOption('limit'); $all=(bool)$input->getOption('all');
        $selectors=(int)($ids!==[])+(int)($limit!==null)+(int)$all;
        if ($selectors!==1 || array_filter($ids,static fn($id): bool => !is_string($id) || trim($id)==='')!==[] || ($limit!==null && (!ctype_digit((string)$limit) || (int)$limit<1 || (int)$limit>10000))) {
            $io->error('Choose exactly one selector: repeated --finding=ID, --limit=1..10000, or explicit --all.');
            return Command::INVALID;
        }
        if (!$this->reports->available()) { $io->error('Report projection schema is not installed. Run the reviewed migration in the intended isolated source first.'); return Command::FAILURE; }
        $db=$this->entities->getConnection();
        if ($ids===[]) $ids=$db->fetchFirstColumn('SELECT id FROM finding ORDER BY id'.($limit===null?'':' LIMIT '.(int)$limit));
        $ids=array_values(array_unique($ids));
        $dry=(bool)$input->getOption('dry-run'); $processed=0; $errors=0;
        foreach ($ids as $id) {
            $finding=$this->entities->find(Finding::class,$id);
            if (!$finding instanceof Finding) { $io->warning('Finding not found: '.$id); ++$errors; continue; }
            // Shadow-only observations never replace the selected active interpretation.
            $observationId=$db->fetchOne("SELECT o.id FROM http_observation o WHERE o.finding_id=? AND o.poc_revision=? AND o.artifact_path IS NOT NULL AND o.artifact_sha256 IS NOT NULL AND (json_extract(COALESCE(o.classification,'{}'),'$.coverage.mode')='active' OR EXISTS(SELECT 1 FROM json_each(COALESCE(o.classification,'{}'),'$.signals') WHERE json_extract(value,'$.mode')='active')) ORDER BY o.created_at DESC,o.classified_at DESC,o.id DESC LIMIT 1",[$id,$finding->getPocRevision()]);
            $observation=$observationId===false?null:$this->entities->find(HttpObservation::class,$observationId);
            if ($dry) $db->beginTransaction();
            try {
                $snapshot=$this->reports->refresh($finding,$observation instanceof HttpObservation?$observation:null);
                $io->text(sprintf('%s %s: %s / %s (%s)', $dry?'Would refresh':'Refreshed',$id,$snapshot['gate'] ?? 'unknown',$snapshot['caseSummary']['kind'] ?? 'unknown',$observationId===false?'independent proof/context':'retained active observation '.$observationId));
                ++$processed;
            } catch (\Throwable $error) { ++$errors; $io->warning($id.': '.$error->getMessage()); }
            finally { if ($dry) $db->rollBack(); }
        }
        $io->text(sprintf('%s%d findings evaluated, %d errors. No browser or capture jobs were requested.',$dry?'Dry run; all projection writes rolled back. ':'',$processed,$errors));
        return $errors===0?Command::SUCCESS:Command::FAILURE;
    }
}
