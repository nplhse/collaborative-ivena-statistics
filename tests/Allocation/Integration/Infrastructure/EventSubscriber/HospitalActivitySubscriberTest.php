<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Application\Event\HospitalOwnershipGranted;
use App\Allocation\Application\Event\HospitalOwnershipRevoked;
use App\Allocation\Application\Event\UserAssociatedWithHospital;
use App\Allocation\Application\Event\UserDisassociatedFromHospital;
use App\Allocation\Infrastructure\EventSubscriber\HospitalActivitySubscriber;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserActivityRepository;

final class HospitalActivitySubscriberTest extends DatabaseKernelTestCase
{
    public function testGrantCreateAndDeleteWriteAssociationActivities(): void
    {
        $user = UserFactory::createOne();
        $hospital = HospitalFactory::createOne(['name' => 'Grant Klinik']);
        $userId = $user->getId();
        $hospitalId = $hospital->getId();
        self::assertNotNull($userId);
        self::assertNotNull($hospitalId);

        $subscriber = new HospitalActivitySubscriber(
            self::getContainer()->get(UserActivityRecorderInterface::class),
        );
        $occurredAt = new \DateTimeImmutable('2026-03-01 09:00:00');
        $subscriber->onAssociated(new UserAssociatedWithHospital(
            userId: $userId,
            hospitalId: $hospitalId,
            grantId: 41,
            hospitalPublicId: $hospital->getPublicIdString(),
            hospitalName: 'Grant Klinik',
            occurredAt: $occurredAt,
        ));
        $subscriber->onDisassociated(new UserDisassociatedFromHospital(
            userId: $userId,
            hospitalId: $hospitalId,
            grantId: 41,
            hospitalPublicId: $hospital->getPublicIdString(),
            hospitalName: 'Grant Klinik',
            occurredAt: new \DateTimeImmutable('2026-03-02 09:00:00'),
        ));

        $types = array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user], ['id' => 'ASC']),
        );

        self::assertSame([
            UserActivityType::HOSPITAL_ASSOCIATED,
            UserActivityType::HOSPITAL_DISASSOCIATED,
        ], $types);
    }

    public function testOwnershipGrantedAndRevokedWriteOncePerUserAndHospital(): void
    {
        $user = UserFactory::createOne();
        $hospital = HospitalFactory::createOne(['name' => 'Owner Klinik']);
        $userId = $user->getId();
        $hospitalId = $hospital->getId();
        self::assertNotNull($userId);
        self::assertNotNull($hospitalId);

        $subscriber = new HospitalActivitySubscriber(
            self::getContainer()->get(UserActivityRecorderInterface::class),
        );
        $granted = new HospitalOwnershipGranted(
            userId: $userId,
            hospitalId: $hospitalId,
            hospitalPublicId: $hospital->getPublicIdString(),
            hospitalName: 'Owner Klinik',
            occurredAt: new \DateTimeImmutable('2026-04-01 09:00:00'),
        );
        $revoked = new HospitalOwnershipRevoked(
            userId: $userId,
            hospitalId: $hospitalId,
            hospitalPublicId: $hospital->getPublicIdString(),
            hospitalName: 'Owner Klinik',
            occurredAt: new \DateTimeImmutable('2026-05-01 09:00:00'),
        );
        $subscriber->onOwnershipGranted($granted);
        $subscriber->onOwnershipGranted($granted);
        $subscriber->onOwnershipRevoked($revoked);
        $subscriber->onOwnershipRevoked($revoked);

        $types = array_map(
            static fn ($activity): UserActivityType => $activity->getType(),
            self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user], ['id' => 'ASC']),
        );

        self::assertSame([
            UserActivityType::HOSPITAL_OWNER_GRANTED,
            UserActivityType::HOSPITAL_OWNER_REVOKED,
        ], $types);
    }
}
