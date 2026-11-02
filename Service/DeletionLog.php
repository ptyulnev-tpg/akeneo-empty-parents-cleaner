<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Protokoll gelöschter Produkte und Product Models (Tabelle acme_product_cleanup_deletion_log).
 * Speichert neben Identifier, Familie, Benutzer und Herkunft auch die Rohwerte, damit sich
 * ein gelöschter Artikel bei Bedarf nachvollziehen oder von Hand wiederherstellen lässt.
 */
final class DeletionLog
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_PRODUCT_MODEL = 'product_model';

    private const TABLE = 'acme_product_cleanup_deletion_log';

    private Connection $connection;
    private bool $tableEnsured = false;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array{entity_type: string, identifier: string, label: ?string, family: ?string, deleted_by: ?string, context: ?string, raw_values: ?array} $entry
     */
    public function add(array $entry): void
    {
        $this->ensureTable();
        $this->connection->insert(self::TABLE, [
            'deleted_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'entity_type' => $entry['entity_type'],
            'identifier' => mb_substr($entry['identifier'], 0, 255),
            'label' => null === $entry['label'] ? null : mb_substr($entry['label'], 0, 255),
            'family' => $entry['family'],
            'deleted_by' => $entry['deleted_by'],
            'context' => null === $entry['context'] ? null : mb_substr($entry['context'], 0, 255),
            'raw_values' => null === $entry['raw_values'] ? null : json_encode($entry['raw_values'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return array{total: int, items: list<array{id: int, deleted_at: string, entity_type: string, identifier: string, label: ?string, family: ?string, deleted_by: ?string, context: ?string}>}
     */
    public function findPage(int $page, int $limit, ?string $entityType = null, ?string $search = null): array
    {
        if ($page < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Page and limit must be at least 1.');
        }

        $this->ensureTable();
        [$where, $params] = $this->where($entityType, $search);
        $rows = $this->connection->fetchAll(
            'SELECT id, deleted_at, entity_type, identifier, label, family, deleted_by, context FROM ' . self::TABLE
            . ' WHERE ' . $where . sprintf(' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, ($page - 1) * $limit),
            $params
        );
        $total = (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . $where, $params);

        return [
            'total' => $total,
            'items' => array_map(static fn (array $row): array => ['id' => (int) $row['id']] + $row, $rows),
        ];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function where(?string $entityType, ?string $search): array
    {
        $conditions = ['1 = 1'];
        $params = [];
        if (null !== $entityType && '' !== $entityType) {
            $conditions[] = 'entity_type = :type';
            $params['type'] = $entityType;
        }
        if (null !== $search && '' !== $search) {
            $conditions[] = '(identifier LIKE :search OR label LIKE :search OR deleted_by LIKE :search)';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS acme_product_cleanup_deletion_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                deleted_at DATETIME NOT NULL,
                entity_type VARCHAR(20) NOT NULL,
                identifier VARCHAR(255) NOT NULL,
                label VARCHAR(255) NULL,
                family VARCHAR(100) NULL,
                deleted_by VARCHAR(255) NULL,
                context VARCHAR(255) NULL,
                raw_values LONGTEXT NULL,
                INDEX idx_deleted_at (deleted_at),
                INDEX idx_identifier (identifier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $this->tableEnsured = true;
    }
}
