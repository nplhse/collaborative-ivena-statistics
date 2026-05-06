<?php

declare(strict_types=1);

namespace App\LegacyMigration\Infrastructure\Matching;

use App\Allocation\Domain\Entity\Hospital;

final readonly class HospitalMatcher
{
    public function __construct(
        private HospitalNameNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return array{hospital: Hospital, score: float, normalized: string}
     */
    public function matchOrFail(int $legacyId, string $legacyName, array $hospitals): array
    {
        $normalized = $this->normalizer->normalize($legacyName);
        if ('' === $normalized) {
            throw new \RuntimeException(sprintf('Hospital match failed for legacy id %d: normalized name is empty', $legacyId));
        }

        $candidates = [];
        foreach ($hospitals as $hospital) {
            $candidate = $this->normalizer->normalize((string) $hospital->getName());
            if ('' === $candidate) {
                continue;
            }

            similar_text($normalized, $candidate, $score);
            $score = $score / 100.0;
            if ($score >= 0.85) {
                $candidates[] = ['hospital' => $hospital, 'score' => $score, 'normalized' => $candidate];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        if ([] === $candidates) {
            throw new \RuntimeException(sprintf(
                'No hospital match for legacy id %d ("%s", normalized: "%s").',
                $legacyId,
                $legacyName,
                $normalized
            ));
        }

        if (isset($candidates[1]) && abs($candidates[0]['score'] - $candidates[1]['score']) < 0.02) {
            throw new \RuntimeException(sprintf(
                'Ambiguous hospital match for legacy id %d ("%s", normalized: "%s"). Top scores: %s / %s.',
                $legacyId,
                $legacyName,
                $normalized,
                number_format($candidates[0]['score'], 3),
                number_format($candidates[1]['score'], 3)
            ));
        }

        return $candidates[0];
    }
}

