<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Run;

use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalView;
use App\DataFixtures\Generator\Application\Run\GenerationRunRequest;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Generator\Application\Run\GenerationRunStage;
use App\DataFixtures\Generator\Application\Run\GenerationRunStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunTrigger;
use App\DataFixtures\Generator\Application\Run\GenerationRunView;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * `generation_run` and `generation_run_hospital` (#791, ADR 023).
 *
 * Every state change is one conditional statement, so concurrent workers and retries cannot move a hospital or
 * a run backwards, and a terminal state is reached exactly once (the statement that returns a row wins).
 *
 * @psalm-type RunRow = array{id: string, scope: string, trigger: string, seed: int|string, period_from: ?string, period_to: ?string, settings: string, notify: bool|int|string, initiated_by_id: int|string|null, status: string, stage: string, reason_code: ?string, created_at: string, updated_at: string, generated_at: ?string, finished_at: ?string}
 * @psalm-type HospitalRow = array{hospital_id: int|string, hospital_name: ?string, owner_id: int|string|null, status: string, months: int|string, allocations: int|string, closures: int|string, mci_cases: int|string, allocation_import_ids: string, closure_import_ids: string, projection_attempts: int|string, reason_code: ?string, error: ?string, started_at: ?string, generated_at: ?string, finished_at: ?string}
 */
final readonly class GenerationRunStore
{
    private const int ERROR_LENGTH = 500;

    public function __construct(private Connection $connection)
    {
    }

    public function create(GenerationRunRequest $request, \DateTimeImmutable $now): string
    {
        $id = Uuid::v7()->toRfc4122();

        $this->connection->transactional(function (Connection $connection) use ($id, $request, $now): void {
            $connection->insert('generation_run', [
                'id' => $id,
                'scope' => $request->scope->value,
                'trigger' => $request->trigger->value,
                'seed' => $request->seed,
                'period_from' => $request->fromMonth,
                'period_to' => $request->toMonth,
                'settings' => json_encode((object) $request->settings, \JSON_THROW_ON_ERROR),
                'notify' => $request->notify,
                'initiated_by_id' => $request->initiatedById,
                'status' => GenerationRunStatus::Pending->value,
                'stage' => GenerationRunStage::Generating->value,
                'created_at' => $this->ts($now),
                'updated_at' => $this->ts($now),
            ], ['notify' => ParameterType::BOOLEAN]);

            foreach ($request->hospitalIds as $hospitalId) {
                $connection->insert('generation_run_hospital', [
                    'run_id' => $id,
                    'hospital_id' => $hospitalId,
                    'status' => GenerationRunHospitalStatus::Pending->value,
                ]);
            }
        });

        return $id;
    }

    public function hospitalStarted(string $runId, int $hospitalId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE generation_run_hospital SET status = :generating, started_at = COALESCE(started_at, :now)
             WHERE run_id = :run AND hospital_id = :hospital AND status = :pending',
            ['generating' => GenerationRunHospitalStatus::Generating->value, 'pending' => GenerationRunHospitalStatus::Pending->value, 'now' => $this->ts($now), 'run' => $runId, 'hospital' => $hospitalId],
        );
        $this->connection->executeStatement(
            'UPDATE generation_run SET status = :running, updated_at = :now WHERE id = :run AND status = :pending',
            ['running' => GenerationRunStatus::Running->value, 'pending' => GenerationRunStatus::Pending->value, 'now' => $this->ts($now), 'run' => $runId],
        );
        $this->touch($runId, $now);
    }

    public function monthGenerated(string $runId, int $hospitalId, int $allocationImportId, int $closureImportId, int $allocations, int $closures, int $mciCases, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE generation_run_hospital
             SET months = months + 1,
                 allocations = allocations + :allocations,
                 closures = closures + :closures,
                 mci_cases = mci_cases + :mci,
                 allocation_import_ids = allocation_import_ids || CAST(:allocation_import AS JSONB),
                 closure_import_ids = closure_import_ids || CAST(:closure_import AS JSONB)
             WHERE run_id = :run AND hospital_id = :hospital AND status = :generating',
            [
                'allocations' => $allocations,
                'closures' => $closures,
                'mci' => $mciCases,
                'allocation_import' => json_encode([$allocationImportId], \JSON_THROW_ON_ERROR),
                'closure_import' => json_encode([$closureImportId], \JSON_THROW_ON_ERROR),
                'run' => $runId,
                'hospital' => $hospitalId,
                'generating' => GenerationRunHospitalStatus::Generating->value,
            ],
        );
        $this->touch($runId, $now);
    }

    /**
     * Fails a hospital that is not finished yet. Returns false when it already was completed or failed.
     */
    public function failHospital(string $runId, int $hospitalId, string $reasonCode, ?string $error, \DateTimeImmutable $now): bool
    {
        $failed = false !== $this->connection->fetchOne(
            'UPDATE generation_run_hospital SET status = :failed, reason_code = :reason, error = :error, finished_at = :now
             WHERE run_id = :run AND hospital_id = :hospital AND status NOT IN (:terminal)
             RETURNING id',
            [
                'failed' => GenerationRunHospitalStatus::Failed->value,
                'reason' => mb_substr($reasonCode, 0, 64),
                'error' => null === $error ? null : mb_substr($error, 0, self::ERROR_LENGTH),
                'now' => $this->ts($now),
                'run' => $runId,
                'hospital' => $hospitalId,
                'terminal' => [GenerationRunHospitalStatus::Completed->value, GenerationRunHospitalStatus::Failed->value],
            ],
            ['terminal' => ArrayParameterType::STRING],
        );
        $this->touch($runId, $now);

        return $failed;
    }

    /**
     * Ends the synchronous stage: hospitals with at least one month become `generated`, the others fail with
     * $nothingReason. Returns the ids of the generated hospitals.
     *
     * @return list<int>
     */
    public function endGeneration(string $runId, string $nothingReason, \DateTimeImmutable $now): array
    {
        $open = [GenerationRunHospitalStatus::Pending->value, GenerationRunHospitalStatus::Generating->value];
        /** @var list<int|string> $generated */
        $generated = $this->connection->fetchFirstColumn(
            'UPDATE generation_run_hospital SET status = :generated, generated_at = :now
             WHERE run_id = :run AND status IN (:open) AND months > 0
             RETURNING hospital_id',
            ['generated' => GenerationRunHospitalStatus::Generated->value, 'now' => $this->ts($now), 'run' => $runId, 'open' => $open],
            ['open' => ArrayParameterType::STRING],
        );
        $this->connection->fetchFirstColumn(
            'UPDATE generation_run_hospital SET status = :failed, reason_code = :reason, finished_at = :now
             WHERE run_id = :run AND status IN (:open)
             RETURNING hospital_id',
            ['failed' => GenerationRunHospitalStatus::Failed->value, 'reason' => $nothingReason, 'now' => $this->ts($now), 'run' => $runId, 'open' => $open],
            ['open' => ArrayParameterType::STRING],
        );
        $this->connection->executeStatement(
            'UPDATE generation_run SET status = :running, stage = :projecting, generated_at = :now, updated_at = :now
             WHERE id = :run AND stage = :generating',
            [
                'running' => GenerationRunStatus::Running->value,
                'projecting' => GenerationRunStage::Projecting->value,
                'generating' => GenerationRunStage::Generating->value,
                'now' => $this->ts($now),
                'run' => $runId,
            ],
        );

        $ids = array_map(intval(...), $generated);
        sort($ids);

        return $ids;
    }

    /**
     * Claims the asynchronous stage of a hospital: `generated`, or `projecting` left by an attempt that
     * Messenger retries. Returns false for completed, failed or unknown hospitals.
     */
    public function claimProjection(string $runId, int $hospitalId, \DateTimeImmutable $now): bool
    {
        $claimed = false !== $this->connection->fetchOne(
            'UPDATE generation_run_hospital SET status = :projecting, projection_attempts = projection_attempts + 1
             WHERE run_id = :run AND hospital_id = :hospital AND status IN (:claimable)
             RETURNING id',
            [
                'projecting' => GenerationRunHospitalStatus::Projecting->value,
                'run' => $runId,
                'hospital' => $hospitalId,
                'claimable' => [GenerationRunHospitalStatus::Generated->value, GenerationRunHospitalStatus::Projecting->value],
            ],
            ['claimable' => ArrayParameterType::STRING],
        );
        if ($claimed) {
            $this->touch($runId, $now);
        }

        return $claimed;
    }

    public function completeHospital(string $runId, int $hospitalId, \DateTimeImmutable $now): bool
    {
        $completed = $this->connection->executeStatement(
            'UPDATE generation_run_hospital SET status = :completed, reason_code = NULL, error = NULL, finished_at = :now
             WHERE run_id = :run AND hospital_id = :hospital AND status = :projecting',
            [
                'completed' => GenerationRunHospitalStatus::Completed->value,
                'projecting' => GenerationRunHospitalStatus::Projecting->value,
                'now' => $this->ts($now),
                'run' => $runId,
                'hospital' => $hospitalId,
            ],
        ) > 0;
        $this->touch($runId, $now);

        return $completed;
    }

    /**
     * Moves the run from `projecting` to `finishing` once no hospital is open. One statement: the row lock on
     * the run serializes the last hospitals, and the condition is checked again after a concurrent commit, so
     * exactly one caller wins.
     */
    public function claimFinishing(string $runId, \DateTimeImmutable $now): bool
    {
        return false !== $this->connection->fetchOne(
            'UPDATE generation_run SET stage = :finishing, updated_at = :now
             WHERE id = :run AND stage = :projecting AND status = :running
               AND NOT EXISTS (SELECT 1 FROM generation_run_hospital h WHERE h.run_id = :run AND h.status NOT IN (:terminal))
             RETURNING id',
            [
                'finishing' => GenerationRunStage::Finishing->value,
                'projecting' => GenerationRunStage::Projecting->value,
                'running' => GenerationRunStatus::Running->value,
                'now' => $this->ts($now),
                'run' => $runId,
                'terminal' => [GenerationRunHospitalStatus::Completed->value, GenerationRunHospitalStatus::Failed->value],
            ],
            ['terminal' => ArrayParameterType::STRING],
        );
    }

    /**
     * The terminal transition: `completed` when every hospital completed, `failed` (reason hospital_failed)
     * otherwise. Returns the new status, or null when another process already finished the run.
     */
    public function finish(string $runId, string $hospitalFailedReason, \DateTimeImmutable $now): ?GenerationRunStatus
    {
        $status = $this->connection->fetchOne(
            'UPDATE generation_run r
             SET status = CASE WHEN failed.any THEN :failed ELSE :completed END,
                 reason_code = CASE WHEN failed.any THEN :reason ELSE NULL END,
                 stage = :done, finished_at = :now, updated_at = :now
             FROM (SELECT EXISTS (SELECT 1 FROM generation_run_hospital WHERE run_id = :run AND status <> :hospital_completed) AS any) failed
             WHERE r.id = :run AND r.status = :running
             RETURNING r.status',
            [
                'failed' => GenerationRunStatus::Failed->value,
                'completed' => GenerationRunStatus::Completed->value,
                'reason' => $hospitalFailedReason,
                'done' => GenerationRunStage::Done->value,
                'now' => $this->ts($now),
                'run' => $runId,
                'hospital_completed' => GenerationRunHospitalStatus::Completed->value,
                'running' => GenerationRunStatus::Running->value,
            ],
        );

        return \is_string($status) ? GenerationRunStatus::from($status) : null;
    }

    /**
     * Fails the whole run (for example when its final step was given up). Returns false when it already ended.
     */
    public function failRun(string $runId, string $reasonCode, \DateTimeImmutable $now): bool
    {
        return false !== $this->connection->fetchOne(
            'UPDATE generation_run SET status = :failed, stage = :done, reason_code = :reason, finished_at = :now, updated_at = :now
             WHERE id = :run AND status IN (:open)
             RETURNING id',
            [
                'failed' => GenerationRunStatus::Failed->value,
                'done' => GenerationRunStage::Done->value,
                'reason' => $reasonCode,
                'now' => $this->ts($now),
                'run' => $runId,
                'open' => [GenerationRunStatus::Pending->value, GenerationRunStatus::Running->value],
            ],
            ['open' => ArrayParameterType::STRING],
        );
    }

    /**
     * Reopens a run for `app:generate:run:retry`: hospitals that failed after generating data go back to
     * `generated`, and a finished run back to `running` / `projecting`. Hospitals without data stay failed.
     *
     * @return list<int> hospitals to project again
     */
    public function reopenFailed(string $runId, \DateTimeImmutable $now): array
    {
        return $this->connection->transactional(function (Connection $connection) use ($runId, $now): array {
            /** @var list<int|string> $reopened */
            $reopened = $connection->fetchFirstColumn(
                'UPDATE generation_run_hospital SET status = :generated, reason_code = NULL, error = NULL, finished_at = NULL
                 WHERE run_id = :run AND status = :failed AND months > 0
                 RETURNING hospital_id',
                ['generated' => GenerationRunHospitalStatus::Generated->value, 'failed' => GenerationRunHospitalStatus::Failed->value, 'run' => $runId],
            );
            if ([] !== $reopened) {
                $connection->executeStatement(
                    'UPDATE generation_run SET status = :running, stage = :projecting, reason_code = NULL, finished_at = NULL, updated_at = :now
                     WHERE id = :run AND stage <> :generating',
                    [
                        'running' => GenerationRunStatus::Running->value,
                        'projecting' => GenerationRunStage::Projecting->value,
                        'generating' => GenerationRunStage::Generating->value,
                        'now' => $this->ts($now),
                        'run' => $runId,
                    ],
                );
            }

            $ids = array_map(intval(...), $reopened);
            sort($ids);

            return $ids;
        });
    }

    public function find(string $runId): ?GenerationRunView
    {
        if (!Uuid::isValid($runId)) {
            return null;
        }

        /** @var RunRow|false $run */
        $run = $this->connection->fetchAssociative('SELECT * FROM generation_run WHERE id = :run', ['run' => $runId]);
        if (false === $run) {
            return null;
        }

        /** @var list<HospitalRow> $hospitals */
        $hospitals = $this->connection->fetchAllAssociative(
            'SELECT g.*, h.name AS hospital_name, h.owner_id
             FROM generation_run_hospital g JOIN hospital h ON h.id = g.hospital_id
             WHERE g.run_id = :run ORDER BY g.id',
            ['run' => $runId],
        );

        return $this->view($run, $hospitals);
    }

    /**
     * Newest runs first.
     *
     * @return list<string>
     */
    public function recentIds(int $limit): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM generation_run ORDER BY created_at DESC, id DESC LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return $ids;
    }

    /**
     * Runs with notifications that reached their terminal state in [$from, $to].
     *
     * @return list<string>
     */
    public function notifyingFinishedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM generation_run WHERE notify AND status IN (:terminal) AND finished_at BETWEEN :from AND :to ORDER BY finished_at',
            [
                'terminal' => [GenerationRunStatus::Completed->value, GenerationRunStatus::Failed->value],
                'from' => $this->ts($from),
                'to' => $this->ts($to),
            ],
            ['terminal' => ArrayParameterType::STRING],
        );

        return $ids;
    }

    /**
     * job_run rows of the run's asynchronous stages (ADR 018/019), oldest first. Written only by workers.
     *
     * @return list<array{job: string, status: string, attempt: int, hospital_id: ?int, started_at: string, duration_ms: ?int, error_class: ?string}>
     */
    public function jobRuns(string $runId): array
    {
        /** @var list<array{job: string, status: string, attempt: int|string, hospital_id: int|string|null, started_at: string, duration_ms: int|string|null, error_class: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT job, status, attempt, metrics->>'hospital_id' AS hospital_id, started_at, duration_ms, error_class
             FROM job_run WHERE metrics->>'generation_run_id' = :run ORDER BY started_at, id",
            ['run' => $runId],
        );

        return array_map(static fn (array $row): array => [
            'job' => $row['job'],
            'status' => $row['status'],
            'attempt' => (int) $row['attempt'],
            'hospital_id' => null === $row['hospital_id'] ? null : (int) $row['hospital_id'],
            'started_at' => $row['started_at'],
            'duration_ms' => null === $row['duration_ms'] ? null : (int) $row['duration_ms'],
            'error_class' => $row['error_class'],
        ], $rows);
    }

    /**
     * Hospitals whose asynchronous stage has not ended: `generated` (message not handled yet) or `projecting`.
     *
     * @return list<int>
     */
    public function openProjections(string $runId): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT hospital_id FROM generation_run_hospital WHERE run_id = :run AND status IN (:open) ORDER BY hospital_id',
            ['run' => $runId, 'open' => [GenerationRunHospitalStatus::Generated->value, GenerationRunHospitalStatus::Projecting->value]],
            ['open' => ArrayParameterType::STRING],
        );

        return array_map(intval(...), $ids);
    }

    private function touch(string $runId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('UPDATE generation_run SET updated_at = :now WHERE id = :run', ['now' => $this->ts($now), 'run' => $runId]);
    }

    private function ts(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }

    private static function time(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value);
    }

    /**
     * @return list<int>
     */
    private static function ids(string $json): array
    {
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? array_values(array_map(intval(...), array_filter($decoded, is_numeric(...)))) : [];
    }

    /**
     * @param RunRow            $run
     * @param list<HospitalRow> $hospitals
     */
    private function view(array $run, array $hospitals): GenerationRunView
    {
        /** @var array<string, mixed> $settings */
        $settings = json_decode($run['settings'], true, flags: \JSON_THROW_ON_ERROR) ?: [];

        return new GenerationRunView(
            id: $run['id'],
            scope: GenerationRunScope::from($run['scope']),
            trigger: GenerationRunTrigger::from($run['trigger']),
            seed: (int) $run['seed'],
            fromMonth: $run['period_from'],
            toMonth: $run['period_to'],
            settings: $settings,
            notify: \in_array($run['notify'], [true, 1, '1', 't', 'true'], true),
            initiatedById: null === $run['initiated_by_id'] ? null : (int) $run['initiated_by_id'],
            status: GenerationRunStatus::from($run['status']),
            stage: GenerationRunStage::from($run['stage']),
            reasonCode: $run['reason_code'],
            createdAt: new \DateTimeImmutable($run['created_at']),
            updatedAt: new \DateTimeImmutable($run['updated_at']),
            generatedAt: self::time($run['generated_at']),
            finishedAt: self::time($run['finished_at']),
            hospitals: array_map(static fn (array $row): GenerationRunHospitalView => new GenerationRunHospitalView(
                hospitalId: (int) $row['hospital_id'],
                hospitalName: (string) $row['hospital_name'],
                ownerId: null === $row['owner_id'] ? null : (int) $row['owner_id'],
                status: GenerationRunHospitalStatus::from($row['status']),
                months: (int) $row['months'],
                allocations: (int) $row['allocations'],
                closures: (int) $row['closures'],
                mciCases: (int) $row['mci_cases'],
                allocationImportIds: self::ids($row['allocation_import_ids']),
                closureImportIds: self::ids($row['closure_import_ids']),
                projectionAttempts: (int) $row['projection_attempts'],
                reasonCode: $row['reason_code'],
                error: $row['error'],
                startedAt: self::time($row['started_at']),
                generatedAt: self::time($row['generated_at']),
                finishedAt: self::time($row['finished_at']),
            ), $hospitals),
        );
    }
}
