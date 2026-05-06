<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\UI\Http\Controller\AnalysisKeyAliasResolver;
use PHPUnit\Framework\TestCase;

final class AnalysisKeyAliasResolverTest extends TestCase
{
    public function testResolvesLegacyAliases(): void
    {
        $resolver = new AnalysisKeyAliasResolver();

        self::assertSame('allocation_pivot', $resolver->resolve('pivot'));
        self::assertSame('allocations_by_month', $resolver->resolve('allocations_over_time'));
        self::assertSame('hospital_pivot', $resolver->resolve('hospital_pivot'));
    }
}
