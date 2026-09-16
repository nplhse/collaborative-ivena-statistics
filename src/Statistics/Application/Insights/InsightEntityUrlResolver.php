<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class InsightEntityUrlResolver
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function resolve(?object $entity): ?string
    {
        if (null === $entity) {
            return null;
        }

        $resolved = match (true) {
            $entity instanceof IndicationNormalized => [$entity->getId(), InsightDimensionKey::Indications],
            $entity instanceof IndicationGroup => [$entity->getId(), InsightDimensionKey::IndicationGroups],
            $entity instanceof Speciality => [$entity->getId(), InsightDimensionKey::Specialities],
            $entity instanceof Assignment => [$entity->getId(), InsightDimensionKey::Assignments],
            $entity instanceof Department => [$entity->getId(), InsightDimensionKey::Departments],
            $entity instanceof Occasion => [$entity->getId(), InsightDimensionKey::Occasions],
            $entity instanceof Infection => [$entity->getId(), InsightDimensionKey::Infections],
            $entity instanceof SecondaryTransport => [$entity->getId(), InsightDimensionKey::SecondaryTransports],
            default => null,
        };

        if (null === $resolved) {
            return null;
        }

        [$id, $dimension] = $resolved;
        if (!\is_int($id)) {
            return null;
        }

        return $this->urlGenerator->generate('app_stats_insights_show', [
            'dimension' => $dimension->value,
            'id' => $id,
        ]);
    }
}
