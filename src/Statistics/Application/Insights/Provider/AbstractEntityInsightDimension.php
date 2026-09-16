<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights\Provider;

use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionProviderInterface;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\Application\Insights\InsightSubject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

abstract readonly class AbstractEntityInsightDimension implements InsightDimensionProviderInterface
{
    public function __construct(
        protected EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function nestedUnder(): ?InsightDimensionKey
    {
        return null;
    }

    #[\Override]
    public function featuredOnOverview(): bool
    {
        return false;
    }

    #[\Override]
    public function supportsCompare(): bool
    {
        return true;
    }

    #[\Override]
    public function hasCode(): bool
    {
        return false;
    }

    #[\Override]
    public function disabledInsightIds(): array
    {
        return [];
    }

    #[\Override]
    public function resolve(int $id): ?InsightSubject
    {
        $row = $this->entityQuery()
            ->andWhere('e.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!\is_array($row)) {
            return null;
        }

        return $this->subjectFromRow($row);
    }

    #[\Override]
    public function searchEntities(string $query, int $limit): array
    {
        $qb = $this->entityQuery();
        $this->applySearch($qb, $query);
        $qb->setMaxResults($limit);

        /** @var list<array{id: int|string, name: string, publicId: Uuid|string|null, code?: int|string|null, contextLabel?: ?string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map($this->normalizeEntityRow(...), $rows);
    }

    #[\Override]
    public function listEntities(?string $search): array
    {
        $qb = $this->entityQuery();
        if (null !== $search && '' !== trim($search)) {
            $this->applySearch($qb, $search);
        }

        /** @var list<array{id: int|string, name: string, publicId: Uuid|string|null, code?: int|string|null, contextLabel?: ?string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map($this->normalizeEntityRow(...), $rows);
    }

    protected function entityQuery(): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from($this->entityFqcn(), 'e')
            ->select('e.id AS id', 'e.name AS name', 'e.publicId AS publicId')
            ->orderBy('e.name', 'ASC');

        if ($this->hasCode()) {
            $qb->addSelect('e.code AS code');
            $qb->orderBy('e.code', 'ASC');
        }

        return $qb;
    }

    protected function applySearch(QueryBuilder $qb, string $query): void
    {
        $term = trim($query);
        if ('' === $term) {
            return;
        }

        $or = $qb->expr()->orX('LOWER(e.name) LIKE :q');
        $qb->setParameter('q', '%'.mb_strtolower($term).'%');

        if ($this->hasCode() && ctype_digit($term)) {
            $or->add('e.code = :code');
            $qb->setParameter('code', (int) $term);
        }

        $qb->andWhere($or);
    }

    /**
     * @param array{id: int|string, name: string, publicId?: Uuid|string|null, code?: int|string|null, contextLabel?: ?string} $row
     */
    protected function subjectFromRow(array $row): InsightSubject
    {
        $normalized = $this->normalizeEntityRow($row);

        return new InsightSubject(
            $this->key(),
            $normalized['id'],
            $normalized['label'],
            InsightPopulationFilter::of($this->key()->projectionColumn(), [$normalized['id']]),
            $normalized['code'],
            $normalized['publicId'],
            $normalized['contextLabel'],
        );
    }

    /**
     * @param array{id: int|string, name: string, publicId?: Uuid|string|null, code?: int|string|null, contextLabel?: ?string} $row
     *
     * @return array{id: int, label: string, code: ?int, publicId: ?string, contextLabel: ?string}
     */
    protected function normalizeEntityRow(array $row): array
    {
        $publicId = $row['publicId'] ?? null;
        if ($publicId instanceof Uuid) {
            $publicId = $publicId->toRfc4122();
        } elseif (!\is_string($publicId) || '' === $publicId) {
            $publicId = null;
        }

        $code = $row['code'] ?? null;
        $code = \is_numeric($code) ? (int) $code : null;

        $label = $row['name'];
        if (null !== $code && $code > 0) {
            $label .= ' ('.$code.')';
        }

        $context = $row['contextLabel'] ?? null;

        return [
            'id' => (int) $row['id'],
            'label' => $label,
            'code' => $code,
            'publicId' => $publicId,
            'contextLabel' => \is_string($context) && '' !== $context ? $context : null,
        ];
    }
}
