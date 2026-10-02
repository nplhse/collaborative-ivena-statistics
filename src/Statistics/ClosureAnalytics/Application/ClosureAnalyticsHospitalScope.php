<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;

/**
 * Closure analytics always queries an explicit hospital id list from
 * {@see HospitalAccessInterface::accessibleHospitalIds()} (Statistics permission).
 * An empty selection or a foreign id never widens that list.
 */
final readonly class ClosureAnalyticsHospitalScope
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
    ) {
    }

    /**
     * @return list<int>
     */
    public function allowedHospitalIds(?User $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        if (!$this->hospitalAccess->isAdminHospitalScopeUser($user)
            && !\in_array(UserRole::PARTICIPANT, $user->getRoles(), true)
        ) {
            return [];
        }

        return $this->hospitalAccess->accessibleHospitalIds($user);
    }

    /**
     * @return list<int>
     */
    public function effectiveHospitalIds(
        ?User $user,
        StatisticsFilter $filter,
        ClosureAnalyticsFilter $closureFilter,
    ): array {
        $scopedHospitalId = StatisticsFilterScope::Hospital === $filter->scope ? $filter->hospitalId : null;

        return $this->restrictHospitalIds(
            $this->allowedHospitalIds($user),
            $scopedHospitalId,
            $closureFilter->hospitalIdsSubmitted,
            $closureFilter->hospitalIds,
        );
    }

    public function eventKeyHospitalIsForbidden(string $eventKey, ?User $user): bool
    {
        if (1 !== preg_match('/^(?:group|cluster):(\d+):/', $eventKey, $matches)) {
            return false;
        }

        return !\in_array((int) $matches[1], $this->allowedHospitalIds($user), true);
    }

    /**
     * @param list<int> $allowed
     * @param list<int> $submittedIds
     *
     * @return list<int>
     */
    public function restrictHospitalIds(
        array $allowed,
        ?int $scopedHospitalId,
        bool $submitted,
        array $submittedIds,
    ): array {
        if ([] === $allowed) {
            return [];
        }

        $base = $allowed;
        if (null !== $scopedHospitalId && $scopedHospitalId > 0 && \in_array($scopedHospitalId, $allowed, true)) {
            $base = [$scopedHospitalId];
        }

        if (!$submitted) {
            return $base;
        }

        return array_values(array_intersect($submittedIds, $base));
    }
}
