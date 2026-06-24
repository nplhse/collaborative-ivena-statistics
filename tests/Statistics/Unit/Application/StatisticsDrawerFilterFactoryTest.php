<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\Application\StatisticsDrawerFilterFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsDrawerFilterFactoryTest extends TestCase
{
    private StatisticsDrawerFilterFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new StatisticsDrawerFilterFactory();
    }

    public function testEmptyQueryProducesInactiveFilter(): void
    {
        $filter = $this->factory->fromQuery($this->queryBag([]));

        self::assertFalse($filter->isActive());
        self::assertNull($filter->gender);
        self::assertNull($filter->urgency);
        self::assertNull($filter->requiresResus);
    }

    public function testParsesScalarFilters(): void
    {
        $filter = $this->factory->fromQuery($this->queryBag([
            'gender' => '2',
            'urgency' => '1',
            'age_group' => '30_39',
            'requiresResus' => '1',
            'department' => '12',
            'speciality' => '34',
            'infection' => '7',
        ]));

        self::assertTrue($filter->isActive());
        self::assertSame(2, $filter->gender);
        self::assertSame(1, $filter->urgency);
        self::assertSame('30_39', $filter->ageGroup);
        self::assertSame(12, $filter->department);
        self::assertSame(34, $filter->speciality);
        self::assertSame(7, $filter->infection);
    }

    public function testParsesBooleanCheckboxFilters(): void
    {
        $filter = $this->factory->fromQuery($this->queryBag([
            'requiresResus' => '1',
            'isCPR' => '0',
            'isInfectious' => 'true',
        ]));

        self::assertTrue($filter->requiresResus);
        self::assertFalse($filter->isCpr);
        self::assertTrue($filter->isInfectious);
    }

    public function testIgnoresInvalidValues(): void
    {
        $filter = $this->factory->fromQuery($this->queryBag([
            'gender' => '0',
            'urgency' => '9',
            'age_group' => '   ',
        ]));

        self::assertFalse($filter->isActive());
        self::assertNull($filter->gender);
        self::assertNull($filter->urgency);
        self::assertNull($filter->ageGroup);
    }

    public function testParsesAggregateAgeGroups(): void
    {
        $filter = $this->factory->fromQuery($this->queryBag([
            'age_group' => StatisticsAgeGroupFilter::OVER_80,
        ]));

        self::assertTrue($filter->isActive());
        self::assertSame(StatisticsAgeGroupFilter::OVER_80, $filter->ageGroup);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return InputBag<string>
     */
    private function queryBag(array $parameters): InputBag
    {
        return (new Request($parameters))->query;
    }
}
