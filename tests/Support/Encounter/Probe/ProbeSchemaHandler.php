<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Probe;

use App\Encounter\Contract\Import\EncounterRowError;
use App\Encounter\Contract\Import\EncounterSchemaHandlerInterface;
use App\Encounter\Contract\Import\RowError;
use App\Encounter\Contract\Import\RowMappingResult;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Contract\Import\SchemaWriteResult;

/**
 * Handler of the test-only schema `probe`: maps rows without a database and records what the shared
 * workflow handed it. A fingerprint seen before counts as known (duplicate); a row with fingerprint `0…0`
 * is rejected, one without `recorded_at` is skipped.
 */
final class ProbeSchemaHandler implements EncounterSchemaHandlerInterface
{
    /** @var list<array{context: SchemaWriteContext, lines: list<int>}> */
    public array $writes = [];

    /** @var list<int> */
    public array $removedImports = [];

    /** @var array<string, true> */
    private array $known = [];

    #[\Override]
    public function schema(): string
    {
        return ProbeDimension::SCHEMA;
    }

    #[\Override]
    public function mapRow(int $line, array $fields, array $cells): RowMappingResult
    {
        if (\count($cells) !== \count($fields)) {
            return RowMappingResult::rejected([new RowError('*', EncounterRowError::ColumnCount)]);
        }

        [$fingerprint, , $recordedAt] = $cells;
        if (str_repeat('0', 64) === $fingerprint) {
            return RowMappingResult::rejected([new RowError('record_fingerprint', EncounterRowError::InvalidCode)]);
        }

        if ('' === $recordedAt) {
            return RowMappingResult::skipped();
        }

        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $recordedAt, new \DateTimeZone('Europe/Berlin'));

        return RowMappingResult::valid(new ProbeRow($line, $fingerprint, false === $at ? null : $at));
    }

    #[\Override]
    public function write(SchemaWriteContext $context, array $rows): SchemaWriteResult
    {
        $lines = [];
        $inserted = 0;
        foreach ($rows as $row) {
            \assert($row instanceof ProbeRow);
            $lines[] = $row->line();
            if (!isset($this->known[$row->fingerprint])) {
                $this->known[$row->fingerprint] = true;
                ++$inserted;
            }
        }

        $this->writes[] = ['context' => $context, 'lines' => $lines];

        return new SchemaWriteResult($inserted);
    }

    #[\Override]
    public function removeForImport(int $importId): int
    {
        $this->removedImports[] = $importId;

        return 0;
    }
}
