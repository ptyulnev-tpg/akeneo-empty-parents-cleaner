<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Controller;

use Acme\Bundle\ProductCleanupBundle\Service\DeletionLog;
use Oro\Bundle\SecurityBundle\SecurityFacade;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interne API für die Seite "Lösch-Log": gelöschte Produkte und Product Models, neueste zuerst.
 */
final class DeletionLogController
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    private DeletionLog $deletionLog;
    private SecurityFacade $securityFacade;

    public function __construct(DeletionLog $deletionLog, SecurityFacade $securityFacade)
    {
        $this->deletionLog = $deletionLog;
        $this->securityFacade = $securityFacade;
    }

    public function listAction(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return new RedirectResponse('/');
        }
        if (!$this->securityFacade->isGranted('pim_enrich_product_index')) {
            return new JsonResponse(['message' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)));
        $type = $this->stringFrom($request->query->get('type'));
        $search = $this->stringFrom($request->query->get('search'));
        $result = $this->deletionLog->findPage($page, $limit, $type, $search);

        return new JsonResponse([
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'items' => $result['items'],
        ]);
    }

    private function stringFrom($value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
