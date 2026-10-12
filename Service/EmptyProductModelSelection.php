<?php

declare(strict_types=1);

namespace Acme\Bundle\ProductCleanupBundle\Service;

/**
 * Welche leeren Product Models betroffen sind: alle, die ersten N (nach Code) oder bestimmte Codes,
 * jeweils optional nur aus einer Familie. Angegebene Codes werden immer mit der Regel "leer" geschnitten,
 * ein Model mit Varianten kann also nie ausgewählt werden.
 */
final class EmptyProductModelSelection
{
    /** Obergrenze für einzeln angehakte Codes pro Job */
    public const MAX_CODES = 1000;

    private ?string $family;
    private ?int $limit;
    /** @var string[]|null */
    private ?array $codes;

    /**
     * @param string[]|null $codes
     */
    private function __construct(?string $family, ?int $limit, ?array $codes)
    {
        if (null !== $limit && $limit < 1) {
            throw new \InvalidArgumentException('The limit must be at least 1.');
        }
        if (null !== $codes && ([] === $codes || count($codes) > self::MAX_CODES)) {
            throw new \InvalidArgumentException(sprintf('Between 1 and %d codes can be selected.', self::MAX_CODES));
        }

        $this->family = '' === $family ? null : $family;
        $this->limit = $limit;
        $this->codes = null === $codes ? null : array_values(array_unique(array_map('strval', $codes)));
    }

    public static function all(?string $family = null): self
    {
        return new self($family, null, null);
    }

    public static function first(int $limit, ?string $family = null): self
    {
        return new self($family, $limit, null);
    }

    /**
     * @param string[] $codes
     */
    public static function ofCodes(array $codes, ?string $family = null): self
    {
        return new self($family, null, $codes);
    }

    /**
     * Aus Job-Parametern (family, limit, codes; jeweils null = nicht gesetzt)
     */
    public static function fromArray(array $parameters): self
    {
        return new self(
            $parameters['family'] ?? null,
            isset($parameters['limit']) ? (int) $parameters['limit'] : null,
            $parameters['codes'] ?? null
        );
    }

    public function toArray(): array
    {
        return ['family' => $this->family, 'limit' => $this->limit, 'codes' => $this->codes];
    }

    public function family(): ?string
    {
        return $this->family;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    /**
     * @return string[]|null
     */
    public function codes(): ?array
    {
        return $this->codes;
    }
}
