<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\EventSubscriber;

use Acme\Bundle\ProductCleanupBundle\Service\DeletionLog;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Akeneo\Tool\Component\StorageUtils\StorageEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Protokolliert jedes gelöschte Produkt und Product Model, egal woher die Löschung kommt
 * (UI, REST API, Massen-Job, CLI). Die Daten werden vor dem Löschen eingesammelt und erst
 * nach erfolgreichem Löschen geschrieben. Fehler beim Protokollieren blockieren das Löschen nie.
 */
final class LogDeletionSubscriber implements EventSubscriberInterface
{
    private DeletionLog $deletionLog;
    private TokenStorageInterface $tokenStorage;
    private RequestStack $requestStack;
    private LoggerInterface $logger;

    /** @var array<int, array> spl_object_id => Log-Eintrag */
    private array $pending = [];

    public function __construct(
        DeletionLog $deletionLog,
        TokenStorageInterface $tokenStorage,
        RequestStack $requestStack,
        LoggerInterface $logger
    ) {
        $this->deletionLog = $deletionLog;
        $this->tokenStorage = $tokenStorage;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorageEvents::PRE_REMOVE => 'collect',
            StorageEvents::POST_REMOVE => 'write',
        ];
    }

    public function collect(GenericEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof ProductInterface && !$subject instanceof ProductModelInterface) {
            return;
        }

        try {
            $isProduct = $subject instanceof ProductInterface;
            $family = $subject->getFamily();
            $this->pending[spl_object_id($subject)] = [
                'entity_type' => $isProduct ? DeletionLog::TYPE_PRODUCT : DeletionLog::TYPE_PRODUCT_MODEL,
                'identifier' => (string) $subject->getIdentifier(),
                'label' => $isProduct ? (string) $subject->getLabel() : $subject->getLabel(null, null),
                'family' => null === $family ? null : $family->getCode(),
                'deleted_by' => $this->currentUsername(),
                'context' => $this->currentContext(),
                'raw_values' => $subject->getRawValues(),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('acme_product_cleanup: could not collect deletion data', ['exception' => $e]);
        }
    }

    public function write(GenericEvent $event): void
    {
        $key = spl_object_id($event->getSubject());
        if (!isset($this->pending[$key])) {
            return;
        }

        $entry = $this->pending[$key];
        unset($this->pending[$key]);

        $this->logger->notice(sprintf(
            'acme_product_cleanup: deleted %s "%s" (family: %s, by: %s, via: %s)',
            $entry['entity_type'],
            $entry['identifier'],
            $entry['family'] ?? '-',
            $entry['deleted_by'] ?? '-',
            $entry['context'] ?? '-'
        ));

        try {
            $this->deletionLog->add($entry);
        } catch (\Throwable $e) {
            $this->logger->error('acme_product_cleanup: could not write deletion log', ['exception' => $e]);
        }
    }

    private function currentUsername(): ?string
    {
        $token = $this->tokenStorage->getToken();
        $user = null === $token ? null : $token->getUser();

        return $user instanceof UserInterface ? $user->getUsername() : null;
    }

    private function currentContext(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request) {
            return $request->getMethod() . ' ' . $request->getPathInfo();
        }

        $argv = $_SERVER['argv'] ?? [];

        return 'cli ' . implode(' ', array_slice($argv, 1, 2));
    }
}
