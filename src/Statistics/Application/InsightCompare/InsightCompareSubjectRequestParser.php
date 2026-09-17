<?php

declare(strict_types=1);

namespace App\Statistics\Application\InsightCompare;

use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;
use App\Statistics\Application\InsightCompare\DTO\InsightCompareSubjectPair;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class InsightCompareSubjectRequestParser
{
    public function parse(Request $request, ?InsightDimensionKey $legacyPathDimension = null): ?InsightCompareSubjectPair
    {
        $dimensionA = $this->parseDimension($request->query->get(StatisticsQueryKeys::SUBJECT_A_DIMENSION));
        $idA = $this->parseId($request->query->get(StatisticsQueryKeys::SUBJECT_A_ID));
        $dimensionB = $this->parseDimension($request->query->get(StatisticsQueryKeys::SUBJECT_B_DIMENSION));
        $idB = $this->parseId($request->query->get(StatisticsQueryKeys::SUBJECT_B_ID));

        if (!$dimensionA instanceof InsightDimensionKey || null === $idA) {
            $dimensionA = $this->dimensionFromLegacyType(
                $request->query->get(StatisticsQueryKeys::SUBJECT_A_TYPE),
                $legacyPathDimension,
            );
            $idA ??= $this->parseId($request->query->get(StatisticsQueryKeys::INDICATION_A));
        }

        if (!$dimensionB instanceof InsightDimensionKey || null === $idB) {
            $dimensionB = $this->dimensionFromLegacyType(
                $request->query->get(StatisticsQueryKeys::SUBJECT_B_TYPE),
                $legacyPathDimension,
            );
            $idB ??= $this->parseId($request->query->get(StatisticsQueryKeys::INDICATION_B));
        }

        if (!$dimensionA instanceof InsightDimensionKey
            || null === $idA
            || !$dimensionB instanceof InsightDimensionKey
            || null === $idB) {
            if ($legacyPathDimension instanceof InsightDimensionKey) {
                $idA ??= $this->parseId($request->query->get(StatisticsQueryKeys::SUBJECT_A_ID));
                $idB ??= $this->parseId($request->query->get(StatisticsQueryKeys::SUBJECT_B_ID));
                if (null !== $idA && null !== $idB) {
                    return new InsightCompareSubjectPair($legacyPathDimension, $idA, $legacyPathDimension, $idB);
                }
            }

            return null;
        }

        return new InsightCompareSubjectPair($dimensionA, $idA, $dimensionB, $idB);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function canonicalizeQuery(array $query, ?InsightDimensionKey $pathDimension = null): array
    {
        $request = Request::create('/', Request::METHOD_GET, $query);
        $pair = $this->parse($request, $pathDimension);
        if (!$pair instanceof InsightCompareSubjectPair) {
            return $query;
        }

        unset(
            $query['dimension'],
            $query[StatisticsQueryKeys::SUBJECT_A_TYPE],
            $query[StatisticsQueryKeys::SUBJECT_B_TYPE],
            $query[StatisticsQueryKeys::INDICATION_A],
            $query[StatisticsQueryKeys::INDICATION_B],
        );
        $query[StatisticsQueryKeys::SUBJECT_A_DIMENSION] = $pair->dimensionA->value;
        $query[StatisticsQueryKeys::SUBJECT_A_ID] = $pair->idA;
        $query[StatisticsQueryKeys::SUBJECT_B_DIMENSION] = $pair->dimensionB->value;
        $query[StatisticsQueryKeys::SUBJECT_B_ID] = $pair->idB;

        return $query;
    }

    private function dimensionFromLegacyType(mixed $typeValue, ?InsightDimensionKey $pathDimension): ?InsightDimensionKey
    {
        $type = $this->parseType($typeValue);
        if (IndicationSubjectType::Group === $type) {
            return InsightDimensionKey::IndicationGroups;
        }
        if (IndicationSubjectType::Single === $type) {
            return $pathDimension?->compareFamily() ?? InsightDimensionKey::Indications;
        }

        return $pathDimension;
    }

    private function parseDimension(mixed $value): ?InsightDimensionKey
    {
        if (!\is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ('' === $normalized) {
            return null;
        }

        return InsightDimensionKey::tryFrom($normalized);
    }

    private function parseType(mixed $value): ?IndicationSubjectType
    {
        if (!\is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ('' === $normalized) {
            return null;
        }

        return IndicationSubjectType::tryFrom($normalized);
    }

    private function parseId(mixed $value): ?int
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $stringValue = (string) $value;
        if ('' === $stringValue || !ctype_digit($stringValue)) {
            return null;
        }

        return (int) $stringValue;
    }
}
