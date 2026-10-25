<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Infrastructure\Notification;

use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Notification\Application\Contract\NotificationHospitalAccessInterface;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class HospitalPermissionNotificationAccessTest extends DatabaseKernelTestCase
{
    public function testKeepsHospitalsTheViewerOwnsOrMayView(): void
    {
        $viewer = UserFactory::createOne(['roles' => ['ROLE_PARTICIPANT']]);
        $owned = HospitalFactory::createOne(['owner' => $viewer]);
        $granted = HospitalFactory::createOne(['owner' => UserFactory::new()]);
        HospitalAccessGrantFactory::createOne(['hospital' => $granted, 'user' => $viewer]);
        // Every grant implies View (HospitalPermissionMask::normalize), so a grant of another user is the counter case.
        $grantedToOther = HospitalFactory::createOne(['owner' => UserFactory::new()]);
        HospitalAccessGrantFactory::createOne(['hospital' => $grantedToOther, 'user' => UserFactory::new(['roles' => ['ROLE_PARTICIPANT']])]);
        $foreign = HospitalFactory::createOne(['owner' => UserFactory::new()]);

        $accessible = $this->access()->filterAccessible($viewer, [
            (int) $owned->getId(),
            (int) $granted->getId(),
            (int) $grantedToOther->getId(),
            (int) $foreign->getId(),
            (int) $owned->getId(),
        ]);
        sort($accessible);

        $expected = [(int) $owned->getId(), (int) $granted->getId()];
        sort($expected);
        self::assertSame($expected, $accessible);
    }

    public function testAdminsSeeEveryHospitalAndEmptyInputShortCircuits(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);

        self::assertSame([5, 7], $this->access()->filterAccessible($admin, [5, 7, 5]));
        self::assertSame([], $this->access()->filterAccessible($admin, []));
    }

    private function access(): NotificationHospitalAccessInterface
    {
        return self::getContainer()->get(NotificationHospitalAccessInterface::class);
    }
}
