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

final class StatisticsFilterFactoryTest extends KernelTestCase
{
    public function testInvalidCohortFallsBackToPublicForAnonymousUser(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'hospital_cohort',
                '',
                'unknown_cohort',
                '',
                '',
                'all',
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->cohortType);
        self::assertNull($filter->notice);
    }

    public function testMissingCohortFallsBackToPublicForAnonymousUser(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'hospital_cohort',
                '',
                '',
                '',
                '',
                'all',
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->cohortType);
        self::assertNull($filter->notice);
    }

    public function testSupportsColonScopedHospitalSyntax(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $user]);
        $hospitalId = $hospital->getId();
        self::assertNotNull($hospitalId);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'hospital:'.$hospitalId,
                '',
                '',
                '',
                '',
                'all',
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('hospital', $filter->scope->value);
        self::assertSame($hospitalId, $filter->hospitalId);
    }

    public function testInvalidStateScopeFallsBackToPublic(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'state:999999',
                '',
                '',
                '',
                '',
                'all',
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->stateId);
    }

    public function testInvalidDispatchAreaScopeFallsBackToPublic(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromInput(
            new StatisticsFilterInput(
                'dispatch_area:999999',
                '',
                '',
                '',
                '',
                'all',
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->dispatchAreaId);
    }
}
