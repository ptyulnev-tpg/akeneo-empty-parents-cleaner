<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Job;

use Acme\Bundle\ProductCleanupBundle\Service\EmptyProductModelCleaner;
use Akeneo\Tool\Component\Batch\Job\JobRepositoryInterface;
use Akeneo\Tool\Component\Batch\Model\StepExecution;
use Akeneo\Tool\Component\Connector\Step\TaskletInterface;

/**
 * Hintergrund-Job: löscht leere Product Models, optional nur einer Familie (Job-Parameter "family").
 * Der Fortschritt wird nach jedem Batch gespeichert und ist im Process Tracker sichtbar.
 */
final class DeleteEmptyProductModelsTasklet implements TaskletInterface
{
    private const BATCH_SIZE = 100;

    private EmptyProductModelCleaner $cleaner;
    private JobRepositoryInterface $jobRepository;
    private ?StepExecution $stepExecution = null;

    public function __construct(EmptyProductModelCleaner $cleaner, JobRepositoryInterface $jobRepository)
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
        $family = $stepExecution->getJobParameters()->get('family');

        $stepExecution->setTotalItems($this->cleaner->count($family));
        $stepExecution->addSummaryInfo('deleted_product_models', 0);

        $this->cleaner->delete(self::BATCH_SIZE, $family, function (int $deletedInBatch) use ($stepExecution): void {
            $stepExecution->incrementSummaryInfo('deleted_product_models', $deletedInBatch);
            $stepExecution->incrementProcessedItems($deletedInBatch);
            $this->jobRepository->updateStepExecution($stepExecution);
        });
    }
}
