<?php

declare(strict_types=1);

namespace App\LegacyMigration\Domain\Repository;

use App\LegacyMigration\Domain\Model\LegacyMigrationStatus;

interface LegacyMigrationStateRepositoryInterface
{
    public function getStatus(): LegacyMigrationStatus;

    public function log(
        string $scope,
        string $level,
        string $message,
        ?int $legacyId = null,
        ?array $context = null,
    ): void;
}

