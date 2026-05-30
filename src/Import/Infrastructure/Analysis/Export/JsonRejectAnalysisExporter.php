<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Analysis\Export;

use App\Import\Application\Analysis\DTO\RejectAnalysisResult;

final class JsonRejectAnalysisExporter implements RejectAnalysisExporterInterface
{
    #[\Override]
    public function supports(string $format): bool
    {
        return 'json' === $format;
    }

    #[\Override]
    public function export(RejectAnalysisResult $result, string $outputPath): void
    {
        $payload = [
            'totalRejects' => $result->totalRejects,
            'distinctGroups' => $result->distinctGroupCount(),
            'groups' => array_map(
                static fn (\App\Import\Application\Analysis\DTO\RejectAnalysisGroup $group): array => [
                    'count' => $group->count,
                    'field' => $group->field,
                    'rejected_value' => $group->rejectedValue,
                    'reason' => $group->reason,
                    'example_file' => $group->exampleFile,
                    'example_line' => $group->exampleLine,
                    'suggested_transformer_hint' => $group->suggestedTransformerHint,
                    'example_raw_row' => $group->exampleRawRow,
                ],
                $result->groups,
            ),
        ];

        file_put_contents(
            $outputPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
