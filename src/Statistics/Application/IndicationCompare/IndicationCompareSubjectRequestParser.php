<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare;

use App\Statistics\Application\IndicationCompare\DTO\IndicationCompareSubjectPair;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class IndicationCompareSubjectRequestParser
{
    public function parse(Request $request): ?IndicationCompareSubjectPair
    {
        $typeA = $this->parseType($request->query->get(StatisticsQueryKeys::SUBJECT_A_TYPE));
        $idA = $this->parseId($request->query->get(StatisticsQueryKeys::SUBJECT_A_ID));
        $typeB = $this->parseType($request->query->get(StatisticsQueryKeys::SUBJECT_B_TYPE));
        $idB = $this->parseId($request->query->get(StatisticsQueryKeys::SUBJECT_B_ID));

        if (!$typeA instanceof IndicationSubjectType || null === $idA) {
            $legacyIdA = $this->parseId($request->query->get(StatisticsQueryKeys::INDICATION_A));
            if (null !== $legacyIdA) {
                $typeA = IndicationSubjectType::Single;
                $idA = $legacyIdA;
            }
        }

        if (!$typeB instanceof IndicationSubjectType || null === $idB) {
            $legacyIdB = $this->parseId($request->query->get(StatisticsQueryKeys::INDICATION_B));
            if (null !== $legacyIdB) {
                $typeB = IndicationSubjectType::Single;
                $idB = $legacyIdB;
            }
        }

        if (!$typeA instanceof IndicationSubjectType
            || null === $idA
            || !$typeB instanceof IndicationSubjectType
            || null === $idB) {
            return null;
        }

        return new IndicationCompareSubjectPair($typeA, $idA, $typeB, $idB);
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
