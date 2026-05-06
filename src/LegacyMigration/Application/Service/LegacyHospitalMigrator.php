<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use App\LegacyMigration\Infrastructure\Matching\HospitalMatcher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LegacyHospitalMigrator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $legacyConnection,
        private Connection $defaultConnection,
        private EntityManagerInterface $entityManager,
        private HospitalMatcher $matcher,
        private LegacyMigrationStateRepositoryInterface $stateRepository,
    ) {
    }

    public function migrate(bool $dryRun = false): int
    {
        $rows = $this->legacyConnection->fetchAllAssociative('SELECT id, name FROM hospital ORDER BY id ASC');
        $hospitals = $this->entityManager->getRepository(Hospital::class)->findAll();
        $migrated = 0;

        foreach ($rows as $row) {
            $legacyHospitalId = (int) $row['id'];
            $exists = (int) $this->defaultConnection->fetchOne(
                'SELECT COUNT(*) FROM legacy_migration_hospital_mapping WHERE legacy_hospital_id = :legacyId',
                ['legacyId' => $legacyHospitalId]
            );
            if ($exists > 0) {
                continue;
            }

            $legacyName = trim((string) ($row['name'] ?? ''));
            $matched = $this->matcher->matchOrFail($legacyHospitalId, $legacyName, $hospitals);

            if (!$dryRun) {
                $hospital = $matched['hospital'];
                $this->defaultConnection->insert('legacy_migration_hospital_mapping', [
                    'legacy_hospital_id' => $legacyHospitalId,
                    'new_hospital_id' => (int) $hospital->getId(),
                    'legacy_name' => $legacyName,
                    'matched_name' => (string) $hospital->getName(),
                    'match_score' => $matched['score'],
                    'migrated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                ]);
            }
            ++$migrated;
        }

        $this->stateRepository->log('hospitals', 'info', 'hospitals phase finished', null, ['migrated' => $migrated, 'dryRun' => $dryRun]);

        return $migrated;
    }
}
