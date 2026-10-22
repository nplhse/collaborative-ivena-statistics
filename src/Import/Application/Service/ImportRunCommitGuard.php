<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Exception\ImportRunSupersededException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Protects every commit of processing results of the run that is being processed.
 *
 * While a run is active, each commit first locks the import row and checks that the run is still
 * the import's current run. Registering a new run takes the same row lock, so both are serialized.
 * Without an active run (legacy messages, other callers) a commit is a plain flush.
 */
final class ImportRunCommitGuard implements ResetInterface
{
    private ?ImportRunContext $context = null;

    public function activate(ImportRunContext $context): void
    {
        $this->context = $context;
    }

    public function deactivate(): void
    {
        $this->context = null;
    }

    #[\Override]
    public function reset(): void
    {
        $this->deactivate();
    }

    /**
     * Flushes the unit of work as a guarded commit (see transactional()).
     *
     * @throws ImportRunSupersededException when a newer run was registered; nothing is committed then
     */
    public function flush(EntityManagerInterface $em): void
    {
        $this->transactional($em, static function () use ($em): void {
            $em->flush();
        });
    }

    /**
     * Runs $work as one commit. For an active run this happens in one transaction after locking the
     * import row and checking that the run is still current; without an active run $work runs as is.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     *
     * @throws ImportRunSupersededException when a newer run was registered; nothing is committed then
     */
    public function transactional(EntityManagerInterface $em, \Closure $work): mixed
    {
        $context = $this->context;
        if (!$context instanceof ImportRunContext) {
            return $work();
        }

        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            if (!self::isCurrentRunLocked($connection, $context)) {
                throw new ImportRunSupersededException($context->importId, $context->runId->toRfc4122());
            }

            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Locks the import row (SELECT … FOR UPDATE) and returns its current run id.
     * Must be called inside a transaction; the lock is held until it ends.
     *
     * @return string|false|null false when the import does not exist
     */
    public static function lockCurrentRunId(Connection $connection, int $importId): string|false|null
    {
        $currentRunId = $connection->fetchOne(
            'SELECT current_run_id FROM import WHERE id = :id FOR UPDATE',
            ['id' => $importId],
        );

        return null === $currentRunId || false === $currentRunId ? $currentRunId : (string) $currentRunId;
    }

    public static function isCurrentRunLocked(Connection $connection, ImportRunContext $context): bool
    {
        return self::matches(self::lockCurrentRunId($connection, $context->importId), $context);
    }

    private static function matches(mixed $currentRunId, ImportRunContext $context): bool
    {
        return \is_string($currentRunId) && strtolower($currentRunId) === $context->runId->toRfc4122();
    }
}
