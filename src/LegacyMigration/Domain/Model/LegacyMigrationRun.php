<?php

declare(strict_types=1);

namespace App\LegacyMigration\Domain\Model;

final readonly class LegacyMigrationRun
{
    public function __construct(
        public int $id,
        public LegacyMigrationRunStatus $status,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}

