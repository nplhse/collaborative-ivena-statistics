<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\User\Application\Explore\ProfileActivityType;
use App\User\Application\Explore\ProjectActivity;
use App\User\Application\Explore\ProjectActivityCursor;
use App\User\Application\Explore\ProjectActivityPage;
use App\User\Application\Explore\ProjectActivityQueryInterface;
use App\User\Domain\Entity\UserActivity;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** @psalm-suppress UnusedClass Wired as ProjectActivityQueryInterface. */
final readonly class ProjectActivityQuery implements ProjectActivityQueryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function getPage(?string $cursor, int $limit = ProjectActivityPage::PAGE_SIZE): ProjectActivityPage
    {
        if ($limit < 1) {
            return new ProjectActivityPage([], null, max(1, $limit));
        }

        $decodedCursor = ProjectActivityCursor::tryDecode($cursor);
        $rows = $this->fetchPage($decodedCursor, $limit + 1);

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
    private function fetchPage(?ProjectActivityCursor $cursor, int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a', 'u')
            ->from(UserActivity::class, 'a')
            ->innerJoin('a.user', 'u')
            ->andWhere('u.isEnabled = true')
            ->andWhere('a.type IN (:types)')
            ->setParameter('types', ProjectActivityPage::feedTypes())
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit);

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
