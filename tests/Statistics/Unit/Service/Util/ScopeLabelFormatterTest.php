<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Util;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Resolver\DbScopeNameResolver;
use App\Statistics\Infrastructure\Util\ScopeLabelFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ScopeLabelFormatterTest extends TestCase
{
    /** @var DbScopeNameResolver&MockObject */
    private DbScopeNameResolver $resolver;

    protected function setUp(): void
    {
        /** @var DbScopeNameResolver&MockObject $resolver */
        $resolver = $this->createMock(DbScopeNameResolver::class);
        $this->resolver = $resolver;
    }

    /**
     * @param array<string,string> $map e.g. ['hospital|123' => 'Saint Mary']
     * */
    private function stubResolverMap(array $map): void
    {
        // $map example: ['hospital|123' => 'Saint Mary', 'state|BY' => 'Bavaria']
        $this->resolver
            ->method('resolve')
            ->willReturnCallback(
                static function (string $type, string $id) use ($map): ?string {
                    $key = $type.'|'.$id;

                    return $map[$key] ?? null;
                }
            );
    }

    public function testFormatGranularityAllUsesScopeDashPeriodOrder(): void
    {
        // Arrange
        $this->stubResolverMap(['hospital|123' => 'Saint Mary']);
        $sut = new ScopeLabelFormatter($this->resolver);

        $scope = new Scope(
            scopeType: 'hospital',
            scopeId: '123',
            granularity: 'all',
            periodKey: 'ignored'
        );

        // Act
        $label = $sut->format($scope);

        // Assert
        self::assertSame('Hospital: Saint Mary – Overall Summary', $label);
    }

    public function testFormatNonAllGranularityUsesPeriodDashScopeOrder(): void
    {
        // Arrange
        $this->stubResolverMap(['state|BY' => 'Bavaria']);
        $sut = new ScopeLabelFormatter($this->resolver);

        $scope = new Scope(
            scopeType: 'state',
            scopeId: 'BY',
            granularity: 'month',
            periodKey: '2025-11-01'
        );

        // Act
        $label = $sut->format($scope);

        // Assert
        self::assertSame('November 2025 – State: Bavaria', $label);
    }

    public function testFormatCanExcludePeriodPart(): void
    {
        // Arrange
        $this->stubResolverMap(['dispatch_area|42' => 'Area 42']);
        $sut = new ScopeLabelFormatter($this->resolver);

        $scope = new Scope(
            scopeType: 'dispatch_area',
            scopeId: '42',
            granularity: 'day',
            periodKey: '2025-11-08'
        );

        // Act
        $label = $sut->format($scope, includePeriod: false);

        // Assert
        self::assertSame('Dispatch Area: Area 42', $label);
    }

    #[DataProvider('provideScopeOnlyCases')]
    public function testFormatScopeOnlyCoversAllKnownScopeTypes(string $scopeType, string $scopeId, ?string $resolvedName, string $expected): void
    {
        // Arrange
        $map = [];
        if (null !== $resolvedName) {
            $map[$scopeType.'|'.$scopeId] = $resolvedName;
        }
        $this->stubResolverMap($map);

        $sut = new ScopeLabelFormatter($this->resolver);
        $scope = new Scope($scopeType, $scopeId, 'all', 'ignored');

        // Act
        $label = $sut->formatScopeOnly($scope);

        // Assert
        self::assertSame($expected, $label);
    }

    /**
     * @return iterable<array{0:string,1:string,2:?string,3:string}>
     */
    public static function provideScopeOnlyCases(): iterable
    {
        // resolver-based types
        yield 'public' => ['public', 'x', null, 'Public Data'];
        yield 'hospital' => ['hospital', '123', 'Saint Mary', 'Hospital: Saint Mary'];
        yield 'dispatch_area' => ['dispatch_area', '42', 'Area 42', 'Dispatch Area: Area 42'];
        yield 'state' => ['state', 'BY', 'Bavaria', 'State: Bavaria'];

        // ucfirst on id
        yield 'hospital_tier' => ['hospital_tier', 'advanced', null, 'Hospital Tier: Advanced'];
        yield 'hospital_size' => ['hospital_size', 'large', null, 'Hospital Size: Large'];
        yield 'hospital_location' => ['hospital_location', 'north', null, 'Hospital Location: North'];

        // cohort uses raw id (e.g. "Basic_Urban")
        yield 'hospital_cohort' => ['hospital_cohort', 'Basic_Urban', null, 'Hospital Cohort: Basic_Urban'];

        // default fallback: "UcfirstType id"
        yield 'default_fallback' => ['region', '99', null, 'Region 99'];
    }

    #[DataProvider('providePeriodCases')]
    public function testFormatPeriodLabelsAreCorrect(string $granularity, string $periodKey, string $expectedPeriodLabel): void
    {
        // Arrange
        // Note: the resolver is called even for 'public', but the name is ignored.
        // We can safely return null by not mapping anything.
        $this->stubResolverMap([]);
        $sut = new ScopeLabelFormatter($this->resolver);

        $scope = new Scope(
            scopeType: 'public',
            scopeId: 'x',
            granularity: $granularity,
            periodKey: $periodKey
        );

        // Act
        $label = $sut->format($scope);

        // Assert
        if ('all' === $granularity) {
            self::assertSame('Public Data – '.$expectedPeriodLabel, $label);
        } else {
            self::assertSame($expectedPeriodLabel.' – Public Data', $label);
        }
    }

    /**
     * @return iterable<array{0:string,1:string,2:string}>
     */
    public static function providePeriodCases(): iterable
    {
        yield 'all' => ['all', 'ignored', 'Overall Summary'];
        yield 'year' => ['year', '2025-01-15', 'Year 2025'];
        yield 'quarter_q1' => ['quarter', '2025-02-10', 'Q1 2025'];
        yield 'quarter_q2' => ['quarter', '2025-05-01', 'Q2 2025'];
        yield 'month' => ['month', '2025-11-08', 'November 2025'];
        yield 'week' => ['week', '2025-11-08', sprintf('Week %s 2025', (new \DateTimeImmutable('2025-11-08'))->format('W'))];
        yield 'day' => ['day', '2025-11-08', 'Nov 8, 2025'];
        yield 'fallback_unknown' => ['hour', '2025-11-08 13:00:00', 'Hour'];
    }

    public function testFormatThrowsOnInvalidDateStringForNonAll(): void
    {
        // Arrange
        $this->stubResolverMap([]); // resolver can be called, but value is ignored
        $sut = new ScopeLabelFormatter($this->resolver);

        $scope = new Scope(
            scopeType: 'public',
            scopeId: 'x',
            granularity: 'month',
            periodKey: 'definitely-not-a-date'
        );

        // Act & Assert
        $this->expectException(\Exception::class);
        $sut->format($scope);
    }
}
