<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Compute\Sql;

use App\Model\Scope;
use App\Service\Statistics\Compute\Sql\ScopeFilterBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopeFilterBuilderTest extends TestCase
{
    /**
     * @param array<string,int|float|string|bool> $expectedParams
     */
    #[DataProvider('provideSimpleScopes')]
    public function testBuildBaseFilterForSimpleScopes(string $scopeType, string $expectedWherePart, array $expectedParams): void
    {
        $builder = new ScopeFilterBuilder();

        // periodKey / granularity do not matter for this test;
        // only the scope-specific SQL fragment is asserted
        $scope = new Scope($scopeType, $expectedParams['scope_id'] ?? 'all', 'year', '2025-01-01');

        [$from, $where, $params] = $builder->buildBaseFilter($scope);

        // FROM clause should be the plain base table if no joining is required
        $this->assertSame('allocation a', $from);

        // WHERE clause should contain the expected scope-specific fragment
        $this->assertStringContainsString($expectedWherePart, $where);

        // Period-expression is appended; we only assert that the AND is present
        $this->assertStringContainsString(' AND ', $where);

        // Validate only scope-specific parameters (period params vary by trait)
        foreach ($expectedParams as $k => $v) {
            $this->assertArrayHasKey($k, $params);
            $this->assertSame($v, $params[$k]);
        }
    }

    /**
     * @return iterable<string, array{
     *      scopeType: string,
     *      expectedWherePart: string,
     *      expectedParams: array<string, string>
     *  }>
     */
    public static function provideSimpleScopes(): iterable
    {
        yield 'public' => [
            'scopeType' => 'public',
            'expectedWherePart' => 'TRUE',
            'expectedParams' => [],
        ];

        yield 'all' => [
            'scopeType' => 'all',
            'expectedWherePart' => 'TRUE',
            'expectedParams' => [],
        ];

        yield 'hospital' => [
            'scopeType' => 'hospital',
            'expectedWherePart' => 'a.hospital_id = :scope_id::int',
            'expectedParams' => ['scope_id' => '123'],
        ];

        yield 'dispatch_area' => [
            'scopeType' => 'dispatch_area',
            'expectedWherePart' => 'a.dispatch_area_id = :scope_id::int',
            'expectedParams' => ['scope_id' => '42'],
        ];

        yield 'state' => [
            'scopeType' => 'state',
            'expectedWherePart' => 'a.state_id = :scope_id::int',
            'expectedParams' => ['scope_id' => '9'],
        ];
    }

    #[DataProvider('provideHospitalDimensionScopes')]
    public function testBuildBaseFilterForHospitalDimensions(string $scopeType, string $expectedColumn): void
    {
        $builder = new ScopeFilterBuilder();
        $scope = new Scope($scopeType, 'X', 'month', '2025-11-01');

        [$from, $where, $params] = $builder->buildBaseFilter($scope);

        // These scopes always produce a JOIN with the hospital table
        $this->assertSame('allocation a JOIN hospital h ON h.id = a.hospital_id', $from);

        // WHERE clause should contain the correct derived hospital column
        $this->assertStringContainsString(sprintf('h.%s = :hv', $expectedColumn), $where);

        // Parameter must exist and match the scopeId
        $this->assertArrayHasKey('hv', $params);
        $this->assertSame('X', $params['hv']);

        $this->assertStringContainsString(' AND ', $where);
    }

    /**
     * @return iterable<string, array{0:string,1:string}>
     */
    public static function provideHospitalDimensionScopes(): iterable
    {
        yield 'hospital_tier' => ['hospital_tier', 'tier'];
        yield 'hospital_size' => ['hospital_size', 'size'];
        yield 'hospital_location' => ['hospital_location', 'location'];
    }

    public function testBuildBaseFilterForHospitalCohortValid(): void
    {
        $builder = new ScopeFilterBuilder();
        $scope = new Scope('hospital_cohort', 'tierA_locB', 'week', '2025-11-03');

        [$from, $where, $params] = $builder->buildBaseFilter($scope);

        // Should join hospital table
        $this->assertSame('allocation a JOIN hospital h ON h.id = a.hospital_id', $from);

        // WHERE clause should include both tier and location filters
        $this->assertStringContainsString('h.tier = :t AND h.location = :l', $where);

        // ScopeId should be split correctly
        $this->assertSame('tierA', $params['t']);
        $this->assertSame('locB', $params['l']);

        $this->assertStringContainsString(' AND ', $where);
    }

    public function testBuildBaseFilterThrowsOnUnknownScopeType(): void
    {
        $scope = new Scope('what_is_this', 'x', 'year', '2025-01-01');

        $builder = new ScopeFilterBuilder();
        $this->expectException(\RuntimeException::class);

        $builder->buildBaseFilter($scope);
    }

    #[DataProvider('invalidCohortIdsProvider')]
    public function testBuildBaseFilterThrowsOnBrokenCohortId(string $badId): void
    {
        // Arrange: cohort scope with invalid scopeId shape
        $scope = new Scope('hospital_cohort', $badId, 'year', '2025-01-01');
        $builder = new ScopeFilterBuilder();

        // Assert: we expect a RuntimeException for invalid cohort ids
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid hospital_cohort scopeId');

        // Act
        $builder->buildBaseFilter($scope);
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidCohortIdsProvider(): array
    {
        return [
            ['onlytier'], // no underscore -> location becomes null
            ['_north'], // empty tier
            ['tier_'], // empty location
        ];
    }
}
