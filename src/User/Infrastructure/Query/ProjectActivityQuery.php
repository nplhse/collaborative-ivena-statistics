<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use App\User\Application\Explore\ProjectActivityCursor;
use App\User\Application\Explore\ProjectActivityFilters;
use App\User\Application\Explore\ProjectActivityPage;
use App\User\Application\Explore\ProjectActivityQueryInterface;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/** @psalm-suppress UnusedClass Wired as ProjectActivityQueryInterface. */
final readonly class ProjectActivityQuery implements ProjectActivityQueryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function getPage(
        ?string $cursor,
        int $limit = ProjectActivityPage::PAGE_SIZE,
        ?ProjectActivityFilters $filters = null,
    ): ProjectActivityPage {
        if ($limit < 1) {
            return new ProjectActivityPage([], null, max(1, $limit));
        }

        $decodedCursor = ProjectActivityCursor::tryDecode($cursor);
        $rows = $this->fetchPage($decodedCursor, $limit + 1, $filters);

        $hasMore = \count($rows) > $limit;
        $visible = array_slice($rows, 0, $limit);
        $items = array_map($this->toActivity(...), $visible);

        $nextCursor = null;
        if ($hasMore && [] !== $items) {
            $nextCursor = ProjectActivityCursor::fromActivity($items[array_key_last($items)])->encode();
        }

        return new ProjectActivityPage($items, $nextCursor, $limit);
    }

    /**
     * @return list<UserActivity>
     */
    private function fetchPage(?ProjectActivityCursor $cursor, int $limit, ?ProjectActivityFilters $filters): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a', 'u')
            ->from(UserActivity::class, 'a')
            ->innerJoin('a.user', 'u')
            ->andWhere('u.isEnabled = true')
            ->andWhere('a.type IN (:types)')
            ->setParameter('types', $this->resolvedTypes($filters))
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit);

        $this->applyFilters($qb, $filters);

        if ($cursor instanceof ProjectActivityCursor) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->lt('a.occurredAt', ':cursorAt'),
                    $qb->expr()->andX(
                        $qb->expr()->eq('a.occurredAt', ':cursorAt'),
                        $qb->expr()->lt('a.id', ':cursorId'),
                    ),
                ),
            )
                ->setParameter('cursorAt', $cursor->occurredAt, Types::DATETIME_IMMUTABLE)
                ->setParameter('cursorId', $cursor->id);
        }

        /** @var list<UserActivity> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @return list<UserActivityType>
     */
    private function resolvedTypes(?ProjectActivityFilters $filters): array
    {
        $types = ProjectActivityPage::feedTypes();
        if (!$filters instanceof ProjectActivityFilters || !$filters->type instanceof UserActivityType) {
            return $types;
        }

        if (!\in_array($filters->type, $types, true)) {
            return $types;
        }

        return [$filters->type];
    }

    private function applyFilters(QueryBuilder $qb, ?ProjectActivityFilters $filters): void
    {
        if (!$filters instanceof ProjectActivityFilters) {
            return;
        }

        if ($filters->from instanceof \DateTimeImmutable) {
            $qb->andWhere('a.occurredAt >= :from')
                ->setParameter('from', $filters->from, Types::DATETIME_IMMUTABLE);
        }

        if ($filters->untilExclusive instanceof \DateTimeImmutable) {
            $qb->andWhere('a.occurredAt < :untilExclusive')
                ->setParameter('untilExclusive', $filters->untilExclusive, Types::DATETIME_IMMUTABLE);
        }

        $username = null !== $filters->username ? trim($filters->username) : '';
        if ('' !== $username) {
            $qb->andWhere('LOWER(u.username) = :username')
                ->setParameter('username', mb_strtolower($username));
        }

        $search = null !== $filters->search ? trim($filters->search) : '';
        if ('' !== $search) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(u.username)', ':search'),
                    $qb->expr()->like("LOWER(COALESCE(JSON_GET_TEXT(a.metadata, 'title'), ''))", ':search'),
                    $qb->expr()->like("LOWER(COALESCE(JSON_GET_TEXT(a.metadata, 'postTitle'), ''))", ':search'),
                    $qb->expr()->like("LOWER(COALESCE(JSON_GET_TEXT(a.metadata, 'excerpt'), ''))", ':search'),
                    $qb->expr()->like("LOWER(COALESCE(JSON_GET_TEXT(a.metadata, 'hospitalName'), ''))", ':search'),
                ),
            )->setParameter('search', '%'.mb_strtolower($search).'%');
        }
    }

    private function toActivity(UserActivity $activity): ProjectActivity
    {
        $id = $activity->getId();
        if (null === $id) {
            throw new \LogicException('UserActivity must be persisted.');
        }

        $user = $activity->getUser();
        $metadata = $activity->getMetadata();
        $type = ProfileActivityType::from($activity->getType()->value);
        $milestone = $metadata['milestone'] ?? null;
        $publicId = $user->getPublicId();

        return new ProjectActivity(
            occurredAt: $activity->getOccurredAt(),
            type: $type,
            stableId: ProjectActivityCursor::padId($id),
            actorUsername: $user->getUsername() ?? '',
            actorPublicId: $publicId instanceof Uuid ? $publicId->toRfc4122() : null,
            hospitalName: $this->stringMetadata($metadata, 'hospitalName'),
            hospitalPublicId: $this->stringMetadata($metadata, 'hospitalPublicId'),
            milestone: \is_int($milestone) ? $milestone : (is_numeric($milestone) ? (int) $milestone : null),
            postTitle: $this->stringMetadata($metadata, 'title') ?? $this->stringMetadata($metadata, 'postTitle'),
            postSlug: $this->stringMetadata($metadata, 'slug') ?? $this->stringMetadata($metadata, 'postSlug'),
            excerpt: $this->stringMetadata($metadata, 'excerpt'),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function stringMetadata(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
