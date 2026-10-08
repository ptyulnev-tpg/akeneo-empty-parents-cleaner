<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Job\JobParameters;

use Akeneo\Tool\Component\Batch\Job\JobInterface;
use Akeneo\Tool\Component\Batch\Job\JobParameters\ConstraintCollectionProviderInterface;
use Akeneo\Tool\Component\Batch\Job\JobParameters\DefaultValuesProviderInterface;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Type;

/**
 * Job-Parameter: "family" (optional, null = alle Familien).
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
        return ['family' => null];
    }

    public function getConstraintCollection()
    {
        return new Collection(['fields' => ['family' => new Type('string')]]);
    }

    public function supports(JobInterface $job)
    {
        return in_array($job->getName(), $this->supportedJobNames, true);
    }
}
