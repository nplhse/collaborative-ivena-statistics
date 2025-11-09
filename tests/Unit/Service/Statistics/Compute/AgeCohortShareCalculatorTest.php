<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Compute;

use App\Model\Scope;
use App\Service\Statistics\Compute\AgeCohortShareCalculator;
use App\Service\Statistics\Compute\Sql\ScopeFilterBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgeCohortShareCalculatorTest extends TestCase
{
    /**
     * Build a realistic row set that mimics DB output where numbers may be
     * ints or floats interchangeably after json_encode/json_decode.
     *
     * @return list<array{t:string,payload:string}>
     */
    private static function baseRows(): array
    {
        return [
            [
                't' => 'rows',
                'payload' => json_encode([
                    'cohort' => '18-29',
                    'total' => 4,
                    'gender_m' => 3.0,
                    'gender_w' => 1.0,
                    'gender_d' => 0.0,
                    'urg_1' => 1.0,
                    'urg_2' => 2.0,
                    'urg_3' => 1.0,
                    'cathlab_required' => 1.0,
                    'resus_required' => 0.0,
                    'is_cpr' => 0.0,
                    'is_ventilated' => 1.0,
                    'is_shock' => 0.0,
                    'is_pregnant' => 0.0,
                    'with_physician' => 1.0,
                    'infectious' => 0.0,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                't' => 'rows',
                'payload' => json_encode([
                    'cohort' => '30-39',
                    'total' => 6,
                    'gender_m' => 2.0,
                    'gender_w' => 4.0,
                    'gender_d' => 0.0,
                    'urg_1' => 2.0,
                    'urg_2' => 2.0,
                    'urg_3' => 2.0,
                    'cathlab_required' => 0.0,
                    'resus_required' => 1.0,
                    'is_cpr' => 1.0,
                    'is_ventilated' => 0.0,
                    'is_shock' => 1.0,
                    'is_pregnant' => 1.0,
                    'with_physician' => 0.0,
                    'infectious' => 1.0,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                't' => 'totals',
                'payload' => json_encode([
                    'total' => 10,
                    'gender_m' => 5.0,
                    'gender_w' => 5.0,
                    'gender_d' => 0.0,
                    'urg_1' => 3.0,
                    'urg_2' => 4.0,
                    'urg_3' => 3.0,
                    'cathlab_required' => 1.0,
                    'resus_required' => 1.0,
                    'is_cpr' => 1.0,
                    'is_ventilated' => 1.0,
                    'is_shock' => 1.0,
                    'is_pregnant' => 1.0,
                    'with_physician' => 1.0,
                    'infectious' => 1.0,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                't' => 'overall',
                'payload' => json_encode([
                    'mean' => 37.5,
                    'variance' => 12.0, // may become 12 (int) after decode
                    'stddev' => 3.46, // may become 3.46 (float)
                ], JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * Two scenarios:
     *  - public scope → variance & stddev must be NULL in UPSERT params
     *  - hospital_cohort → variance & stddev must be present (numeric)
     *
     * @return array<string, array{0:Scope,1:bool}>
     */
    public static function provideCalculateUpserts(): array
    {
        return [
            'public-month' => [
                new Scope('public', 'all', 'month', '2024-01-01'),
                false,
            ],
            'hospital-cohort-month' => [
                new Scope('hospital_cohort', 'tier_loc', 'month', '2024-01-01'),
                true,
            ],
        ];
    }

    #[DataProvider('provideCalculateUpserts')]
    public function testCalculateUpsertsPayload(Scope $scope, bool $expectCohortStats): void
    {
        $db = $this->createMock(Connection::class);
        $filter = $this->createMock(ScopeFilterBuilder::class);

        // Return dummy FROM/WHERE/PARAMS
        $filter->method('buildBaseFilter')
            ->willReturn(['allocation a', 'TRUE', ['k' => 'v']]);

        $rows = self::baseRows();

        // SELECT expectation (avoid deprecated isType(), use callbacks)
        $db->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(fn ($sql) => is_string($sql) && str_contains($sql, 'WITH cohortized')),
                $this->callback('is_array')
            )
            ->willReturn($rows);

        // UPSERT expectation; compare numeric values tolerant
        $db->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(fn ($sql) => is_string($sql)
                    && str_contains($sql, 'INSERT INTO agg_allocations_age_buckets')
                ),
                $this->callback(function (array $params) use ($expectCohortStats): bool {
                    foreach ([
                        't', 'i', 'g', 'k',
                        'total', 'gender_m', 'gender_w', 'gender_d',
                        'urg_1', 'urg_2', 'urg_3',
                        'cathlab_required', 'resus_required',
                        'is_cpr', 'is_ventilated', 'is_shock', 'is_pregnant',
                        'with_physician', 'infectious',
                        'overall_age_mean', 'overall_age_variance', 'overall_age_stddev',
                    ] as $key) {
                        if (!array_key_exists($key, $params)) {
                            $this->fail("Missing upsert param: {$key}");
                        }
                    }

                    // Mean must be numeric ~ 37.5 (allow int/float ambiguity)
                    $this->assertEqualsWithDelta(37.5, (float) $params['overall_age_mean'], 1e-9);

                    if ($expectCohortStats) {
                        // Variance/stddev present; compare as float with delta
                        $this->assertEqualsWithDelta(12.0, (float) $params['overall_age_variance'], 1e-9);
                        $this->assertEqualsWithDelta(3.46, (float) $params['overall_age_stddev'], 1e-9);
                    } else {
                        $this->assertNull($params['overall_age_variance']);
                        $this->assertNull($params['overall_age_stddev']);
                    }

                    // Decode one series and assert structure numerically
                    $total = json_decode((string) $params['total'], true);
                    $this->assertIsArray($total);

                    // First bucket exists and is zero
                    $this->assertSame('<18', $total[0]['key']);
                    $this->assertSame(0, (int) $total[0]['n']);
                    $this->assertEqualsWithDelta(0.0, (float) $total[0]['share'], 1e-12);

                    // 18–29 bucket: n=4, share=0.4 (allow float/int JSON ambiguity)
                    $row1829 = array_values(array_filter(
                        $total,
                        static fn ($x) => isset($x['key']) && '18-29' === $x['key']
                    ))[0] ?? null;

                    $this->assertNotNull($row1829);
                    $this->assertSame(4, (int) $row1829['n']);
                    $this->assertEqualsWithDelta(0.4, (float) $row1829['share'], 1e-12);

                    return true;
                })
            );

        $sut = new AgeCohortShareCalculator($db, $filter);
        $sut->calculate($scope);
    }
}
