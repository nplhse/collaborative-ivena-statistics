<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;

final readonly class IndicationSubjectResolver
{
    public function __construct(
        private IndicationNormalizedRepository $indicationRepository,
        private IndicationGroupRepository $groupRepository,
    ) {
    }

    public function resolveSingle(int $indicationId): ?IndicationSubject
    {
        $indication = $this->indicationRepository->find($indicationId);
        if (!$indication instanceof IndicationNormalized) {
            return null;
        }

        $label = $this->indicationRepository->getDatalistLabelById($indicationId) ?? $indication->getName() ?? '';

        return new IndicationSubject(
            IndicationSubjectType::Single,
            $indicationId,
            $label,
            [$indicationId],
        );
    }

    public function resolveGroup(int $groupId): ?IndicationSubject
    {
        $group = $this->groupRepository->find($groupId);
        if (!$group instanceof IndicationGroup) {
            return null;
        }

        $indicationIds = $this->groupRepository->getIndicationIds($groupId);

        return new IndicationSubject(
            IndicationSubjectType::Group,
            $groupId,
            $group->getName() ?? '',
            $indicationIds,
        );
    }
}
