<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Indication;

use App\Allocation\Application\Indication\IndicationRawCorruptionMergeService;
use App\Import\Infrastructure\Indication\IndicationKey;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IndicationRawCorruptionMergeServiceTest extends TestCase
{
    public function testRunRollsBackAndRethrowsWhenPersistFails(): void
    {
        $connection = $this->connectionMock();
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'FROM indication_raw')) {
                    return [[
                        'id' => 1,
                        'code' => '332',
                        'name' => 'STEMI / "OMI"',
                        'hash' => 'stale-hash',
                        'review_status' => 'unreviewed',
                        'target_id' => null,
                        'normalized_id' => null,
                    ]];
                }

                return [];
            },
        );
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('executeStatement')
            ->willThrowException(new \RuntimeException('persist failed'));
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $service = new IndicationRawCorruptionMergeService($connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('persist failed');

        $service->run(false);
    }

    public function testQuoteVariantMergeSkipsMissingSurvivorRow(): void
    {
        $hash = IndicationKey::hashFrom('332', 'STEMI / "OMI"');
        $connection = $this->connectionMock();
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($hash): array {
                if (str_contains($sql, 'FROM indication_raw')) {
                    return [
                        [
                            'id' => 10,
                            'code' => '332',
                            'name' => 'STEMI / "OMI"',
                            'hash' => $hash,
                            'review_status' => 'matched',
                            'target_id' => 3,
                            'normalized_id' => 3,
                        ],
                        [
                            'id' => 11,
                            'code' => '332',
                            'name' => 'STEMI / \\OMI\\""',
                            'hash' => $hash,
                            'review_status' => 'unreviewed',
                            'target_id' => null,
                            'normalized_id' => null,
                        ],
                    ];
                }

                return [];
            },
        );
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::never())->method('executeStatement');

        $result = new IndicationRawCorruptionMergeService($connection)->run(false);

        self::assertCount(1, $result->actions);
        self::assertSame('quote_variant', $result->actions[0]->type);
        self::assertSame([], $result->affectedImportIds);
    }

    /**
     * @return MockObject&Connection
     */
    private function connectionMock(): MockObject
    {
        return $this->createMock(Connection::class);
    }
}
