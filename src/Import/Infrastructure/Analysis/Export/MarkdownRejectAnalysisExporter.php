<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Analysis\Export;

use App\Import\Application\Analysis\DTO\RejectAnalysisResult;

final class MarkdownRejectAnalysisExporter implements RejectAnalysisExporterInterface
{
    #[\Override]
    public function supports(string $format): bool
    {
        return 'md' === $format;
    }

    #[\Override]
    public function export(RejectAnalysisResult $result, string $outputPath): void
    {
        $lines = [
            '# Import Reject Analysis',
            '',
            sprintf('Total rejects: %d  ', $result->totalRejects),
            sprintf('Distinct groups: %d', $result->distinctGroupCount()),
            '',
            '| Count | Field | Rejected value | Reason | Example |',
            '| ---: | --- | --- | --- | --- |',
        ];

        foreach ($result->groups as $group) {
            $example = trim(sprintf(
                '%s line %s',
                $group->exampleFile,
                $group->exampleLine,
            ));

            $lines[] = sprintf(
                '| %d | %s | %s | %s | %s |',
                $group->count,
                $this->escapeCell($group->field),
                $this->escapeCell($group->rejectedValue),
                $this->escapeCell($group->reason),
                $this->escapeCell($example),
            );
        }

        file_put_contents($outputPath, implode("\n", $lines)."\n");
    }

    private function escapeCell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
