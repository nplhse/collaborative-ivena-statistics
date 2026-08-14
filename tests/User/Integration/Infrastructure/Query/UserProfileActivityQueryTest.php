<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Query;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Explore\ProfileActivityCursor;
use App\User\Application\Explore\ProfileActivityPage;
use App\User\Application\Explore\ProfileActivityType;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Query\UserProfileActivityQuery;
use Doctrine\ORM\EntityManagerInterface;

final class UserProfileActivityQueryTest extends DatabaseKernelTestCase
{
    public function testJoinedStaysLastAndCursorDoesNotSkipOrRepeat(): void
    {
        $user = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-04-17 10:00:00')]);
        $userId = $user->getId();
        self::assertNotNull($userId);

        $sameDay = new \DateTimeImmutable('2022-04-17 10:00:00');
        $this->record($user, UserActivityType::JOINED, $sameDay, UserActivityDeduplicationKey::joined($userId));
        $this->record(
            $user,
            UserActivityType::FIRST_IMPORT,
            $sameDay,
            UserActivityDeduplicationKey::importMilestone($userId, 1),
            ['milestone' => 1, 'hospitalName' => 'A', 'hospitalPublicId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'],
        );

        for ($i = 1; $i <= 25; ++$i) {
            $this->record(
                $user,
                UserActivityType::POST_PUBLISHED,
                new \DateTimeImmutable(sprintf('2026-01-%02d 12:00:00', $i)),
                UserActivityDeduplicationKey::postPublished($userId, $i),
                ['title' => 'Post '.$i, 'slug' => 'post-'.$i, 'postId' => $i],
            );
        }

        $query = self::getContainer()->get(UserProfileActivityQuery::class);
        $first = $query->getPage($user, null, ProfileActivityPage::PAGE_SIZE);
        self::assertCount(20, $first->items);
        self::assertTrue($first->hasMore());
        self::assertNotNull($first->nextCursor);
        self::assertSame(ProfileActivityType::POST_PUBLISHED, $first->items[0]->type);
        self::assertNotContains(ProfileActivityType::JOINED, array_map(static fn ($item) => $item->type, $first->items));

        $second = $query->getPage($user, $first->nextCursor, ProfileActivityPage::PAGE_SIZE);
        $allKeys = [
            ...array_map(static fn ($activity): string => $activity->type->value.':'.$activity->stableId, $first->items),
            ...array_map(static fn ($activity): string => $activity->type->value.':'.$activity->stableId, $second->items),
        ];

        self::assertCount(7, $second->items);
        self::assertCount(27, $allKeys);
        self::assertCount(27, array_values(array_unique($allKeys)));
        self::assertFalse($second->hasMore());
        self::assertSame(ProfileActivityType::FIRST_IMPORT, $second->items[array_key_last($second->items) - 1]->type);
        self::assertSame(ProfileActivityType::JOINED, $second->items[array_key_last($second->items)]->type);

        $legacy = base64_encode(json_encode([
            'v' => 1,
            'occurredAt' => '2026-01-25T12:00:00+00:00',
            'type' => 'post_published',
            'stableId' => ProfileActivityCursor::padId(25),
        ], JSON_THROW_ON_ERROR));
        $fromLegacy = $query->getPage($user, $legacy, ProfileActivityPage::PAGE_SIZE);
        self::assertCount(20, $fromLegacy->items);
        self::assertSame($first->items[0]->stableId, $fromLegacy->items[0]->stableId);
    }

    public function testHospitalAndContentMetadataAreMapped(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->record(
            $user,
            UserActivityType::HOSPITAL_ASSOCIATED,
            new \DateTimeImmutable('2026-03-01 09:00:00'),
            UserActivityDeduplicationKey::hospitalAssociated($userId, 12, 41),
            ['hospitalName' => 'Grant Klinik', 'hospitalPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
        );
        $this->record(
            $user,
            UserActivityType::COMMENT_CREATED,
            new \DateTimeImmutable('2026-03-02 09:00:00'),
            UserActivityDeduplicationKey::commentCreated($userId, 9),
            ['postTitle' => 'Artikel', 'postSlug' => 'artikel', 'excerpt' => 'Hi'],
        );
        $this->record(
            $user,
            UserActivityType::JOINED,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            UserActivityDeduplicationKey::joined($userId),
        );

        $page = self::getContainer()->get(UserProfileActivityQuery::class)->getPage($user, null);
        self::assertSame(ProfileActivityType::COMMENT_CREATED, $page->items[0]->type);
        self::assertSame('Artikel', $page->items[0]->postTitle);
        self::assertSame('Hi', $page->items[0]->excerpt);
        self::assertSame(ProfileActivityType::HOSPITAL_ASSOCIATED, $page->items[1]->type);
        self::assertSame('Grant Klinik', $page->items[1]->hospitalName);
        self::assertSame('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $page->items[1]->hospitalPublicId);
        self::assertSame(ProfileActivityType::JOINED, $page->items[2]->type);
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function record(
        User $user,
        UserActivityType $type,
        \DateTimeImmutable $occurredAt,
        string $deduplicationKey,
        array $metadata = [],
    ): void {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserActivity(
            user: $user,
            type: $type,
            occurredAt: $occurredAt,
            deduplicationKey: $deduplicationKey,
            metadata: $metadata,
        ));
        $entityManager->flush();
    }
}
