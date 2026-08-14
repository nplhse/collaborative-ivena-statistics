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
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Activity\UserActivityBackfill;
use App\User\Infrastructure\Repository\UserActivityRepository;

final class UserActivityBackfillTest extends DatabaseKernelTestCase
{
    public function testApplyIsIdempotentAndSkipsLiveOverlap(): void
    {
        $user = UserFactory::createOne(['createdAt' => new \DateTimeImmutable('2022-01-01 08:00:00')]);
        $hospital = HospitalFactory::createOne(['name' => 'Backfill Klinik', 'owner' => $user]);
        $userId = $user->getId();
        self::assertNotNull($userId);

        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2022-04-17 10:00:00'),
        ]);
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

        self::getContainer()->get(UserActivityRecorderInterface::class)->record(new UserActivityWrite(
            userId: $userId,
            type: UserActivityType::JOINED,
            occurredAt: $user->getCreatedAt(),
            deduplicationKey: UserActivityDeduplicationKey::joined($userId),
        ));

        $backfill = self::getContainer()->get(UserActivityBackfill::class);
        $dryRun = $backfill->run(false);
        self::assertGreaterThan(0, $dryRun->recordedTotal());
        self::assertSame(1, $dryRun->skippedExisting);

        $first = $backfill->run(true);
        self::assertGreaterThan(0, $first->recordedTotal());
        self::assertSame(1, $first->skippedExisting);

        $second = $backfill->run(true);
        self::assertSame(0, $second->recordedTotal());
        self::assertGreaterThan(0, $second->skippedExisting);

        $types = array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user]),
        );
        self::assertContains(UserActivityType::JOINED, $types);
        self::assertContains(UserActivityType::FIRST_IMPORT, $types);
        self::assertContains(UserActivityType::POST_PUBLISHED, $types);
        self::assertContains(UserActivityType::COMMENT_CREATED, $types);
        self::assertContains(UserActivityType::HOSPITAL_OWNER_GRANTED, $types);
        self::assertNotContains(UserActivityType::IMPORT_MILESTONE, $types);
        $postTitles = [];
        foreach (self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user]) as $activity) {
            if (UserActivityType::POST_PUBLISHED === $activity->getType()) {
                $postTitles[] = $activity->getMetadata()['title'] ?? null;
            }
        }
        self::assertContains('Published', $postTitles);
        self::assertContains('Commented', $postTitles);
        self::assertNotContains('Draft', $postTitles);
    }

    public function testOwnerHistoryUsesImporterSegmentsAndIgnoresGrantUserImports(): void
    {
        $ownerA = UserFactory::createOne();
        $ownerB = UserFactory::createOne();
        $grantUser = UserFactory::createOne();
        $hospital = HospitalFactory::createOne(['name' => 'Switch Klinik', 'owner' => $ownerB]);
        HospitalAccessGrantFactory::createOne([
            'user' => $grantUser,
            'hospital' => $hospital,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
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

        $ownerWithoutImport = UserFactory::createOne();
        HospitalFactory::createOne(['name' => 'Silent Klinik', 'owner' => $ownerWithoutImport]);

        $report = self::getContainer()->get(UserActivityBackfill::class)->run(true);
        self::assertGreaterThanOrEqual(1, $report->unableToReconstruct);

        $repository = self::getContainer()->get(UserActivityRepository::class);
        $aTypes = array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            $repository->findBy(['user' => $ownerA]),
        );
        $bTypes = array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            $repository->findBy(['user' => $ownerB]),
        );
        $grantTypes = array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            $repository->findBy(['user' => $grantUser]),
        );
        $silentTypes = array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            $repository->findBy(['user' => $ownerWithoutImport]),
        );

        self::assertContains(UserActivityType::HOSPITAL_OWNER_GRANTED, $aTypes);
        self::assertContains(UserActivityType::HOSPITAL_OWNER_REVOKED, $aTypes);
        self::assertContains(UserActivityType::HOSPITAL_OWNER_GRANTED, $bTypes);
        self::assertNotContains(UserActivityType::HOSPITAL_OWNER_GRANTED, $grantTypes);
        self::assertNotContains(UserActivityType::HOSPITAL_OWNER_REVOKED, $grantTypes);
        self::assertContains(UserActivityType::HOSPITAL_ASSOCIATED, $grantTypes);
        self::assertNotContains(UserActivityType::HOSPITAL_OWNER_GRANTED, $silentTypes);
    }
}
