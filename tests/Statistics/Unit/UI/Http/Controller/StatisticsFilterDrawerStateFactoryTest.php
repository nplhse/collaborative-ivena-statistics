<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\UI\Http\Controller;

use App\Statistics\UI\Http\Controller\StatisticsFilterDrawerStateFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsFilterDrawerStateFactoryTest extends TestCase
{
    public function testCountsKnownAgeGroupAsActiveFilter(): void
    {
        $state = new StatisticsFilterDrawerStateFactory()->fromRequest(Request::create('/', 'GET', ['age_group' => 'adults']));

        self::assertSame('adults', $state['values']['age_group']);
        self::assertSame(1, $state['activeCount']);
    }

    public function testUnknownAgeGroupIsNoActiveFilter(): void
    {
        $state = new StatisticsFilterDrawerStateFactory()->fromRequest(Request::create('/', 'GET', ['age_group' => '0_18']));

        self::assertSame('', $state['values']['age_group']);
        self::assertSame(0, $state['activeCount']);
    }
}
