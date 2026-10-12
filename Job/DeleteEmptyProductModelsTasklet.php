<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Job;

use Acme\Bundle\ProductCleanupBundle\Service\EmptyProductModelCleaner;
use Acme\Bundle\ProductCleanupBundle\Service\EmptyProductModelSelection;
use Akeneo\Tool\Component\Batch\Job\JobRepositoryInterface;
use Akeneo\Tool\Component\Batch\Model\StepExecution;
use Akeneo\Tool\Component\Connector\Step\TaskletInterface;
use Psr\Log\LoggerInterface;

/**
 * Hintergrund-Job: löscht leere Product Models laut Job-Parametern family / limit / codes
 * (siehe EmptyProductModelSelection).
 * Der Fortschritt wird nach jedem Batch gespeichert und ist im Process Tracker sichtbar; jedes gelöschte Model
 * steht mit Code, Familie und Bezeichnung in der Job-Log-Datei (Process Tracker → "Download log").
 */
final class DeleteEmptyProductModelsTasklet implements TaskletInterface
{
    private const BATCH_SIZE = 100;

    private EmptyProductModelCleaner $cleaner;
    private JobRepositoryInterface $jobRepository;
    private LoggerInterface $logger;
    private ?StepExecution $stepExecution = null;

    public function __construct(
        EmptyProductModelCleaner $cleaner,
        JobRepositoryInterface $jobRepository,
        LoggerInterface $batchLogger
    ) {
        $this->cleaner = $cleaner;
        $this->jobRepository = $jobRepository;
        $this->logger = $batchLogger;
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
        $parameters = $stepExecution->getJobParameters();
        $selection = EmptyProductModelSelection::fromArray([
            'family' => $parameters->get('family'),
            'limit' => $parameters->get('limit'),
            'codes' => $parameters->get('codes'),
        ]);

        $total = $this->cleaner->countSelection($selection);
        $stepExecution->setTotalItems($total);
        $stepExecution->addSummaryInfo('deleted_product_models', 0);
        $this->logger->info(sprintf(
            'Deleting empty product models: %d expected, user=%s, selection=%s',
            $total,
            (string) $stepExecution->getJobExecution()->getUser(),
            json_encode($selection->toArray())
        ));

        $deleted = $this->cleaner->delete(self::BATCH_SIZE, $selection, function (array $deletedInBatch) use ($stepExecution): void {
            foreach ($deletedInBatch as $model) {
                $this->logger->info(sprintf(
                    'Deleted product model %s | family: %s | label: %s | empty sub-models: %d',
                    $model['code'],
                    $model['family'],
                    $model['label'],
                    $model['sub_models']
                ));
            }
            $stepExecution->incrementSummaryInfo('deleted_product_models', count($deletedInBatch));
            $stepExecution->incrementProcessedItems(count($deletedInBatch));
            $this->jobRepository->updateStepExecution($stepExecution);
        });

        $this->logger->info(sprintf('Deleted %d empty product models', $deleted));
    }
}
