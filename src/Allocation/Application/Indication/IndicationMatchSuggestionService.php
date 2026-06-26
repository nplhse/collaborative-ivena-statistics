<?php

declare(strict_types=1);

namespace App\Allocation\Application\Indication;

use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Import\Infrastructure\Indication\IndicationKey;

final readonly class IndicationMatchSuggestionService
{
    public function __construct(
        private IndicationNormalizedRepository $normalizedRepository,
    ) {
    }

    /**
     * @return list<array{id: int, label: string, score: int}>
     */
    public function suggest(IndicationRaw $raw, int $limit = 5): array
    {
        $rawCode = $raw->getCode();
        $rawName = $raw->getName();
        if (null === $rawCode || null === $rawName) {
            return [];
        }

        $normalizedText = IndicationKey::normalizeText($rawName);
        $candidates = [];

        foreach ($this->normalizedRepository->getDatalist() as $row) {
            $score = 0;
            if (preg_match('/\((\d+)\)$/', $row['label'], $matches) && (int) $matches[1] === $rawCode) {
                ++$score;
            }

            $candidateName = preg_replace('/\s*\(\d+\)$/', '', $row['label']) ?? $row['label'];
            if (IndicationKey::normalizeText($candidateName) === $normalizedText) {
                $score += 3;
            } elseif (str_contains(IndicationKey::normalizeText($candidateName), $normalizedText)
                || str_contains($normalizedText, IndicationKey::normalizeText($candidateName))) {
                ++$score;
            }

            if ($score > 0) {
                $candidates[] = [
                    'id' => $row['id'],
                    'label' => $row['label'],
                    'score' => $score,
                ];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['label'] <=> $b['label']);

        return \array_slice($candidates, 0, $limit);
    }
}
