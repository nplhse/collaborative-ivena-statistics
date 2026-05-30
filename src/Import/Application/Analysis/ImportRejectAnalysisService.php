<?php

declare(strict_types=1);

namespace App\Import\Application\Analysis;

use App\Import\Application\Analysis\DTO\RejectAnalysisGroup;
use App\Import\Application\Analysis\DTO\RejectAnalysisResult;
use App\Import\Infrastructure\Repository\ImportRejectRepository;

final readonly class ImportRejectAnalysisService
{
    private const int RAW_ROW_MAX_LENGTH = 2000;

    public function __construct(
        private ImportRejectRepository $importRejectRepository,
        private RejectMessageNormalizer $messageNormalizer,
        private TransformerHintGenerator $hintGenerator,
    ) {
    }

    public function analyze(
        int $minCount = 1,
        ?int $limit = null,
        bool $includeExamples = false,
    ): RejectAnalysisResult {
        $totalRejects = $this->importRejectRepository->countAll();
        $aggregated = [];

        foreach ($this->importRejectRepository->iterateForAnalysis() as $reject) {
            $exampleFile = $this->resolveExampleFile($reject['importFilePath'], $reject['importName']);
            $exampleLine = null !== $reject['lineNumber'] ? (string) $reject['lineNumber'] : '';
            $exampleRawRow = $includeExamples ? $this->encodeRawRow($reject['row']) : '';

            foreach ($reject['messages'] as $message) {
                if ('' === trim($message)) {
                    continue;
                }

                $normalized = $this->messageNormalizer->normalize($message, $reject['row']);
                $key = $this->buildGroupKey(
                    $normalized['field'],
                    $normalized['rejected_value'],
                    $normalized['reason'],
                );

                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'count' => 0,
                        'field' => $normalized['field'],
                        'rejected_value' => $normalized['rejected_value'],
                        'reason' => $normalized['reason'],
                        'example_file' => $exampleFile,
                        'example_line' => $exampleLine,
                        'example_raw_row' => $exampleRawRow,
                    ];
                }

                ++$aggregated[$key]['count'];
            }
        }

        $groups = [];
        foreach ($aggregated as $item) {
            if ($item['count'] < $minCount) {
                continue;
            }

            $groups[] = new RejectAnalysisGroup(
                count: $item['count'],
                field: $item['field'],
                rejectedValue: $item['rejected_value'],
                reason: $item['reason'],
                exampleFile: $item['example_file'],
                exampleLine: $item['example_line'],
                suggestedTransformerHint: $this->hintGenerator->generate(
                    $item['field'],
                    $item['rejected_value'],
                    $item['reason'],
                ),
                exampleRawRow: $includeExamples ? $item['example_raw_row'] : '',
            );
        }

        usort(
            $groups,
            static fn (RejectAnalysisGroup $a, RejectAnalysisGroup $b): int => [$b->count, $a->field, $a->rejectedValue, $a->reason]
                <=> [$a->count, $b->field, $b->rejectedValue, $b->reason],
        );

        if (null !== $limit && $limit > 0) {
            $groups = \array_slice($groups, 0, $limit);
        }

        return new RejectAnalysisResult($totalRejects, $groups);
    }

    private function buildGroupKey(string $field, string $rejectedValue, string $reason): string
    {
        return hash('xxh128', json_encode([$field, $rejectedValue, $reason], JSON_THROW_ON_ERROR));
    }

    private function resolveExampleFile(?string $filePath, ?string $importName): string
    {
        if (null !== $filePath && '' !== trim($filePath)) {
            return $filePath;
        }

        if (null !== $importName && '' !== trim($importName)) {
            return $importName;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function encodeRawRow(array $row): string
    {
        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (\strlen($encoded) <= self::RAW_ROW_MAX_LENGTH) {
            return $encoded;
        }

        return substr($encoded, 0, self::RAW_ROW_MAX_LENGTH - 1).'…';
    }
}
