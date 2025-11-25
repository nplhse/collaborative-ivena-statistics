<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Domain\Model;

use App\Statistics\Domain\Model\DashboardContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DashboardContextTest extends TestCase
{
    public function testConstructWithValidValuesWorksAndToQueryMapsFields(): void
    {
        // Arrange
        $scopeType = 'state';
        $scopeId = 'BY';
        $granularity = 'month';
        $periodKey = '2025-11-01';

        // Act
        $ctx = new DashboardContext($scopeType, $scopeId, $granularity, $periodKey);

        // Assert
        $q = $ctx->toQuery();
        self::assertSame('state', $q['scopeType']);
        self::assertSame('BY', $q['scopeId']);
        self::assertSame('month', $q['gran']);
        self::assertSame('2025-11-01', $q['key']);
    }

    public function testFromQueryUsesDefaultsWhenMissing(): void
    {
        // Arrange
        $query = []; // simulate empty query

        // Act
        $ctx = DashboardContext::fromQuery($query);

        // Assert
        // defaults per class: scopeType=public, scopeId=all, gran=all, key=2010-01-01
        self::assertSame(
            ['scopeType' => 'public', 'scopeId' => 'all', 'gran' => 'all', 'key' => '2010-01-01'],
            $ctx->toQuery()
        );
    }

    public function testFromQueryUsesProvidedValues(): void
    {
        // Arrange
        $query = [
            'scopeType' => 'hospital',
            'scopeId' => '123',
            'gran' => 'week',
            'key' => '2025-11-03',
        ];

        // Act
        $ctx = DashboardContext::fromQuery($query);

        // Assert
        self::assertSame($query, $ctx->toQuery());
    }

    public function testWithCreatesNewInstanceAndOverridesSelectedFields(): void
    {
        // Arrange
        $original = new DashboardContext('dispatch_area', '42', 'day', '2025-11-08');

        // Act
        $modified = $original->with(granularity: 'month', periodKey: '2025-11-01');

        // Assert
        // immutability: original unchanged
        self::assertSame(
            ['scopeType' => 'dispatch_area', 'scopeId' => '42', 'gran' => 'day', 'key' => '2025-11-08'],
            $original->toQuery()
        );

        // new instance has overrides applied
        self::assertSame(
            ['scopeType' => 'dispatch_area', 'scopeId' => '42', 'gran' => 'month', 'key' => '2025-11-01'],
            $modified->toQuery()
        );
    }

    #[DataProvider('provideInvalidScopeTypes')]
    public function testConstructThrowsForInvalidScopeType(string $invalidScope): void
    {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scopeType');

        // Act
        new DashboardContext($invalidScope, 'x', 'all', '2010-01-01');
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideInvalidScopeTypes(): iterable
    {
        yield ['foo'];
        yield [''];
        yield ['STATE']; // case-sensitive invalid
    }

    #[DataProvider('provideInvalidGranularities')]
    public function testConstructThrowsForInvalidGranularity(string $invalidGran): void
    {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid granularity');

        // Act
        new DashboardContext('public', 'all', $invalidGran, '2010-01-01');
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideInvalidGranularities(): iterable
    {
        yield ['hour'];
        yield [''];
        yield ['Month']; // case-sensitive invalid
    }
}
