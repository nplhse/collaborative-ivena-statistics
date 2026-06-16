<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class StatisticsHospitalScopeLabelResolverTest extends DatabaseKernelTestCase
{
    public function testAdminSeesHospitalsLabel(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsHospitalScopeLabelResolver::class);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        self::assertSame('Hospitals', $resolver->groupLabel($admin));
    }

    public function testParticipantSeesMyHospitalsLabel(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(StatisticsHospitalScopeLabelResolver::class);
        $participant = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        self::assertSame('My hospitals', $resolver->groupLabel($participant));
    }
}
