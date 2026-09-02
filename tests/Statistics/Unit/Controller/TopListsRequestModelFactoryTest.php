<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\TopList\TopListLimitPolicy;
use App\Statistics\Application\TopList\TopListPageSizePolicy;
use App\Statistics\UI\Http\Controller\TopListsRequestModelFactory;
use PHPUnit\Framework\TestCase;

final class TopListsRequestModelFactoryTest extends TestCase
{
    public function testParsesReportAndLimitFromQuery(): void
    {
        $factory = $this->factory();

        $model = $factory->fromQuery([
            'limit' => '10',
        ], 'top_diagnoses');

        self::assertSame('top_diagnoses', $model->topListKey);
        self::assertSame(10, $model->limit->queryValue());
        self::assertSame(1, $model->page);
        self::assertFalse($model->compare);
        self::assertSame(25, $model->pageSize);
    }

    public function testFallsBackToDefaultLimit(): void
    {
        $factory = $this->factory();

        self::assertSame(25, $factory->fromQuery(['limit' => 'invalid'], 'top_diagnoses')->limit->queryValue());
        self::assertSame(25, $factory->fromQuery([], 'top_diagnoses')->limit->queryValue());
    }

    public function testParsesAllLimitCompareAndPage(): void
    {
        $factory = $this->factory();

        $model = $factory->fromQuery([
            'limit' => 'all',
            'page' => '3',
            'compare' => '1',
        ], 'top_diagnoses');

        self::assertTrue($model->limit->isAll);
        self::assertSame(3, $model->page);
        self::assertTrue($model->compare);
        self::assertSame(25, $model->pageSize);
    }

    public function testParsesPageSizeAndFallsBackToDefault(): void
    {
        $factory = $this->factory();

        self::assertSame(50, $factory->fromQuery(['per_page' => '50'], 'top_diagnoses')->pageSize);
        self::assertSame(100, $factory->fromQuery(['per_page' => '100'], 'top_diagnoses')->pageSize);
        self::assertSame(25, $factory->fromQuery(['per_page' => '10'], 'top_diagnoses')->pageSize);
        self::assertSame(25, $factory->fromQuery(['per_page' => 'invalid'], 'top_diagnoses')->pageSize);
    }

    private function factory(): TopListsRequestModelFactory
    {
        return new TopListsRequestModelFactory(new TopListLimitPolicy(), new TopListPageSizePolicy());
    }
}
