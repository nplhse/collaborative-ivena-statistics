<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsDrawerFilterFactory
{
    public function fromRequest(Request $request): StatisticsDrawerFilter
    {
        return $this->fromQuery($request->query);
    }

    /**
     * @param InputBag<string> $query
     */
    public function fromQuery(InputBag $query): StatisticsDrawerFilter
    {
        return new StatisticsDrawerFilter(
            gender: $this->parsePositiveInt($query->getString('gender')),
            urgency: $this->parseUrgency($query->getString('urgency')),
            ageGroup: $this->parseNonEmptyString($query->getString('age_group')),
            department: $this->parsePositiveInt($query->getString('department')),
            speciality: $this->parsePositiveInt($query->getString('speciality')),
            requiresResus: $this->parseOptionalBoolean($query, 'requiresResus'),
            requiresCathlab: $this->parseOptionalBoolean($query, 'requiresCathlab'),
            isVentilated: $this->parseOptionalBoolean($query, 'isVentilated'),
            isShock: $this->parseOptionalBoolean($query, 'isShock'),
            isCpr: $this->parseOptionalBoolean($query, 'isCPR'),
            isPregnant: $this->parseOptionalBoolean($query, 'isPregnant'),
            isWorkAccident: $this->parseOptionalBoolean($query, 'isWorkAccident'),
            isInfectious: $this->parseOptionalBoolean($query, 'isInfectious'),
            infection: $this->parsePositiveInt($query->getString('infection')),
        );
    }

    private function parsePositiveInt(string $value): ?int
    {
        $trimmed = trim($value);
        if ('' === $trimmed || !ctype_digit($trimmed)) {
            return null;
        }

        $int = (int) $trimmed;

        return $int > 0 ? $int : null;
    }

    private function parseNonEmptyString(string $value): ?string
    {
        $trimmed = trim($value);

        return '' !== $trimmed ? $trimmed : null;
    }

    private function parseUrgency(string $value): ?int
    {
        return AllocationUrgency::tryFromQueryValue($value)?->value;
    }

    /**
     * @param InputBag<string> $query
     */
    private function parseOptionalBoolean(InputBag $query, string $key): ?bool
    {
        if (!\in_array($key, StatisticsQueryKeys::DRAWER_FILTERS, true) || !$query->has($key)) {
            return null;
        }

        $value = $query->get($key);
        if (null === $value || '' === $value) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
