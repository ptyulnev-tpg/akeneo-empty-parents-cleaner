<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Service;

use Akeneo\Pim\Enrichment\Component\Product\Model\ProductInterface;
use Akeneo\Tool\Component\StorageUtils\Cache\EntityManagerClearerInterface;
use Akeneo\Tool\Component\StorageUtils\Remover\BulkRemoverInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Common\Persistence\ObjectRepository;

/**
 * Findet und löscht Produkte ohne Familie. Varianten-Produkte haben immer eine Familie und sind
 * damit nie betroffen. Jede Löschung landet über den LogDeletionSubscriber im Lösch-Log.
 */
final class ProductsWithoutFamilyCleaner
{
    private const CONDITION = 'p.family_id IS NULL AND p.product_model_id IS NULL';

    private Connection $connection;
    private ObjectRepository $productRepository;
    private BulkRemoverInterface $productRemover;
    private EntityManagerClearerInterface $cacheClearer;

    public function __construct(
        Connection $connection,
        ObjectRepository $productRepository,
        BulkRemoverInterface $productRemover,
        EntityManagerClearerInterface $cacheClearer
    ) {
        $this->connection = $connection;
        $this->productRepository = $productRepository;
        $this->productRemover = $productRemover;
        $this->cacheClearer = $cacheClearer;
    }

    public function count(): int
    {
        return (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM pim_catalog_product p WHERE ' . self::CONDITION
        );
    }

    /**
     * @return iterable<string>
     */
    public function findIdentifiers(): iterable
    {
        $statement = $this->connection->executeQuery(
            'SELECT p.identifier FROM pim_catalog_product p WHERE ' . self::CONDITION . ' ORDER BY p.identifier'
        );

        while (false !== ($identifier = $statement->fetchColumn())) {
            yield (string) $identifier;
        }
    }

    /**
     * @return array{total: int, items: list<array{id: int, identifier: string, enabled: bool, created: string, updated: string}>}
     */
    public function findPage(int $page, int $limit): array
    {
        if ($page < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Page and limit must be at least 1.');
        }

        $rows = $this->connection->fetchAll(
            'SELECT p.id, p.identifier, p.is_enabled, p.created, p.updated FROM pim_catalog_product p WHERE '
            . self::CONDITION . sprintf(' ORDER BY p.identifier LIMIT %d OFFSET %d', $limit, ($page - 1) * $limit)
        );

        return [
            'total' => $this->count(),
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'identifier' => $row['identifier'],
                'enabled' => (bool) $row['is_enabled'],
                'created' => $row['created'],
                'updated' => $row['updated'],
            ], $rows),
        ];
    }

    /**
     * @param callable(int $deletedInBatch): void|null $onBatchDeleted
     *
     * @return int Anzahl gelöschter Produkte
     */
    public function delete(int $batchSize, ?callable $onBatchDeleted = null): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }

        // Identifier vorab einsammeln (stabile Menge, kein Offset-Paging über eine schrumpfende Tabelle)
        $identifiers = iterator_to_array($this->findIdentifiers(), false);
        $deleted = 0;

        foreach (array_chunk($identifiers, $batchSize) as $batch) {
            // Erneut prüfen: zwischen Einsammeln und Löschen kann ein Produkt eine Familie bekommen haben
            $stillWithoutFamily = $this->filterStillWithoutFamily($batch);
            if ([] === $stillWithoutFamily) {
                continue;
            }

            /** @var ProductInterface[] $products */
            $products = $this->productRepository->findBy(['identifier' => $stillWithoutFamily]);
            $this->productRemover->removeAll($products);
            $this->cacheClearer->clear();

            $deleted += count($products);
            if (null !== $onBatchDeleted) {
                $onBatchDeleted(count($products));
            }
        }

        return $deleted;
    }

    /**
     * @param string[] $identifiers
     *
     * @return string[]
     */
    private function filterStillWithoutFamily(array $identifiers): array
    {
        return array_column($this->connection->fetchAll(
            'SELECT p.identifier FROM pim_catalog_product p WHERE ' . self::CONDITION . ' AND p.identifier IN (:identifiers)',
            ['identifiers' => $identifiers],
            ['identifiers' => Connection::PARAM_STR_ARRAY]
        ), 'identifier');
    }
}
