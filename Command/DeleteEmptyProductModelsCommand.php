<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Command;

use Acme\Bundle\ProductCleanupBundle\Service\EmptyProductModelCleaner;
use Acme\Bundle\ProductCleanupBundle\Service\EmptyProductModelSelection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zählt (Dry-Run, Standard) oder löscht (--force) leere Product Models (Root-Models ohne Varianten).
 */
final class DeleteEmptyProductModelsCommand extends Command
{
    protected static $defaultName = 'acme:product-model:delete-empty';

    private const DEFAULT_BATCH_SIZE = 100;

    private EmptyProductModelCleaner $cleaner;

    public function __construct(EmptyProductModelCleaner $cleaner)
    {
        parent::__construct();

        $this->cleaner = $cleaner;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Counts (dry-run) or deletes root product models without any variant product')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Really delete. Without it, the command only counts (dry-run).')
            ->addOption('family', null, InputOption::VALUE_REQUIRED, 'Only product models of this family')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Only the first N empty product models (ordered by code)')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Dry-run only: also print the codes of the affected product models')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of product models deleted per batch', (string) self::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');
        $family = $input->getOption('family');
        $limit = $input->getOption('limit');

        if ($batchSize < 1) {
            $io->error('--batch-size must be a positive integer.');

            return 1;
        }
        if (null !== $limit && (int) $limit < 1) {
            $io->error('--limit must be a positive integer.');

            return 1;
        }

        $selection = null === $limit
            ? EmptyProductModelSelection::all($family)
            : EmptyProductModelSelection::first((int) $limit, $family);
        $total = $this->cleaner->countSelection($selection);

        if (!$input->getOption('force')) {
            return $this->dryRun($io, $total, $selection, (bool) $input->getOption('list'));
        }

        if (0 === $total) {
            $io->success('No empty product models found. Nothing to delete.');

            return 0;
        }

        // Mit -n (non-interactive) wird ohne Rückfrage gelöscht, z. B. für Cron/Skripte
        if ($input->isInteractive()
            && !$io->confirm(sprintf('Really delete %d empty product models?', $total), false)
        ) {
            $io->warning('Aborted. No product model was deleted.');

            return 1;
        }

        $io->progressStart($total);
        $deleted = $this->cleaner->delete($batchSize, $selection, static function (int $deletedInBatch) use ($io): void {
            $io->progressAdvance($deletedInBatch);
        });
        $io->progressFinish();

        $io->success(sprintf('%d empty product models deleted.', $deleted));

        return 0;
    }

    private function dryRun(SymfonyStyle $io, int $total, EmptyProductModelSelection $selection, bool $list): int
    {
        $io->note('DRY-RUN: nothing will be deleted. Use --force to delete.');

        if (null === $selection->family()) {
            $io->table(['Family', 'Empty product models'], array_map(
                static fn (string $code, int $count): array => [$code, $count],
                array_keys($counts = $this->cleaner->countByFamily()),
                $counts
            ));
        }

        if ($list) {
            foreach ($this->cleaner->findCodes($selection) as $code) {
                $io->writeln($code);
            }
        }

        $io->success(sprintf('%d empty product models would be deleted.', $total));

        return 0;
    }
}
