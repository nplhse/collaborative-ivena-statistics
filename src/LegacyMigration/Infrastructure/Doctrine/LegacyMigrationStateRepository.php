<?php

declare(strict_types=1);

namespace App\LegacyMigration\Infrastructure\Doctrine;

use App\LegacyMigration\Domain\Model\LegacyMigrationStatus;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use Doctrine\DBAL\Connection;

final readonly class LegacyMigrationStateRepository implements LegacyMigrationStateRepositoryInterface
{
    public function __construct(
        private Connection $defaultConnection,
        private LegacyMigrationSchemaManager $schemaManager,
    ) {
    }

    public function getStatus(): LegacyMigrationStatus
    {
        return $this->schemaManager->getStatus();
    }

    public function log(
        string $scope,
        string $level,
        string $message,
        ?int $legacyId = null,
        ?array $context = null,
    ): void {
        if (!$this->schemaManager->isInstalled()) {
            return;
        }

        $this->defaultConnection->insert('legacy_migration_log', [
            'scope' => $scope,
            'legacy_id' => $legacyId,
            'level' => $level,
            'message' => $message,
            'context' => null === $context ? null : json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}

