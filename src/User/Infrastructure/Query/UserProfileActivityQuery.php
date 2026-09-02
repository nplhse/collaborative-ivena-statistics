<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\User\Application\Explore\ProfileActivity;
use App\User\Application\Explore\ProfileActivityCursor;
use App\User\Application\Explore\ProfileActivityPage;
use App\User\Application\Explore\ProfileActivityType;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class UserProfileActivityQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getPage(User $user, ?string $cursor, int $limit = ProfileActivityPage::PAGE_SIZE): ProfileActivityPage
    {
        $id = $user->getId();
        if (null === $id) {
            throw new \LogicException('User must be persisted.');
        }

        if ($limit < 1) {
            return new ProfileActivityPage([], null, max(1, $limit));
        }

        $decodedCursor = ProfileActivityCursor::tryDecode($cursor);
        $rows = $this->fetchPage($id, $decodedCursor, $limit + 1);

        $hasMore = \count($rows) > $limit;
        $visible = array_slice($rows, 0, $limit);

        $items = array_map($this->toActivity(...), $visible);

        $nextCursor = null;
        if ($hasMore && [] !== $items) {
            $nextCursor = ProfileActivityCursor::fromActivity($items[array_key_last($items)])->encode();
        }

        return new ProfileActivityPage($items, $nextCursor, $limit);
    }

    /**
     * @return list<UserActivity>
     */
    private function fetchPage(int $userId, ?ProfileActivityCursor $cursor, int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->addSelect('CASE WHEN a.type = :joinedType THEN 1 ELSE 0 END AS HIDDEN joinedRank')
            ->from(UserActivity::class, 'a')
            ->andWhere('IDENTITY(a.user) = :userId')
            ->setParameter('userId', $userId)
            ->setParameter('joinedType', UserActivityType::JOINED)
            ->orderBy('joinedRank', 'ASC');

        $this->excludeUnpublishedPostActivities($qb);

        $qb->addOrderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit);

        if ($cursor instanceof ProfileActivityCursor) {
            if ($cursor->joined) {
                $qb->andWhere('a.type = :joinedType')
                    ->andWhere(
                        $qb->expr()->orX(
                            $qb->expr()->lt('a.occurredAt', ':cursorAt'),
                            $qb->expr()->andX(
                                $qb->expr()->eq('a.occurredAt', ':cursorAt'),
                                $qb->expr()->lt('a.id', ':cursorId'),
                            ),
                        ),
                    );
            } else {
                $qb->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->eq('a.type', ':joinedType'),
                        $qb->expr()->andX(
                            $qb->expr()->neq('a.type', ':joinedType'),
                            $qb->expr()->orX(
                                $qb->expr()->lt('a.occurredAt', ':cursorAt'),
                                $qb->expr()->andX(
                                    $qb->expr()->eq('a.occurredAt', ':cursorAt'),
                                    $qb->expr()->lt('a.id', ':cursorId'),
                                ),
                            ),
                        ),
                    ),
                );
            }

            $qb->setParameter('cursorAt', $cursor->occurredAt, Types::DATETIME_IMMUTABLE)
                ->setParameter('cursorId', $cursor->id);
        }

        /** @var list<UserActivity> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    private function excludeUnpublishedPostActivities(QueryBuilder $qb): void
    {
        $qb->andWhere('NOT (a.type = :postPublished AND a.occurredAt > :now)')
            ->setParameter('postPublished', UserActivityType::POST_PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable('now'), Types::DATETIME_IMMUTABLE);
    }

    private function toActivity(UserActivity $activity): ProfileActivity
    {
        $id = $activity->getId();
        if (null === $id) {
            throw new \LogicException('UserActivity must be persisted.');
        }

        $metadata = $activity->getMetadata();
        $type = ProfileActivityType::from($activity->getType()->value);
        $milestone = $metadata['milestone'] ?? null;

        return new ProfileActivity(
            occurredAt: $activity->getOccurredAt(),
            type: $type,
            stableId: ProfileActivityCursor::padId($id),
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
