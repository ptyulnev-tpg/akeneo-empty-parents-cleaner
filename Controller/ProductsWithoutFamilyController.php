<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Controller;

use Acme\Bundle\ProductCleanupBundle\Service\ProductsWithoutFamilyCleaner;
use Akeneo\Tool\Bundle\BatchBundle\Launcher\JobLauncherInterface;
use Akeneo\Tool\Component\StorageUtils\Repository\IdentifiableObjectRepositoryInterface;
use Oro\Bundle\SecurityBundle\SecurityFacade;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Interne API für die Seite "Produkte ohne Familie": Liste (paginiert) und Start des Lösch-Jobs.
 */
final class ProductsWithoutFamilyController
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    private ProductsWithoutFamilyCleaner $cleaner;
    private SecurityFacade $securityFacade;
    private TokenStorageInterface $tokenStorage;
    private IdentifiableObjectRepositoryInterface $jobInstanceRepository;
    private JobLauncherInterface $jobLauncher;
    private string $jobInstanceCode;

    public function __construct(
        ProductsWithoutFamilyCleaner $cleaner,
        SecurityFacade $securityFacade,
        TokenStorageInterface $tokenStorage,
        IdentifiableObjectRepositoryInterface $jobInstanceRepository,
        JobLauncherInterface $jobLauncher,
        string $jobInstanceCode
    ) {
        $this->cleaner = $cleaner;
        $this->securityFacade = $securityFacade;
        $this->tokenStorage = $tokenStorage;
        $this->jobInstanceRepository = $jobInstanceRepository;
        $this->jobLauncher = $jobLauncher;
        $this->jobInstanceCode = $jobInstanceCode;
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
        $result = $this->cleaner->findPage($page, $limit);

        return new JsonResponse([
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'can_delete' => $this->securityFacade->isGranted('pim_enrich_product_remove'),
            'items' => $result['items'],
        ]);
    }

    public function launchDeleteAction(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return new RedirectResponse('/');
        }
        if (!$this->securityFacade->isGranted('pim_enrich_product_remove')) {
            return new JsonResponse(['message' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $jobInstance = $this->jobInstanceRepository->findOneByIdentifier($this->jobInstanceCode);
        if (null === $jobInstance) {
            return new JsonResponse([
                'message' => sprintf(
                    'Job instance "%1$s" not found. Create it with: bin/console akeneo:batch:create-job "Acme Product Cleanup" %1$s mass_delete %1$s \'{"family":null}\' "Delete products without family"',
                    $this->jobInstanceCode
                ),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $jobExecution = $this->jobLauncher->launch($jobInstance, $this->tokenStorage->getToken()->getUser(), []);

        return new JsonResponse(['job_execution_id' => $jobExecution->getId()]);
    }
}
