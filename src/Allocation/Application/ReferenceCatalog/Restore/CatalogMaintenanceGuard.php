<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Restore;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Keeps catalog maintenance (restore, development reset) away from running work (ADR 020).
 *
 * Both share one lock, so they never run at the same time, and both refuse while the
 * Doctrine queues (ADR 003) hold messages that are waiting or being handled by a worker.
 */
final readonly class CatalogMaintenanceGuard
{
    public const string LOCK = 'reference-catalog-maintenance';

    private const string FAILED_QUEUE = 'failed';

    public function __construct(
        private LockFactory $lockFactory,
        private Connection $connection,
    ) {
    }

    /**
     * Returns the held lock, or null when another restore or reset holds it.
     *
     * @phpstan-impure
     */
    public function acquire(): ?LockInterface
    {
        $lock = $this->lockFactory->createLock(self::LOCK, 3600.0);

        return $lock->acquire() ? $lock : null;
    }

    /**
     * Messages waiting or in progress per queue; the failure queue does not count.
     *
     * @return array<string, int>
     */
    public function busyQueues(): array
    {
        try {
            /** @var array<string, int|string> $rows */
            $rows = $this->connection->fetchAllKeyValue(
                'SELECT queue_name, COUNT(*) FROM messenger_messages WHERE queue_name <> :failed GROUP BY queue_name ORDER BY queue_name',
                ['failed' => self::FAILED_QUEUE],
            );
        } catch (TableNotFoundException) {
            return [];
        }

        return array_map(static fn (int|string $count): int => (int) $count, $rows);
    }
}
