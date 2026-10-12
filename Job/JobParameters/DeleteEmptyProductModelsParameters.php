<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Job\JobParameters;

use Akeneo\Tool\Component\Batch\Job\JobInterface;
use Akeneo\Tool\Component\Batch\Job\JobParameters\ConstraintCollectionProviderInterface;
use Akeneo\Tool\Component\Batch\Job\JobParameters\DefaultValuesProviderInterface;
use Acme\Bundle\ProductCleanupBundle\Service\EmptyProductModelSelection;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Type;

/**
 * Job-Parameter (jeweils null = nicht gesetzt): "family" (nur diese Familie), "limit" (die ersten N nach Code),
 * "codes" (nur diese Codes). Siehe EmptyProductModelSelection.
 */
final class DeleteEmptyProductModelsParameters implements ConstraintCollectionProviderInterface, DefaultValuesProviderInterface
{
    private array $supportedJobNames;

    public function __construct(array $supportedJobNames)
    {
        $this->supportedJobNames = $supportedJobNames;
    }

    public function getDefaultValues()
    {
        return ['family' => null, 'limit' => null, 'codes' => null];
    }

    public function getConstraintCollection()
    {
        return new Collection(['fields' => [
            'family' => new Type('string'),
            'limit' => [new Type('integer'), new Range(['min' => 1])],
            'codes' => [new Type('array'), new Count(['min' => 1, 'max' => EmptyProductModelSelection::MAX_CODES])],
        ]]);
    }

    public function supports(JobInterface $job)
    {
        return in_array($job->getName(), $this->supportedJobNames, true);
    }
}
