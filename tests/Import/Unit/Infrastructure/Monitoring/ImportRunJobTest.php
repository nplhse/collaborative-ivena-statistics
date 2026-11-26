<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Monitoring;

use App\Encounter\Application\Message\ImportEncountersMessage;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\Message\ImportRunMessageInterface;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Monitoring\ImportRunTelemetry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each import type is its own job_run job (ADR 018, #1070); the message names the type, so a run whose
 * retries are exhausted keeps the job of its normal attempts.
 */
final class ImportRunJobTest extends TestCase
{
    /**
     * @return iterable<string, array{ImportRunMessageInterface, ImportType, string}>
     */
    public static function messages(): iterable
    {
        yield 'allocation' => [new ImportAllocationsMessage(1, 'run'), ImportType::ALLOCATION, 'allocation_import'];
        yield 'closure' => [new ImportClosuresMessage(1, 'run'), ImportType::CLOSURE, 'closure_import'];
        yield 'encounter' => [new ImportEncountersMessage(1, 'run'), ImportType::ENCOUNTER, 'encounter_import'];
    }

    #[DataProvider('messages')]
    public function testTheMessageNamesItsImportTypeAndTheTypeItsJob(ImportRunMessageInterface $message, ImportType $type, string $job): void
    {
        self::assertSame($type, $message->getImportType());
        self::assertSame($job, ImportRunTelemetry::jobFor($type));
    }

    public function testEveryImportTypeHasItsOwnJob(): void
    {
        $jobs = array_map(ImportRunTelemetry::jobFor(...), ImportType::cases());

        self::assertSame($jobs, array_values(array_unique($jobs)));
    }
}
