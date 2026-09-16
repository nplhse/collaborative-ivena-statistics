<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\Application\Insights\InsightSubject;

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

    public function resolve(IndicationSubjectType $type, int $id): ?IndicationSubject
    {
        return match ($type) {
            IndicationSubjectType::Single => $this->resolveSingle($id),
            IndicationSubjectType::Group => $this->resolveGroup($id),
        };
    }

    public function toInsightSubject(IndicationSubject $subject): InsightSubject
    {
        $dimension = IndicationSubjectType::Group === $subject->type
            ? InsightDimensionKey::IndicationGroups
            : InsightDimensionKey::Indications;

        $code = null;
        if (IndicationSubjectType::Single === $subject->type) {
            $indication = $this->indicationRepository->find($subject->id);
            $code = $indication?->getCode();
            $publicId = $indication?->getPublicId()?->toRfc4122();
        } else {
            $group = $this->groupRepository->find($subject->id);
            $publicId = $group?->getPublicId()?->toRfc4122();
        }

        return new InsightSubject(
            $dimension,
            $subject->id,
            $subject->label,
            InsightPopulationFilter::indications($subject->indicationIds),
            $code,
            $publicId,
        );
    }
}
