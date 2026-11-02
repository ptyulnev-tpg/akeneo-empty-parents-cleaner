<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Command;

use Acme\Bundle\ProductCleanupBundle\Service\ProductsWithoutFamilyCleaner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zählt (Dry-Run, Standard) oder löscht (--force) Produkte ohne Familie.
 */
final class DeleteProductsWithoutFamilyCommand extends Command
{
    protected static $defaultName = 'acme:product:delete-without-family';

    private const DEFAULT_BATCH_SIZE = 100;

    private ProductsWithoutFamilyCleaner $cleaner;

    public function __construct(ProductsWithoutFamilyCleaner $cleaner)
    {
        parent::__construct();

        $this->cleaner = $cleaner;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Counts (dry-run) or deletes products without family')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Really delete. Without it, the command only counts (dry-run).')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Dry-run only: also print the identifiers of the affected products')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of products deleted per batch', (string) self::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');

        if ($batchSize < 1) {
            $io->error('--batch-size must be a positive integer.');

            return 1;
        }

        $total = $this->cleaner->count();

        if (!$input->getOption('force')) {
            $io->note('DRY-RUN: nothing will be deleted. Use --force to delete.');
            if ($input->getOption('list')) {
                foreach ($this->cleaner->findIdentifiers() as $identifier) {
                    $io->writeln($identifier);
                }
            }
            $io->success(sprintf('%d products without family would be deleted.', $total));

            return 0;
        }

        if (0 === $total) {
            $io->success('No products without family found. Nothing to delete.');

            return 0;
        }

        // Mit -n (non-interactive) wird ohne Rückfrage gelöscht, z. B. für Cron/Skripte
        if ($input->isInteractive()
            && !$io->confirm(sprintf('Really delete %d products without family?', $total), false)
        ) {
            $io->warning('Aborted. No product was deleted.');

            return 1;
        }

        $io->progressStart($total);
        $deleted = $this->cleaner->delete($batchSize, static function (int $deletedInBatch) use ($io): void {
            $io->progressAdvance($deletedInBatch);
        });
        $io->progressFinish();

        $io->success(sprintf('%d products without family deleted. See Activity > Deletion log.', $deleted));

        return 0;
    }
}
