<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Domain\Model;

use App\Statistics\Domain\Model\DashboardContext;
use App\Statistics\Domain\Model\Scope;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    public function testLockKeyBuildsExpectedString(): void
    {
        // Arrange
        $s = new Scope('hospital', '123', 'month', '2025-11-01');

        // Act
        $lock = $s->lockKey();

        // Assert
        self::assertSame('agg:hospital:123:month:2025-11-01', $lock);
    }

    public function testIsHospital(): void
    {
        // Arrange
        $a = new Scope('hospital', '1', 'all', '2010-01-01');
        $b = new Scope('state', 'BY', 'all', '2010-01-01');

        // Act & Assert
        self::assertTrue($a->isHospital());
        self::assertFalse($b->isHospital());
    }

    public function testFromDashboardContextUsesDefaultsWhenKeysAreMissing(): void
    {
        // Arrange
        // Use fromQuery() WITHOUT keys -> lets DashboardContext supply valid defaults.
        $ctx = DashboardContext::fromQuery([]); // scopeType=public, scopeId=all, gran=all, key=2010-01-01

        // Act
        $s = Scope::fromDashboardContext($ctx);

        // Assert
        self::assertSame('public', $s->scopeType);
        self::assertSame('all', $s->scopeId);
        self::assertSame('all', $s->granularity);
        self::assertSame('2010-01-01', $s->periodKey); // anchor for 'all'
    }

    public function testFromDashboardContextNormalizesMonthKeyToFirstOfMonth(): void
    {
        // Arrange
        // Pass only gran/key (valid values), omit others if desired.
        $ctx = DashboardContext::fromQuery([
            'scopeType' => 'public',
            'scopeId' => 'all',
            'gran' => 'month',
            'key' => '2025-11-08', // will be normalized to 2025-11-01
        ]);

        // Act
        $s = Scope::fromDashboardContext($ctx);

        // Assert
        self::assertSame('month', $s->granularity);
        self::assertSame('2025-11-01', $s->periodKey);
    }

    public function testFromDashboardContextSetsAllToAnchorDateRegardlessOfKey(): void
    {
        // Arrange
        $ctx = DashboardContext::fromQuery([
            'scopeType' => 'state',
            'scopeId' => 'BY',
            'gran' => 'all',
            'key' => '2099-12-31', // should be ignored for 'all'
        ]);

        // Act
        $s = Scope::fromDashboardContext($ctx);

        // Assert
        self::assertSame('state', $s->scopeType);
        self::assertSame('BY', $s->scopeId);
        self::assertSame('all', $s->granularity);
        self::assertSame('2010-01-01', $s->periodKey);
    }

    public function testFromDashboardContextRespectsProvidedValidValues(): void
    {
        // Arrange
        $ctx = DashboardContext::fromQuery([
            'scopeType' => 'dispatch_area',
            'scopeId' => '42',
            'gran' => 'day',
            'key' => '2025-11-08',
        ]);

        // Act
        $s = Scope::fromDashboardContext($ctx);

        // Assert
        self::assertSame('dispatch_area', $s->scopeType);
        self::assertSame('42', $s->scopeId);
        self::assertSame('day', $s->granularity);
        self::assertSame('2025-11-08', $s->periodKey);
    }

    public function testFromDashboardContextDoesNotAllowConstructingInvalidDashboardContext(): void
    {
        // Arrange
        // If you try to construct DashboardContext with empty strings, it must throw in its constructor.
        $this->expectException(\InvalidArgumentException::class);

        // Act
        // This is intentionally invalid and should fail *before* Scope::fromDashboardContext():
        new DashboardContext('', '', '', ''); // will throw
    }
}
