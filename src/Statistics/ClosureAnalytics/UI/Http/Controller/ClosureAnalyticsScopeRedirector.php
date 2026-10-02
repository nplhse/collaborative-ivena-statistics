<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\ClosureAnalyticsHospitalScope;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;

/**
 * Rewrites closure-analytics URLs onto the caller's hospital selection.
 * Public, state, cohort, dispatch-area and foreign hospital scopes never stay in the URL.
 */
final readonly class ClosureAnalyticsScopeRedirector
{
    public function __construct(
        private ClosureAnalyticsHospitalScope $hospitalScope,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>|null
     */
    public function canonicalRedirectQuery(array $query, ?User $user): ?array
    {
        $allowed = $this->hospitalScope->allowedHospitalIds($user);
        if ([] === $allowed) {
            return null;
        }

        $target = $this->targetHospitalIds($query, $allowed);
        if ($this->isAlreadyCanonical($query, $allowed, $target)) {
            return null;
        }

        return $this->applySelection($query, $allowed, $target);
    }

    /**
     * @param array<string, mixed> $query
     * @param list<int>            $allowed
     *
     * @return list<int>
     */
    private function targetHospitalIds(array $query, array $allowed): array
    {
        [$scope, $hospitalId] = $this->hospitalScopeFromQuery($query);
        $scopedHospitalId = StatisticsFilterScope::Hospital->value === $scope ? $hospitalId : null;

        return $this->hospitalScope->restrictHospitalIds(
            $allowed,
            $scopedHospitalId,
            $this->hospitalIdsWereSubmitted($query),
            $this->submittedHospitalIds($query),
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param list<int>            $allowed
     * @param list<int>            $target
     */
    private function isAlreadyCanonical(array $query, array $allowed, array $target): bool
    {
        if ($this->hasDisallowedScopeKeys($query)) {
            return false;
        }

        [$scope, $hospitalId] = $this->hospitalScopeFromQuery($query);
        $submittedIds = $this->hospitalIdsWereSubmitted($query) ? $this->submittedHospitalIds($query) : null;

        if ([] === $target) {
            return null !== $submittedIds
                && [] === $submittedIds
                && StatisticsFilterScope::MyHospitals->value === $scope
                && $hospitalId <= 0;
        }

        if (1 === \count($target)) {
            return StatisticsFilterScope::Hospital->value === $scope
                && $hospitalId === $target[0]
                && null === $submittedIds;
        }

        $sortedTarget = $target;
        $sortedAllowed = $allowed;
        sort($sortedTarget);
        sort($sortedAllowed);

        if ($sortedTarget === $sortedAllowed) {
            return StatisticsFilterScope::MyHospitals->value === $scope
                && $hospitalId <= 0
                && null === $submittedIds;
        }

        $sortedSubmitted = $submittedIds ?? [];
        sort($sortedSubmitted);

        return StatisticsFilterScope::MyHospitals->value === $scope
            && $hospitalId <= 0
            && $sortedSubmitted === $sortedTarget;
    }

    /**
     * @param array<string, mixed> $query
     * @param list<int>            $allowed
     * @param list<int>            $target
     *
     * @return array<string, mixed>
     */
    private function applySelection(array $query, array $allowed, array $target): array
    {
        unset(
            $query[StatisticsQueryKeys::HOSPITAL],
            $query[StatisticsQueryKeys::STATE],
            $query[StatisticsQueryKeys::COHORT],
            $query[StatisticsQueryKeys::DISPATCH_AREA],
            $query[ClosureAnalyticsFilterRequestResolver::HOSPITALS],
            $query[ClosureAnalyticsFilterRequestResolver::HOSPITALS_SUBMITTED],
        );

        if ([] === $target) {
            $query[StatisticsQueryKeys::SCOPE] = StatisticsFilterScope::MyHospitals->value;
            $query[ClosureAnalyticsFilterRequestResolver::HOSPITALS_SUBMITTED] = '1';

            return $query;
        }

        $sortedTarget = $target;
        $sortedAllowed = $allowed;
        sort($sortedTarget);
        sort($sortedAllowed);

        if (1 === \count($target)) {
            $query[StatisticsQueryKeys::SCOPE] = StatisticsFilterScope::Hospital->value;
            $query[StatisticsQueryKeys::HOSPITAL] = (string) $target[0];

            return $query;
        }

        $query[StatisticsQueryKeys::SCOPE] = StatisticsFilterScope::MyHospitals->value;
        if ($sortedTarget !== $sortedAllowed) {
            $query[ClosureAnalyticsFilterRequestResolver::HOSPITALS] = array_map(
                static fn (int $id): string => (string) $id,
                $sortedTarget,
            );
            $query[ClosureAnalyticsFilterRequestResolver::HOSPITALS_SUBMITTED] = '1';
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function hasDisallowedScopeKeys(array $query): bool
    {
        foreach ([StatisticsQueryKeys::STATE, StatisticsQueryKeys::COHORT, StatisticsQueryKeys::DISPATCH_AREA] as $key) {
            if (isset($query[$key]) && '' !== (string) $query[$key]) {
                return true;
            }
        }

        [$scope] = $this->hospitalScopeFromQuery($query);

        return !\in_array($scope, [StatisticsFilterScope::Hospital->value, StatisticsFilterScope::MyHospitals->value], true);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function hospitalIdsWereSubmitted(array $query): bool
    {
        return \array_key_exists(ClosureAnalyticsFilterRequestResolver::HOSPITALS, $query)
            || \array_key_exists(ClosureAnalyticsFilterRequestResolver::HOSPITALS_SUBMITTED, $query);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<int>
     */
    private function submittedHospitalIds(array $query): array
    {
        return $this->positiveIdList($query[ClosureAnalyticsFilterRequestResolver::HOSPITALS] ?? []);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: string, 1: int}
     */
    private function hospitalScopeFromQuery(array $query): array
    {
        $scope = trim((string) ($query[StatisticsQueryKeys::SCOPE] ?? ''));
        $hospitalId = $this->positiveInt($query[StatisticsQueryKeys::HOSPITAL] ?? null);
        if (str_contains($scope, ':')) {
            [$token, $operand] = array_pad(explode(':', $scope, 2), 2, '');
            $scope = trim($token);
            if (StatisticsFilterScope::Hospital->value === $scope && $hospitalId <= 0) {
                $hospitalId = $this->positiveInt($operand);
            }
        }

        return [$scope, $hospitalId];
    }

    /**
     * @return list<int>
     */
    private function positiveIdList(mixed $value): array
    {
        $values = \is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($values as $item) {
            $id = $this->positiveInt($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param mixed $value query value, which may be an int or a numeric string
     */
    private function positiveInt(mixed $value): int
    {
        if ((\is_int($value) || (\is_string($value) && ctype_digit($value))) && (int) $value > 0) {
            return (int) $value;
        }

        return 0;
    }
}
