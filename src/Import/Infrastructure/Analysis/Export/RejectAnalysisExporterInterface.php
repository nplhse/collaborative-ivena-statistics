<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Analysis\Export;

use App\Import\Application\Analysis\DTO\RejectAnalysisResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('import.reject_analysis_exporter')]
interface RejectAnalysisExporterInterface
{
    public function export(RejectAnalysisResult $result, string $outputPath): void;

    public function supports(string $format): bool;
}
