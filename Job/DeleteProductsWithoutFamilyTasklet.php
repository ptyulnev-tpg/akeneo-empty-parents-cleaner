<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Job;

use Acme\Bundle\ProductCleanupBundle\Service\ProductsWithoutFamilyCleaner;
use Akeneo\Tool\Component\Batch\Job\JobRepositoryInterface;
use Akeneo\Tool\Component\Batch\Model\StepExecution;
use Akeneo\Tool\Component\Connector\Step\TaskletInterface;

/**
 * Hintergrund-Job: löscht alle Produkte ohne Familie.
 * Der Fortschritt wird nach jedem Batch gespeichert und ist im Process Tracker sichtbar.
 */
final class DeleteProductsWithoutFamilyTasklet implements TaskletInterface
{
    private const BATCH_SIZE = 100;

    private ProductsWithoutFamilyCleaner $cleaner;
    private JobRepositoryInterface $jobRepository;
    private ?StepExecution $stepExecution = null;

    public function __construct(ProductsWithoutFamilyCleaner $cleaner, JobRepositoryInterface $jobRepository)
    {
        $this->cleaner = $cleaner;
        $this->jobRepository = $jobRepository;
    }

    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
    }

    public function execute()
    {
        if (null === $this->stepExecution) {
            throw new \LogicException(sprintf('In order to execute "%s" you need to set a step execution.', self::class));
        }

        $stepExecution = $this->stepExecution;
        $stepExecution->setTotalItems($this->cleaner->count());
        $stepExecution->addSummaryInfo('deleted_products', 0);

        $this->cleaner->delete(self::BATCH_SIZE, function (int $deletedInBatch) use ($stepExecution): void {
            $stepExecution->incrementSummaryInfo('deleted_products', $deletedInBatch);
            $stepExecution->incrementProcessedItems($deletedInBatch);
            $this->jobRepository->updateStepExecution($stepExecution);
        });
    }
}
