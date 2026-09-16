<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights\Provider;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightNavPlacement;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\Application\Insights\InsightSubject;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IndicationGroupInsightDimension extends AbstractEntityInsightDimension
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private IndicationGroupRepository $groupRepository,
    ) {
        parent::__construct($entityManager);
    }

    #[\Override]
    public function key(): InsightDimensionKey
    {
        return InsightDimensionKey::IndicationGroups;
    }

    #[\Override]
    public function entityFqcn(): string
    {
        return IndicationGroup::class;
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.insights.dimension.indication_groups.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.insights.dimension.indication_groups.description';
    }

    #[\Override]
    public function allValuesLinkTranslationKey(): string
    {
        return 'stats.insights.dimension.indication_groups.all';
    }

    #[\Override]
    public function icon(): string
    {
        return 'tabler:folders';
    }

    #[\Override]
    public function navPlacement(): InsightNavPlacement
    {
        return InsightNavPlacement::Nested;
    }

    #[\Override]
    public function navOrder(): int
    {
        return 11;
    }

    #[\Override]
    public function nestedUnder(): InsightDimensionKey
    {
        return InsightDimensionKey::Indications;
    }

    #[\Override]
    public function resolve(int $id): ?InsightSubject
    {
        $group = $this->groupRepository->find($id);
        if (!$group instanceof IndicationGroup) {
            return null;
        }

        $indicationIds = $this->groupRepository->getIndicationIds($id);
        $category = $group->getCategory();

        return new InsightSubject(
            $this->key(),
            $id,
            $group->getName() ?? '',
            InsightPopulationFilter::indications($indicationIds),
            null,
            $group->getPublicId()?->toRfc4122(),
            \is_string($category) && '' !== $category ? $category : null,
        );
    }

    #[\Override]
    protected function entityQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from($this->entityFqcn(), 'e')
            ->select('e.id AS id', 'e.name AS name', 'e.publicId AS publicId', 'e.category AS contextLabel')
            ->orderBy('e.name', 'ASC');
    }
}
