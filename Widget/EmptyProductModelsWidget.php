<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Widget;

use Acme\Bundle\ProductCleanupBundle\Service\EmptyProductModelCleaner;
use Akeneo\Platform\Bundle\DashboardBundle\Widget\WidgetInterface;

/**
 * Dashboard-Widget: Anzahl leerer Product Models je Familie, mit Link zur Detailseite.
 */
final class EmptyProductModelsWidget implements WidgetInterface
{
    private EmptyProductModelCleaner $cleaner;

    public function __construct(EmptyProductModelCleaner $cleaner)
    {
        $this->cleaner = $cleaner;
    }

    public function getAlias()
    {
        return 'acme_empty_product_models';
    }

    public function getTemplate()
    {
        return 'AcmeProductCleanupBundle:Widget:empty_product_models.html.twig';
    }

    public function getParameters()
    {
        return [];
    }

    public function getData()
    {
        $families = $this->cleaner->countByFamily();

        return ['total' => array_sum($families), 'families' => $families];
    }
}
