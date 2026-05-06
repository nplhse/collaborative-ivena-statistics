<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\StatisticsFilterFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsFilterFactoryTest extends KernelTestCase
{
    public function testInvalidCohortFallsBackToPublicForAnonymousUser(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromRequest(
            new Request(query: ['scope' => 'hospital_cohort', 'cohort' => 'unknown_cohort']),
            null,
        );

        self::assertSame('my_hospitals', $filter->scope->value);
        self::assertNull($filter->cohortType);
        self::assertNull($filter->notice);
    }

    public function testMissingCohortFallsBackToPublicForAnonymousUser(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsFilterFactory::class);

        $filter = $factory->createFromRequest(
            new Request(query: ['scope' => 'hospital_cohort']),
            null,
        );

        self::assertSame('my_hospitals', $filter->scope->value);
        self::assertNull($filter->cohortType);
        self::assertNull($filter->notice);
    }
}
