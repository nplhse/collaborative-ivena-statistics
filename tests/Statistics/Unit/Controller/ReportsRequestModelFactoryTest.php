<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\UI\Http\Controller\ReportsRequestModelFactory;
use PHPUnit\Framework\TestCase;

final class ReportsRequestModelFactoryTest extends TestCase
{
    public function testParsesReportAndLimitFromQuery(): void
    {
        $factory = new ReportsRequestModelFactory();

        $model = $factory->fromQuery([
            'report' => 'top_diagnoses',
            'limit' => '10',
        ]);

        self::assertSame('top_diagnoses', $model->reportKey);
        self::assertSame(10, $model->limit);
    }

    public function testFallsBackToDefaultLimit(): void
    {
        $factory = new ReportsRequestModelFactory();

        self::assertSame(25, $factory->fromQuery(['limit' => 'invalid'])->limit);
        self::assertSame(25, $factory->fromQuery([])->limit);
    }
}
