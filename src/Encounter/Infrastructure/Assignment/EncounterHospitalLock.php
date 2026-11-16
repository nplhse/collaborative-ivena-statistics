<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * PostgreSQL transaction-level advisory lock per hospital. Its namespace differs from the matcher's own lock
 * (EncounterMatchRepository), which #1300 replaces by this one.
 *
 * @psalm-suppress UnusedClass Wired as EncounterHospitalLockInterface in config/services.yaml.
 */
final readonly class EncounterHospitalLock implements EncounterHospitalLockInterface
{
    private const int LOCK_NAMESPACE = 1307;

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function lock(int $hospitalId): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The encounter hospital lock is held until the end of a transaction; start one first.');
        }

        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock(:namespace, :hospital)',
            ['namespace' => self::LOCK_NAMESPACE, 'hospital' => $hospitalId],
            ['namespace' => Types::INTEGER, 'hospital' => Types::INTEGER],
        );
    }
}
