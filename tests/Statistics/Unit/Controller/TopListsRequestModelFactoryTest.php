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
            'report' => 'top_diagnoses',
            'limit' => '10',
        ]);

        self::assertSame('top_diagnoses', $model->topListKey);
        self::assertSame(10, $model->limit);
    }

    public function testFallsBackToDefaultLimit(): void
    {
        $factory = new TopListsRequestModelFactory(new TopListLimitPolicy());

        self::assertSame(25, $factory->fromQuery(['limit' => 'invalid'])->limit);
        self::assertSame(25, $factory->fromQuery([])->limit);
    }
}
