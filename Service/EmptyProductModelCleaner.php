<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Service;

use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Akeneo\Pim\Enrichment\Component\Product\Repository\ProductModelRepositoryInterface;
use Akeneo\Tool\Component\StorageUtils\Cache\EntityManagerClearerInterface;
use Akeneo\Tool\Component\StorageUtils\Remover\BulkRemoverInterface;
use Doctrine\DBAL\Connection;

/**
 * Findet und löscht leere Product Models (TAPS-835): Root Product Models, unter denen weder direkt
 * noch unter einem ihrer Sub-Models ein Varianten-Produkt hängt. Leere Sub-Models werden mitgelöscht
 * (parent_id ON DELETE CASCADE). Einfache Produkte ohne Parent werden nie angefasst.
 *
 * Die Regel entspricht EmptyProductModelScanner::findDeadRoots() in tpg-pegasus-services und steht
 * nur in DEAD_ROOT_CONDITION, damit Zählen, Auflisten, Blättern und Löschen dieselbe Menge sehen.
 */
final class EmptyProductModelCleaner
{
    private const DEAD_ROOT_CONDITION = <<<'SQL'
        pm.parent_id IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM pim_catalog_product p WHERE p.product_model_id = pm.id
        )
        AND NOT EXISTS (
            SELECT 1
            FROM pim_catalog_product_model sub
            JOIN pim_catalog_product p ON p.product_model_id = sub.id
            WHERE sub.parent_id = pm.id
        )
        SQL;

    private const FROM = <<<'SQL'
        FROM pim_catalog_product_model pm
        JOIN pim_catalog_family_variant fv ON fv.id = pm.family_variant_id
        JOIN pim_catalog_family f ON f.id = fv.family_id
        SQL;

    private Connection $connection;
    private ProductModelRepositoryInterface $productModelRepository;
    private BulkRemoverInterface $productModelRemover;
    private EntityManagerClearerInterface $cacheClearer;

    public function __construct(
        Connection $connection,
        ProductModelRepositoryInterface $productModelRepository,
        BulkRemoverInterface $productModelRemover,
        EntityManagerClearerInterface $cacheClearer
    ) {
        $this->connection = $connection;
        $this->productModelRepository = $productModelRepository;
        $this->productModelRemover = $productModelRemover;
        $this->cacheClearer = $cacheClearer;
    }

    public function count(?string $familyCode = null): int
    {
        return $this->countSelection(EmptyProductModelSelection::all($familyCode));
    }

    /**
     * Anzahl, die eine Löschung dieser Auswahl betreffen würde
     */
    public function countSelection(EmptyProductModelSelection $selection): int
    {
        [$where, $params, $types] = $this->where($selection);
        $count = (int) $this->connection->fetchColumn('SELECT COUNT(*) ' . self::FROM . ' WHERE ' . $where, $params, 0, $types);

        return null === $selection->limit() ? $count : min($count, $selection->limit());
    }

    /**
     * @return array<string, int> Familiencode => Anzahl leerer Product Models
     */
    public function countByFamily(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT f.code AS family, COUNT(*) AS total ' . self::FROM . ' WHERE ' . self::DEAD_ROOT_CONDITION
            . ' GROUP BY f.code ORDER BY f.code'
        );

        return array_map('intval', array_column($rows, 'total', 'family'));
    }

    /**
     * Codes der Auswahl, sortiert nach Code (bei "die ersten N" also die ersten N nach Code)
     *
     * @return iterable<string>
     */
    public function findCodes(EmptyProductModelSelection $selection): iterable
    {
        [$where, $params, $types] = $this->where($selection);
        $statement = $this->connection->executeQuery(
            'SELECT pm.code ' . self::FROM . ' WHERE ' . $where . ' ORDER BY pm.code'
            . (null === $selection->limit() ? '' : sprintf(' LIMIT %d', $selection->limit())),
            $params,
            $types
        );

        while (false !== ($code = $statement->fetchColumn())) {
            yield (string) $code;
        }
    }

    /**
     * @return array{total: int, items: list<array{id: int, code: string, label: string, family: string, family_variant: string, sub_models: int, created: string}>}
     */
    public function findPage(int $page, int $limit, ?string $familyCode = null, ?string $localeCode = null): array
    {
        if ($page < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Page and limit must be at least 1.');
        }

        [$where, $params, $types] = $this->where(EmptyProductModelSelection::all($familyCode));
        $rows = $this->connection->fetchAll(
            'SELECT pm.id, pm.code, f.code AS family, fv.code AS family_variant, pm.created,'
            . ' (SELECT COUNT(*) FROM pim_catalog_product_model sub WHERE sub.parent_id = pm.id) AS sub_models '
            . self::FROM . ' WHERE ' . $where
            . sprintf(' ORDER BY pm.code LIMIT %d OFFSET %d', $limit, ($page - 1) * $limit),
            $params,
            $types
        );

        $labels = $this->labelsByCode(array_column($rows, 'code'), $localeCode);
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'label' => $labels[$row['code']] ?? $row['code'],
            'family' => $row['family'],
            'family_variant' => $row['family_variant'],
            'sub_models' => (int) $row['sub_models'],
            'created' => $row['created'],
        ], $rows);

        return ['total' => $this->count($familyCode), 'items' => $items];
    }

    /**
     * @param callable(list<array{code: string, family: string, label: string, sub_models: int}> $deletedInBatch): void|null $onBatchDeleted
     *        wird nach jedem Batch mit den gelöschten Root-Models aufgerufen (für Protokollierung)
     *
     * @return int Anzahl gelöschter Root Product Models
     */
    public function delete(int $batchSize, EmptyProductModelSelection $selection, ?callable $onBatchDeleted = null): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }

        // Codes vorab einsammeln (stabile Menge, kein Offset-Paging über eine schrumpfende Tabelle)
        $codes = iterator_to_array($this->findCodes($selection), false);
        $deleted = 0;

        foreach (array_chunk($codes, $batchSize) as $batch) {
            // Erneut prüfen: zwischen Einsammeln und Löschen kann ein Model eine Variante bekommen haben
            $stillEmpty = $this->filterStillEmpty($batch);
            if ([] === $stillEmpty) {
                continue;
            }

            /** @var ProductModelInterface[] $productModels */
            $productModels = $this->productModelRepository->findByIdentifiers($stillEmpty);
            // Vor dem Löschen festhalten, was gelöscht wird (danach sind die Entities weg)
            $deletedInBatch = array_map(static fn (ProductModelInterface $productModel): array => [
                'code' => $productModel->getCode(),
                'family' => null !== $productModel->getFamily() ? $productModel->getFamily()->getCode() : '',
                'label' => $productModel->getLabel(),
                'sub_models' => $productModel->getProductModels()->count(),
            ], $productModels);

            $this->productModelRemover->removeAll($productModels);
            $this->cacheClearer->clear();

            $deleted += count($deletedInBatch);
            if (null !== $onBatchDeleted) {
                $onBatchDeleted($deletedInBatch);
            }
        }

        return $deleted;
    }

    /**
     * @param string[] $codes
     *
     * @return string[]
     */
    private function filterStillEmpty(array $codes): array
    {
        return array_column($this->connection->fetchAll(
            'SELECT pm.code ' . self::FROM . ' WHERE ' . self::DEAD_ROOT_CONDITION . ' AND pm.code IN (:codes)',
            ['codes' => $codes],
            ['codes' => Connection::PARAM_STR_ARRAY]
        ), 'code');
    }

    /**
     * WHERE-Teil für eine Auswahl: immer DEAD_ROOT_CONDITION, eingeschränkt nach Familie und/oder Codes.
     * Das LIMIT einer Auswahl setzt der Aufrufer (es hängt von der Sortierung ab).
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, int>}
     */
    private function where(EmptyProductModelSelection $selection): array
    {
        $where = self::DEAD_ROOT_CONDITION;
        $params = [];
        $types = [];

        if (null !== $selection->family()) {
            $where .= ' AND f.code = :family';
            $params['family'] = $selection->family();
        }
        if (null !== $selection->codes()) {
            $where .= ' AND pm.code IN (:codes)';
            $params['codes'] = $selection->codes();
            $types['codes'] = Connection::PARAM_STR_ARRAY;
        }

        return [$where, $params, $types];
    }

    /**
     * @param string[] $codes
     *
     * @return array<string, string>
     */
    private function labelsByCode(array $codes, ?string $localeCode): array
    {
        if ([] === $codes) {
            return [];
        }

        $labels = [];
        foreach ($this->productModelRepository->findByIdentifiers($codes) as $productModel) {
            $labels[$productModel->getCode()] = (string) $productModel->getLabel($localeCode);
        }
        $this->cacheClearer->clear();

        return $labels;
    }
}
