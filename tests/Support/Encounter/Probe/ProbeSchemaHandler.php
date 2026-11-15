<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Probe;

use App\Encounter\Contract\Assignment\AssignmentCounts;
use App\Encounter\Contract\Assignment\AssignmentRequest;
use App\Encounter\Contract\Assignment\EncounterAssignmentsInterface;
use App\Encounter\Contract\Import\EncounterRowError;
use App\Encounter\Contract\Import\EncounterSchemaHandlerInterface;
use App\Encounter\Contract\Import\RowError;
use App\Encounter\Contract\Import\RowMappingResult;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Contract\Import\SchemaWriteResult;

/**
 * Handler of the test-only schema `probe`: maps rows and records what the shared workflow handed it,
 * without a table of its own. A fingerprint seen before counts as known (duplicate); a row with fingerprint
 * `0…0` is rejected, one without `recorded_at` is skipped. New rows are assigned by their case pseudonym
 * through the contract (ADR 037), like an attaching schema; unassigned ones are kept as pending.
 */
final class ProbeSchemaHandler implements EncounterSchemaHandlerInterface
{
    /** @var list<array{context: SchemaWriteContext, lines: list<int>}> */
    public array $writes = [];

    /** @var list<int> */
    public array $removedImports = [];

    /** @var array<string, int> importing import by known fingerprint */
    private array $known = [];

    public function __construct(
        private readonly ?EncounterAssignmentsInterface $assignments = null,
    ) {
    }

    #[\Override]
    public function schema(): string
    {
        return ProbeDimension::SCHEMA;
    }

    #[\Override]
    public function createsEncounters(): bool
    {
        return false;
    }

    #[\Override]
    public function mapRow(int $line, array $fields, array $cells): RowMappingResult
    {
        if (\count($cells) !== \count($fields)) {
            return RowMappingResult::rejected([new RowError('*', EncounterRowError::ColumnCount)]);
        }

        [$fingerprint, $caseKey, $recordedAt] = $cells;
        if (str_repeat('0', 64) === $fingerprint) {
            return RowMappingResult::rejected([new RowError('record_fingerprint', EncounterRowError::InvalidCode)]);
        }

        if ('' === $recordedAt) {
            return RowMappingResult::skipped();
        }

        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $recordedAt, new \DateTimeZone('Europe/Berlin'));

        return RowMappingResult::valid(new ProbeRow($line, $fingerprint, $caseKey, false === $at ? null : $at));
    }

    #[\Override]
    public function write(SchemaWriteContext $context, array $rows): SchemaWriteResult
    {
        $lines = [];
        $inserted = 0;
        $counts = new AssignmentCounts();
        foreach ($rows as $row) {
            \assert($row instanceof ProbeRow);
            $lines[] = $row->line();
            if (isset($this->known[$row->fingerprint])) {
                continue;
            }

            $this->known[$row->fingerprint] = $context->importId;
            ++$inserted;
            if ($this->assignments instanceof EncounterAssignmentsInterface) {
                $request = new AssignmentRequest($context->hospitalId, $context->keyGeneration, caseKey: $row->caseKey, at: $row->coverageAt());
                $result = $this->assignments->find($request);
                $counts = $counts->with($result);
                $this->assignments->recordPending($context, ProbeDimension::SCHEMA, $row->fingerprint, $request, $result);
            }
        }

        $this->writes[] = ['context' => $context, 'lines' => $lines];

        return new SchemaWriteResult($inserted, [], $this->assignments instanceof EncounterAssignmentsInterface ? $counts : null);
    }

    #[\Override]
    public function removeForImport(int $importId): int
    {
        $this->removedImports[] = $importId;
        $known = \count($this->known);
        $this->known = array_filter($this->known, static fn (int $owner): bool => $owner !== $importId);

        return $known - \count($this->known);
    }
}
