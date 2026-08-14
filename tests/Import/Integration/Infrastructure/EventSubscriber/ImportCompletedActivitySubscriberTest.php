<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\EventSubscriber\ImportCompletedActivitySubscriber;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Application\Contract\UserImportActivityProviderInterface;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserActivityRepository;

final class ImportCompletedActivitySubscriberTest extends DatabaseKernelTestCase
{
    public function testFirstAndTenthSuccessfulImportWriteMilestones(): void
    {
        $user = UserFactory::createOne();
        $hospital = HospitalFactory::createOne(['name' => 'Milestone Klinik']);
        $userId = $user->getId();
        self::assertNotNull($userId);

        $subscriber = $this->subscriber();
        $first = ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2024-01-01 10:00:00'),
        ]);
        $subscriber->onImportCompleted(new ImportCompleted((int) $first->getId()));

        for ($rank = 2; $rank <= 10; ++$rank) {
            $import = ImportFactory::createOne([
                'createdBy' => $user,
                'hospital' => $hospital,
                'status' => 10 === $rank ? ImportStatus::PARTIAL : ImportStatus::COMPLETED,
                'createdAt' => new \DateTimeImmutable('2024-01-01 10:00:00')->modify('+'.($rank - 1).' days'),
            ]);
            $subscriber->onImportCompleted(new ImportCompleted((int) $import->getId()));
        }

        $eleventh = ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'status' => ImportStatus::COMPLETED,
            'createdAt' => new \DateTimeImmutable('2024-01-12 10:00:00'),
        ]);
        $subscriber->onImportCompleted(new ImportCompleted((int) $eleventh->getId()));

        $types = array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user], ['id' => 'ASC']),
        );

        self::assertSame([
            UserActivityType::FIRST_IMPORT,
            UserActivityType::IMPORT_MILESTONE,
        ], $types);
    }

    public function testFailedAndCancelledImportsDoNotWriteActivity(): void
    {
        $user = UserFactory::createOne();
        $hospital = HospitalFactory::createOne();
        $subscriber = $this->subscriber();
        foreach ([ImportStatus::FAILED, ImportStatus::CANCELLED] as $status) {
            $import = ImportFactory::createOne([
                'createdBy' => $user,
                'hospital' => $hospital,
                'status' => $status,
            ]);
            $subscriber->onImportCompleted(new ImportCompleted((int) $import->getId()));
        }

        self::assertSame(
            [],
            self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user]),
        );
    }

    private function subscriber(): ImportCompletedActivitySubscriber
    {
        return new ImportCompletedActivitySubscriber(
            self::getContainer()->get(ImportRepository::class),
            self::getContainer()->get(UserImportActivityProviderInterface::class),
            self::getContainer()->get(UserActivityRecorderInterface::class),
        );
    }
}
