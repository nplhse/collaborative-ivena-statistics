<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterKeyStateReaderInterface;
use App\Encounter\Domain\Entity\EncounterKeyState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Key state of a hospital (ADR 027). A hospital without a row is at generation 1 with no registered
 * check value.
 *
 * @extends ServiceEntityRepository<EncounterKeyState>
 */
final class EncounterKeyStateRepository extends ServiceEntityRepository implements EncounterKeyStateReaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EncounterKeyState::class);
    }

    public function findForHospital(Hospital $hospital): ?EncounterKeyState
    {
        return $this->findOneBy(['hospital' => $hospital]);
    }

    #[\Override]
    public function currentState(int $hospitalId): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT key_generation, key_change_in_progress FROM encounter_key_state WHERE hospital_id = ?',
            [$hospitalId],
            [Types::INTEGER],
        );

        return [
            'generation' => false === $row ? EncounterKeyState::FIRST_GENERATION : (int) $row['key_generation'],
            'key_change_in_progress' => false !== $row && (bool) $row['key_change_in_progress'],
        ];
    }

    /**
     * Locks the hospital's key state row for the rest of the current transaction, creating it first
     * when the hospital has none. A key change (#1050) takes the same lock, so a commit of an import
     * and a key change are serialized. Must run inside a transaction.
     *
     * @return array{generation: int, key_change_in_progress: bool, active_check_value: string|null, retired_check_values: list<string>}
     */
    public function lockForImport(int $hospitalId, \DateTimeImmutable $now): array
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'INSERT INTO encounter_key_state (hospital_id, created_at) VALUES (?, ?) ON CONFLICT (hospital_id) DO NOTHING',
            [$hospitalId, $now],
            [Types::INTEGER, Types::DATETIME_IMMUTABLE],
        );

        $row = $connection->fetchAssociative(
            'SELECT key_generation, key_change_in_progress, active_check_value, retired_check_values FROM encounter_key_state WHERE hospital_id = ? FOR UPDATE',
            [$hospitalId],
            [Types::INTEGER],
        );
        if (false === $row) {
            throw new \LogicException(sprintf('Key state of hospital #%d vanished while locked.', $hospitalId));
        }

        $retired = json_decode((string) $row['retired_check_values'], true, flags: \JSON_THROW_ON_ERROR);

        return [
            'generation' => (int) $row['key_generation'],
            'key_change_in_progress' => (bool) $row['key_change_in_progress'],
            'active_check_value' => null === $row['active_check_value'] ? null : (string) $row['active_check_value'],
            'retired_check_values' => \is_array($retired) ? array_values(array_map(strval(...), $retired)) : [],
        ];
    }

    /**
     * Registers the check value of the current generation (first upload of the generation, ADR 027).
     * Only call while holding the lock of lockForImport().
     */
    public function registerCheckValue(int $hospitalId, string $checkValue): void
    {
        $this->connection()->executeStatement(
            'UPDATE encounter_key_state SET active_check_value = ? WHERE hospital_id = ? AND active_check_value IS NULL',
            [$checkValue, $hospitalId],
            [Types::STRING, Types::INTEGER],
        );
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
