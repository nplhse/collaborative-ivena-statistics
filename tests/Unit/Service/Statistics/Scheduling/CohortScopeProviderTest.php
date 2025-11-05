<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Scheduling;

use App\Model\Scope;
use App\Service\Statistics\Scheduling\CohortScopeProvider;
use App\Service\Statistics\Util\Period;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CohortScopeProviderTest extends TestCase
{
    public function testNoHospitalIdsLogsWarningAndYieldsNothing(): void
    {
        $importId = 123;

        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        // First call: distinct hospital_id -> empty []
        $db->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                self::stringContains('SELECT DISTINCT hospital_id'),
                ['id' => $importId]
            )
            ->willReturn([]);

        // No further DB calls expected
        $db->expects($this->never())->method('fetchAssociative');

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'No hospital found for import',
                ['import_id' => $importId]
            );

        $provider = new CohortScopeProvider($db, $logger);
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        self::assertSame([], $scopes, 'Expected no scopes when no hospital ids are found.');
    }

    public function testYieldsCohortWhenTierAndLocationPresent(): void
    {
        $importId = 9;

        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        // 1) Distinct hospital ids -> [7]
        $db->expects($this->atLeast(1))
            ->method('fetchFirstColumn')
            ->with(
                self::callback(function (string $sql) {
                    // first call OR subsequent period key calls
                    return str_contains($sql, 'SELECT DISTINCT hospital_id')
                        || str_contains($sql, 'SELECT DISTINCT');
                }),
                self::anything()
            )
            ->willReturnOnConsecutiveCalls(
                [7], // hospital ids
                // keys per granularity (ALL, YEAR, QUARTER, MONTH, WEEK, DAY):
                // Use one key per call for simplicity.
                ['2010-01-01'], // ALL (constant expression still queried)
                ['2023-01-01'], // YEAR
                ['2025-10-01'], // QUARTER
                [],             // MONTH (empty)
                ['2025-10-27'], // WEEK
                ['2025-11-01']  // DAY
            );

        // 2) Meta for hospital id 7
        $db->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                self::stringContains('SELECT h.tier, h.size, h.location'),
                ['hid' => 7]
            )
            ->willReturn([
                'tier' => 'full',
                'size' => 'large',
                'location' => 'urban',
            ]);

        $provider = new CohortScopeProvider($db, $logger);
        /** @var list<Scope> $scopes */
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        // Expected: one scope per returned period key, always "hospital_cohort" with "full_urban"
        $expected = [
            ['hospital_cohort', 'full_urban', Period::ALL, '2010-01-01'],
            ['hospital_cohort', 'full_urban', Period::YEAR, '2023-01-01'],
            ['hospital_cohort', 'full_urban', Period::QUARTER, '2025-10-01'],
            // MONTH -> none
            ['hospital_cohort', 'full_urban', Period::WEEK, '2025-10-27'],
            ['hospital_cohort', 'full_urban', Period::DAY, '2025-11-01'],
        ];

        $tuples = array_map(
            fn (Scope $s) => [$s->scopeType, $s->scopeId, $s->granularity, $s->periodKey],
            $scopes
        );
        self::assertSame($expected, $tuples, 'Unexpected scopes yielded for cohort classification.');
    }

    public function testFallsBackToTierOnlyWhenSizeAndLocationMissing(): void
    {
        $importId = 11;

        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        // hospital ids
        $db->expects($this->exactly(1 + 6)) // 1 for hospital ids + 6 for the granularities
        ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(
                [99],
                ['2010-01-01'], // ALL
                [], [], [], [], [] // no other keys
            );

        // meta with only tier
        $db->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(['tier' => 'extended', 'size' => null, 'location' => null]);

        $provider = new CohortScopeProvider($db, $logger);
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        $expected = [
            ['hospital_tier', 'extended', Period::ALL, '2010-01-01'],
        ];
        $tuples = array_map(
            fn (Scope $s) => [$s->scopeType, $s->scopeId, $s->granularity, $s->periodKey],
            $scopes
        );
        self::assertSame($expected, $tuples);
    }

    public function testFallsBackToSizeOnlyWhenTierAndLocationMissing(): void
    {
        $importId = 12;

        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $db->expects($this->exactly(1 + 6))
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(
                [55],
                ['2010-01-01'], // ALL
                [], [], [], [], []
            );

        $db->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(['tier' => null, 'size' => 'medium', 'location' => null]);

        $provider = new CohortScopeProvider($db, $logger);
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        $expected = [
            ['hospital_size', 'medium', Period::ALL, '2010-01-01'],
        ];
        $tuples = array_map(
            fn (Scope $s) => [$s->scopeType, $s->scopeId, $s->granularity, $s->periodKey],
            $scopes
        );
        self::assertSame($expected, $tuples);
    }

    public function testFallsBackToLocationOnlyWhenTierAndSizeMissing(): void
    {
        $importId = 13;

        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $db->expects($this->exactly(1 + 6))
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(
                [77],
                ['2010-01-01'], // ALL
                [], [], [], [], []
            );

        $db->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(['tier' => null, 'size' => null, 'location' => 'rural']);

        $provider = new CohortScopeProvider($db, $logger);
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        $expected = [
            ['hospital_location', 'rural', Period::ALL, '2010-01-01'],
        ];
        $tuples = array_map(
            fn (Scope $s) => [$s->scopeType, $s->scopeId, $s->granularity, $s->periodKey],
            $scopes
        );
        self::assertSame($expected, $tuples);
    }

    public function testMetaQueryReturnsFalseThenNoScopesIfAllNulls(): void
    {
        $importId = 14;

        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $db->expects($this->exactly(1 + 6))
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(
                [1],
                ['2010-01-01'], // ALL
                [], [], [], [], []
            );

        // fetchAssociative returns false -> provider sets all meta to null
        $db->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $provider = new CohortScopeProvider($db, $logger);
        $scopes = iterator_to_array($provider->provideForImport($importId), false);

        self::assertSame([], $scopes, 'When meta is missing and all fields are null, no cohort scope should be yielded.');
    }
}
