<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\DTO\ImportSummary;
use App\Import\Domain\Entity\Import;

/**
 * Records one attempt of an import run (ADR 018): a job_run row, a Sentry transaction with spans and one summary log.
 * Outside begin()/end() every call is a no-op, so importers and persisters also work without a traced run.
 */
interface ImportRunTelemetryInterface
{
    /** Status of an attempt that ends with a retryable error; Messenger retries the same run. */
    public const string STATUS_RETRYING = 'retrying';

    public function begin(ImportRunContext $context, Import $import): void;

    /**
     * @param array<string, int|bool> $data ids and counts only
     */
    public function startSpan(string $op, array $data = []): void;

    /**
     * @param array<string, int|bool> $data ids and counts only
     */
    public function finishSpan(array $data = []): void;

    /** Measures the time spent reading rows from the file. */
    public function timedReader(RowReaderInterface $reader): RowReaderInterface;

    /** Measures the time spent writing rejected rows; it counts as persisting. */
    public function timedRejectWriter(RejectWriterInterface $writer): RejectWriterInterface;

    /** Opens the span around the importer loop and resets the phase times. */
    public function startRows(): void;

    /** Closes the importer loop span with read, map and persist phase spans. */
    public function rowsImported(ImportSummary $summary): void;

    /** Takes the persisted counters from the evaluated import, so the summary matches the import. */
    public function evaluated(Import $import): void;

    /**
     * @param string $status terminal ImportRunStatus value or STATUS_RETRYING
     */
    public function end(string $status, ?\Throwable $error = null): void;
}
