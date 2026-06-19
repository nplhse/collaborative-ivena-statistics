<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\AnalysisQueryExecutorRegistry;
use App\Statistics\GenericAnalysis\Application\Contract\AnalysisQueryExecutorInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use PHPUnit\Framework\TestCase;

final class AnalysisQueryExecutorRegistryTest extends TestCase
{
    public function testResolvesExecutorsByDataSource(): void
    {
        $allocationExecutor = new class implements AnalysisQueryExecutorInterface {
            public function supports(AnalysisDataSource $dataSource): bool
            {
                return AnalysisDataSource::Allocations === $dataSource;
            }

            public function execute(AnalysisQuery $query): AnalysisResult
            {
                throw new \LogicException('not used');
            }
        };

        $hospitalExecutor = new class implements AnalysisQueryExecutorInterface {
            public function supports(AnalysisDataSource $dataSource): bool
            {
                return AnalysisDataSource::Hospitals === $dataSource;
            }

            public function execute(AnalysisQuery $query): AnalysisResult
            {
                throw new \LogicException('not used');
            }
        };

        $registry = new AnalysisQueryExecutorRegistry([$allocationExecutor, $hospitalExecutor]);

        self::assertSame($allocationExecutor, $registry->get(AnalysisDataSource::Allocations));
        self::assertSame($hospitalExecutor, $registry->get(AnalysisDataSource::Hospitals));
    }
}
