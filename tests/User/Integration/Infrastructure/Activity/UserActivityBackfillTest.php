<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Activity;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Activity\UserActivityWrite;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Activity\UserActivityBackfill;
use App\User\Infrastructure\Repository\UserActivityRepository;

/**
 * `UserActivityBackfill` derives the activity of a user from existing rows: joined, first import, published posts,
 * comments, hospital access and ownership. Every test checks one derived type on a small fixture.
 */
final class UserActivityBackfillTest extends DatabaseKernelTestCase
{
    public function testJoinedIsSkippedWhenItWasAlreadyRecordedLive(): void
    {
        $user = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00')]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        self::getContainer()->get(UserActivityRecorderInterface::class)->record(new UserActivityWrite(
            userId: $userId,
            type: UserActivityType::JOINED,
            occurredAt: $user->getCreatedAt(),
            deduplicationKey: UserActivityDeduplicationKey::joined($userId),
        ));

        $report = $this->backfill()->run(true);

        self::assertSame([0, 1], [$report->joined, $report->skippedExisting]);
        self::assertSame(['joined 2022-01-01 08:00'], $this->activities($user));
    }

    public function testFirstImportIsRecordedAtTheTimeOfTheImport(): void
    {
        $user = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00')]);
        $hospital = HospitalFactory::createOne(['name' => 'Backfill Klinik', 'owner' => $user, 'createdBy' => $user]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-04-17 10:00:00'),
        ]);

        $report = $this->backfill()->run(true);

        // One import is the first milestone only; the next one is the tenth import.
        self::assertSame(1, $report->milestones);
        self::assertSame([
            'joined 2022-01-01 08:00',
            'first_import 2022-04-17 10:00',
            'hospital_owner_granted 2022-04-17 10:00',
        ], $this->activities($user));
    }

    public function testPublishedPostIsRecordedButNotADraft(): void
    {
        $user = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00')]);
        PostFactory::createOne([
            'createdBy' => $user,
            'title' => 'Published',
            'slug' => 'published-backfill',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('2026-07-12 10:00:00'),
        ]);
        PostFactory::createOne([
            'createdBy' => $user,
            'title' => 'Draft',
            'slug' => 'draft-backfill',
            'status' => PostStatus::DRAFT,
            'publishedAt' => null,
        ]);

        $report = $this->backfill()->run(true);

        self::assertSame(1, $report->posts);
        self::assertSame([['title' => 'Published', 'occurredAt' => '2026-07-12 10:00']], $this->postActivities($user));
    }

    public function testCommentOnAPublishedPostIsRecorded(): void
    {
        $user = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00')]);
        PostCommentFactory::createOne([
            'author' => $user,
            'createdBy' => $user,
            'post' => PostFactory::createOne([
                'createdBy' => $user,
                'title' => 'Commented',
                'slug' => 'commented-backfill',
                'status' => PostStatus::PUBLISHED,
                'publishedAt' => new \DateTimeImmutable('2026-07-01 10:00:00'),
            ]),
            'content' => 'Hello world',
            'createdAt' => new \DateTimeImmutable('2026-07-18 09:00:00'),
        ]);

        $report = $this->backfill()->run(true);

        self::assertSame([1, 1], [$report->posts, $report->comments]);
        self::assertSame([
            'joined 2022-01-01 08:00',
            'post_published 2026-07-01 10:00',
            'comment_created 2026-07-18 09:00',
        ], $this->activities($user));
    }

    public function testOwnershipFollowsTheImporterSegmentsOfTheHospital(): void
    {
        [$ownerA, $ownerB] = $this->hospitalWithChangingImporters();

        $this->backfill()->run(true);

        // A imported first and lost the hospital when B imported; the import of the grant user in between counts for
        // neither.
        self::assertSame([
            'hospital_owner_granted 2024-01-01 10:00',
            'hospital_owner_revoked 2024-03-01 10:00',
        ], $this->activities($ownerA, UserActivityType::HOSPITAL_OWNER_GRANTED, UserActivityType::HOSPITAL_OWNER_REVOKED));
        self::assertSame(
            ['hospital_owner_granted 2024-03-01 10:00'],
            $this->activities($ownerB, UserActivityType::HOSPITAL_OWNER_GRANTED, UserActivityType::HOSPITAL_OWNER_REVOKED),
        );
    }

    public function testUserWithAnAccessGrantIsOnlyAssociatedWithTheHospital(): void
    {
        $grantUser = $this->hospitalWithChangingImporters()[2];

        $this->backfill()->run(true);

        // Its own import makes it neither owner nor former owner.
        self::assertSame(['hospital_associated'], $this->types(
            $grantUser,
            UserActivityType::HOSPITAL_ASSOCIATED,
            UserActivityType::HOSPITAL_OWNER_GRANTED,
            UserActivityType::HOSPITAL_OWNER_REVOKED,
        ));
    }

    public function testOwnerWithoutImportCannotBeReconstructed(): void
    {
        $owner = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00')]);
        HospitalFactory::createOne(['name' => 'Silent Klinik', 'owner' => $owner, 'createdBy' => $owner]);

        $report = $this->backfill()->run(true);

        self::assertSame(1, $report->unableToReconstruct);
        self::assertSame(['joined 2022-01-01 08:00'], $this->activities($owner));
    }

    public function testDryRunCountsButWritesNothing(): void
    {
        $user = $this->ownerWithOneImport();

        $report = $this->backfill()->run(false);

        // joined, first import, owner granted
        self::assertSame(3, $report->recordedTotal());
        self::assertSame([], $this->activities($user));
    }

    public function testSecondRunRecordsNothing(): void
    {
        $this->ownerWithOneImport();
        $this->backfill()->run(true);

        $second = $this->backfill()->run(true);

        // The three rows of the first run are found by their deduplication keys.
        self::assertSame([0, 3], [$second->recordedTotal(), $second->skippedExisting]);
    }

    private function ownerWithOneImport(): User
    {
        $user = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00')]);
        $hospital = HospitalFactory::createOne(['name' => 'Backfill Klinik', 'owner' => $user, 'createdBy' => $user]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-04-17 10:00:00'),
        ]);

        return $user;
    }

    /**
     * Imports by owner A (January), by a user with an access grant (February) and by owner B (March); B owns the
     * hospital now.
     *
     * @return array{User, User, User} owner A, owner B, grant user
     */
    private function hospitalWithChangingImporters(): array
    {
        $ownerA = UserFactory::createOne();
        $ownerB = UserFactory::createOne();
        $grantUser = UserFactory::createOne();
        $hospital = HospitalFactory::createOne(['name' => 'Switch Klinik', 'owner' => $ownerB, 'createdBy' => $ownerB]);
        HospitalAccessGrantFactory::createOne([
            'user' => $grantUser,
            'hospital' => $hospital,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $ownerB,
        ]);
        ImportFactory::createOne([
            'createdBy' => $ownerA,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2024-01-01 10:00:00'),
        ]);
        ImportFactory::createOne([
            'createdBy' => $grantUser,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2024-02-01 10:00:00'),
        ]);
        ImportFactory::createOne([
            'createdBy' => $ownerB,
            'hospital' => $hospital,
            'status' => ImportStatus::PARTIAL,
            'createdAt' => new \DateTimeImmutable('2024-03-01 10:00:00'),
        ]);

        return [$ownerA, $ownerB, $grantUser];
    }

    private function backfill(): UserActivityBackfill
    {
        return self::getContainer()->get(UserActivityBackfill::class);
    }

    /**
     * @return list<UserActivity> in the order they happened
     */
    private function rows(User $user): array
    {
        /** @var list<UserActivity> $rows */
        $rows = self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user], ['occurredAt' => 'ASC', 'id' => 'ASC']);

        return $rows;
    }

    /**
     * @return list<UserActivity> of the given types, all without types
     */
    private function rowsOfTypes(User $user, UserActivityType ...$types): array
    {
        return array_values(array_filter(
            $this->rows($user),
            static fn (UserActivity $activity): bool => [] === $types || \in_array($activity->getType(), $types, true),
        ));
    }

    /**
     * @return list<string> "type Y-m-d H:i" per row
     */
    private function activities(User $user, UserActivityType ...$types): array
    {
        return array_map(
            static fn (UserActivity $activity): string => $activity->getType()->value.' '.$activity->getOccurredAt()->format('Y-m-d H:i'),
            $this->rowsOfTypes($user, ...$types),
        );
    }

    /**
     * @return list<string>
     */
    private function types(User $user, UserActivityType ...$types): array
    {
        return array_map(static fn (UserActivity $activity): string => $activity->getType()->value, $this->rowsOfTypes($user, ...$types));
    }

    /**
     * @return list<array{title: mixed, occurredAt: string}>
     */
    private function postActivities(User $user): array
    {
        return array_map(
            static fn (UserActivity $activity): array => ['title' => $activity->getMetadata()['title'] ?? null, 'occurredAt' => $activity->getOccurredAt()->format('Y-m-d H:i')],
            $this->rowsOfTypes($user, UserActivityType::POST_PUBLISHED),
        );
    }
}
