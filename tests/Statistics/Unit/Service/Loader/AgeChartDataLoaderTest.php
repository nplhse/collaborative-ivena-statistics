<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Loader;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Loader\AgeChartDataLoader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgeChartDataLoaderTest extends TestCase
{
    public function testReturnsEmptySeriesWhenNoRowFound(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->method('fetchAssociative')
            ->willReturn(false);

        $loader = new AgeChartDataLoader($conn);

        $scope = new Scope('public', 'all', 'year', '2025-01-01');
        $metrics = [
            ['name' => 'Total', 'col' => 'total'],
        ];

        $result = $loader->buildPayload($scope, $metrics, 'count');

        $this->assertSame(
            ['<18', '18-29', '30-39', '40-49', '50-59', '60-69', '70-79', '80-89', '90-99'],
            $result['labels']
        );

        $this->assertCount(1, $result['series']);
        $this->assertSame('Total', $result['series'][0]['name']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0], $result['series'][0]['data']);

        $this->assertNull($result['mean']);
    }

    /**
     * @param list<array{key:string,n?:int,share?:float}> $decoded
     * @param list<int|float>                             $expectedCount
     * @param list<int|float>                             $expectedShare
     */
    #[DataProvider('provideJsonBucketData')]
    public function testBucketDecodingAndModes(
        string $json,
        array $decoded,
        array $expectedCount,
        array $expectedShare,
    ): void {
        $conn = $this->createMock(Connection::class);

        $conn->method('fetchAssociative')
            ->willReturn([
                'total' => '[]', // irrelevant for this test
                'gender_m' => $json,
                'overall_age_mean' => '42.5',
            ]);

        $loader = new AgeChartDataLoader($conn);

        $metrics = [
            ['name' => 'M', 'col' => 'gender_m'],
        ];

        $scope = new Scope('public', 'all', 'month', '2025-11-01');

        $resultCount = $loader->buildPayload($scope, $metrics, 'count');
        $resultShare = $loader->buildPayload($scope, $metrics, 'share');

        $this->assertSame('M', $resultCount['series'][0]['name']);
        $this->assertSame($expectedCount, $resultCount['series'][0]['data']);

        $this->assertSame('M', $resultShare['series'][0]['name']);
        $this->assertSame($expectedShare, $resultShare['series'][0]['data']);

        $this->assertSame(42.5, $resultShare['mean']);
    }

    /**
     * @return iterable<string, array{
     *     json: string,
     *     decoded: list<array{key:string,n?:int,share?:float}>,
     *     expectedCount: list<int>,
     *     expectedShare: list<float>
     * }>
     */
    public static function provideJsonBucketData(): iterable
    {
        $len = 9;

        yield 'valid JSON with n and share' => [
            'json' => json_encode([
                ['key' => '<18',   'n' => 5, 'share' => 0.1],
                ['key' => '30-39', 'n' => 8, 'share' => 0.16],
            ], JSON_THROW_ON_ERROR),
            'decoded' => [],
            'expectedCount' => [
                5, // <18
                0, // 18-29
                8, // 30-39
                0, 0, 0, 0, 0, 0,
            ],
            'expectedShare' => [
                0.1, // <18
                0.0, // 18-29
                0.16, // 30-39
                0.0, 0.0, 0.0, 0.0, 0.0, 0.0,
            ],
        ];

        yield 'bad JSON' => [
            'json' => '{not valid json}',
            'decoded' => [],
            'expectedCount' => array_fill(0, $len, 0),
            'expectedShare' => array_fill(0, $len, 0.0),
        ];

        yield 'empty' => [
            'json' => '',
            'decoded' => [],
            'expectedCount' => array_fill(0, $len, 0),
            'expectedShare' => array_fill(0, $len, 0.0),
        ];
    }

    public function testIgnoresInvalidBucketEntries(): void
    {
        $conn = $this->createMock(Connection::class);

        $json = json_encode([
            ['key' => '<18', 'n' => 5],
            ['wrong' => 'format'],
            ['key' => '18-29', 'share' => 0.33],
            ['key' => 123], // invalid key
        ]);

        $conn->method('fetchAssociative')->willReturn([
            'total' => '',
            'gender_m' => $json,
            'overall_age_mean' => null,
        ]);

        $metrics = [
            ['name' => 'M', 'col' => 'gender_m'],
        ];

        $loader = new AgeChartDataLoader($conn);
        $scope = new Scope('public', 'all', 'day', '2025-11-08');

        $count = $loader->buildPayload($scope, $metrics, 'count');
        $share = $loader->buildPayload($scope, $metrics, 'share');

        // Should only include recognized and valid buckets (<18 and 18-29)
        $this->assertSame([5, 0, 0, 0, 0, 0, 0, 0, 0], $count['series'][0]['data']);
        $this->assertSame([0.0, 0.33, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $share['series'][0]['data']);
    }
}
