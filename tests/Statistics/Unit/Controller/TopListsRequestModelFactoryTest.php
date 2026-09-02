<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\TopList\TopListLimitPolicy;
use App\Statistics\UI\Http\Controller\TopListsRequestModelFactory;
use PHPUnit\Framework\TestCase;

final class TopListsRequestModelFactoryTest extends TestCase
{
    public function testParsesReportAndLimitFromQuery(): void
    {
        $factory = new TopListsRequestModelFactory(new TopListLimitPolicy());

        $model = $factory->fromQuery([
            'limit' => '10',
        ], 'top_diagnoses');

        self::assertSame('top_diagnoses', $model->topListKey);
        self::assertSame(10, $model->limit->queryValue());
        self::assertSame(1, $model->page);
        self::assertFalse($model->compare);
    }

    public function testFallsBackToDefaultLimit(): void
    {
        $factory = new TopListsRequestModelFactory(new TopListLimitPolicy());

        self::assertSame(25, $factory->fromQuery(['limit' => 'invalid'], 'top_diagnoses')->limit->queryValue());
        self::assertSame(25, $factory->fromQuery([], 'top_diagnoses')->limit->queryValue());
    }

    public function testParsesAllLimitCompareAndPage(): void
    {
        $factory = new TopListsRequestModelFactory(new TopListLimitPolicy());

        $model = $factory->fromQuery([
            'limit' => 'all',
            'page' => '3',
            'compare' => '1',
        ], 'top_diagnoses');

        self::assertTrue($model->limit->isAll);
        self::assertSame(3, $model->page);
        self::assertTrue($model->compare);
    }
}
