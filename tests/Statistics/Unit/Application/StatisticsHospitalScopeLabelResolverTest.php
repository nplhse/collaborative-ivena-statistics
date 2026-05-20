<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StatisticsHospitalScopeLabelResolverTest extends KernelTestCase
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
