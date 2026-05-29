<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\StatisticsFilterFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StatisticsFilterFactoryAccessTest extends KernelTestCase
{
    private StatisticsFilterFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(StatisticsFilterFactory::class);
    }

    public function testInvalidCohortFallsBackToPublicForAnonymousUser(): void
    {
        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital_cohort',
                '',
                'unknown_cohort',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
    }

    public function testExplicitMyHospitalsWithoutAccessRedirectsToPublic(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'my_hospitals',
                '',
                '',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertTrue($filter->requiresPublicRedirect);
    }

    public function testParticipantWithHospitalCanUseHospitalScope(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $user]);

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                (string) $hospital->getId(),
                '',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('hospital', $filter->scope->value);
        self::assertSame($hospital->getId(), $filter->hospitalId);
    }

    public function testAnonymousMyHospitalsScopeBecomesPublic(): void
    {
        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'my_hospitals',
                '',
                '',
                '',
                '',
                'all',
                null,
                null,
                null,
                false,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
    }

    public function testAnonymousHospitalScopeBecomesPublic(): void
    {
        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                '99',
                '',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->hospitalId);
    }

    public function testHospitalScopeWithoutIdFallsBackToMyHospitalsForParticipant(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                '',
                '',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('my_hospitals', $filter->scope->value);
    }

    public function testUnauthorizedHospitalFallsBackToMyHospitalsWhenParticipantHasOwnedHospitals(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $otherOwner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);
        $foreign = HospitalFactory::createOne(['owner' => $otherOwner]);
        self::assertNotNull($foreign->getId());

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                (string) $foreign->getId(),
                '',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('my_hospitals', $filter->scope->value);
        self::assertNull($filter->hospitalId);
    }

    public function testUnauthorizedHospitalRedirectsToPublicWithoutMyHospitalsAccess(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $otherOwner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $foreign = HospitalFactory::createOne(['owner' => $otherOwner]);
        self::assertNotNull($foreign->getId());

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                (string) $foreign->getId(),
                '',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertTrue($filter->requiresPublicRedirect);
    }

    public function testInvalidCohortFallsBackToMyHospitalsForParticipant(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital_cohort',
                '',
                'unknown_cohort',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('my_hospitals', $filter->scope->value);
    }

    public function testUnknownScopeTokenFallsBackToPublic(): void
    {
        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'not_a_real_scope',
                '',
                '',
                '',
                '',
                'all',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
    }
}
