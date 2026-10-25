<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportMilestoneType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Records import milestones inside the transaction of the run that reached them (ADR 016).
 */
final class ImportMilestoneRecorder
{
    /**
     * Records the creator's first successful import, unless it was recorded before.
     *
     * @return int|null the id of the new milestone, or null when it already existed or the import has no creator
     */
    public function recordFirstImport(Connection $connection, Import $import, Uuid $runId, \DateTimeImmutable $achievedAt): ?int
    {
        $userId = $import->getCreatedBy()?->getId();
        if (null === $userId) {
            return null;
        }

        $id = $connection->fetchOne(
            <<<'SQL'
                INSERT INTO user_import_milestone (user_id, milestone_type, import_run_id, achieved_at)
                VALUES (:user, :type, :run, :achieved_at)
                ON CONFLICT (user_id, milestone_type) DO NOTHING
                RETURNING id
                SQL,
            ['user' => $userId, 'type' => ImportMilestoneType::FirstImport->value, 'run' => $runId, 'achieved_at' => $achievedAt],
            ['run' => UuidType::NAME, 'achieved_at' => Types::DATETIME_IMMUTABLE],
        );

        return false === $id ? null : (int) $id;
    }
}
