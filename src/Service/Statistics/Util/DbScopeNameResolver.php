<?php

declare(strict_types=1);

namespace App\Service\Statistics\Util;

use Doctrine\DBAL\Connection;

/** @psalm-suppress ClassMustBeFinal */
class DbScopeNameResolver
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $db,
    ) {
    }

    public function resolve(string $scopeType, string $scopeId): ?string
    {
        return match ($scopeType) {
            'hospital' => $this->safeFetchOne('SELECT name FROM hospital WHERE id = :id', ['id' => $scopeId]),
            'dispatch_area' => $this->safeFetchOne('SELECT name FROM dispatch_area WHERE id = :id', ['id' => $scopeId]),
            'state' => $this->safeFetchOne('SELECT name FROM state WHERE id = :id', ['id' => $scopeId]),
            default => null,
        };
    }

    /**
     * @param array<string, string|int|float|bool|null> $params
     */
    private function safeFetchOne(string $sql, array $params): ?string
    {
        $r = $this->db->fetchOne($sql, $params);

        return false === $r ? null : $r;
    }
}
