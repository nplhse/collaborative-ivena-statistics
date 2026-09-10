<?php

declare(strict_types=1);

namespace App\Import\Application\Analysis;

/**
 * @phpstan-type ImportRejectAnalysisRow array{
 *     id: int,
 *     lineNumber: ?int,
 *     messages: list<string>,
 *     row: array<string, mixed>,
 *     importId: ?int,
 *     importName: ?string,
 *     importFilePath: ?string,
 *     hospitalName: ?string
 * }
 */
interface ImportRejectAnalysisReader
{
    /**
     * @return iterable<ImportRejectAnalysisRow>
     */
    public function iterateForAnalysis(): iterable;
}
