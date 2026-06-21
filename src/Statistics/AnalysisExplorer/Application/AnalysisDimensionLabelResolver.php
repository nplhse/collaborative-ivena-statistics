<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalysisDimensionLabelResolver
{
    /** @var array<string, array<int, string>> */
    private array $entityLabelsByDimension = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly GenericAnalysisEntityLabelResolverInterface $entityLabelResolver,
        private readonly HospitalCohortLabelResolver $hospitalCohortLabelResolver,
    ) {
    }

    public function warmEntityLabels(
        AnalysisResult $result,
        AnalysisDimension $primary,
        ?AnalysisDimension $series,
    ): void {
        /** @var array<string, list<int>> $idsByDimension */
        $idsByDimension = [];

        foreach ($result->rows as $row) {
            $this->collectEntityId($idsByDimension, $primary->key, $row->bucket);
            if ($series instanceof AnalysisDimension) {
                $this->collectEntityId($idsByDimension, $series->key, $row->series);
            }
        }

        $labels = [];
        foreach ($idsByDimension as $dimensionKey => $ids) {
            $labels[$dimensionKey] = $this->entityLabelResolver->resolve($dimensionKey, $ids);
        }

        $this->entityLabelsByDimension = $labels;
    }

    public function labelFor(AnalysisDimension $dimension, int|string|float|null $bucket): string
    {
        if (null === $bucket || '' === $bucket) {
            return '';
        }

        $bucketKey = $this->bucketKey($bucket);

        if ('__null__' === $bucketKey) {
            return 'Unknown';
        }

        if ('hospital_cohort' === $dimension->key) {
            $cohortKey = HospitalCohortKey::tryFrom($bucketKey);
            if ($cohortKey instanceof HospitalCohortKey) {
                return $this->hospitalCohortLabelResolver->label($cohortKey);
            }
        }

        $lookupKey = is_numeric($bucketKey) ? (int) $bucketKey : $bucketKey;
        if (isset($dimension->valueLabelTranslationKeys[$lookupKey])) {
            return $this->translator->trans($dimension->valueLabelTranslationKeys[$lookupKey]);
        }
        if (isset($dimension->valueLabelTranslationKeys[$bucketKey])) {
            return $this->translator->trans($dimension->valueLabelTranslationKeys[$bucketKey]);
        }
        if (isset($dimension->valueLabels[$lookupKey])) {
            return $dimension->valueLabels[$lookupKey];
        }
        if (isset($dimension->valueLabels[$bucketKey])) {
            return $dimension->valueLabels[$bucketKey];
        }

        if (AnalysisDimensionType::Boolean === $dimension->type) {
            return match ($bucketKey) {
                '1', 'true' => $this->translator->trans('action.yes'),
                '0', 'false' => $this->translator->trans('action.no'),
                default => $bucketKey,
            };
        }

        if (is_numeric($bucketKey) && isset($this->entityLabelsByDimension[$dimension->key])) {
            $entityId = (int) $bucketKey;

            return $this->entityLabelsByDimension[$dimension->key][$entityId] ?? $bucketKey;
        }

        if ('month' === $dimension->key && is_numeric($bucketKey)) {
            $month = max(1, min(12, (int) $bucketKey));
            $formatted = \IntlDateFormatter::formatObject(
                new \DateTimeImmutable(sprintf('2024-%02d-01', $month)),
                'MMM',
                'en',
            );

            return false !== $formatted && '' !== $formatted ? $formatted : (string) $month;
        }

        return $bucketKey;
    }

    /**
     * @param array<string, list<int>> $idsByDimension
     */
    private function collectEntityId(array &$idsByDimension, string $dimensionKey, mixed $value): void
    {
        if (!$this->entityLabelResolver->supports($dimensionKey) || !is_numeric($value)) {
            return;
        }

        $idsByDimension[$dimensionKey] ??= [];
        $idsByDimension[$dimensionKey][] = (int) $value;
    }

    private function bucketKey(int|string|float|null $bucket): string
    {
        if (null === $bucket) {
            return '__null__';
        }

        return (string) $bucket;
    }
}
