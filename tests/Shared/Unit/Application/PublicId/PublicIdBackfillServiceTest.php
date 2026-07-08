<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\PublicId;

use App\Shared\Application\PublicId\PublicIdBackfillService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PublicIdBackfillServiceTest extends TestCase
{
    public function testDryRunCountsRemainingRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnMap([
            ['SELECT COUNT(*) FROM hospital WHERE public_id IS NULL', [], [], 2],
            ['SELECT COUNT(*) FROM secondary_transport WHERE public_id IS NULL', [], [], 0],
            ['SELECT COUNT(*) FROM indication_raw WHERE public_id IS NULL', [], [], 1],
            ['SELECT COUNT(*) FROM mci_case WHERE public_id IS NULL', [], [], 0],
            ['SELECT COUNT(*) FROM allocation WHERE public_id IS NULL', [], [], 5],
        ]);

        $service = new PublicIdBackfillService($connection);
        $result = $service->run(dryRun: true);

        self::assertFalse($result->completed);
        self::assertSame(2, $result->remainingByTable['hospital']);
        self::assertSame(5, $result->remainingByTable['allocation']);
        self::assertSame(0, $result->updatedByTable['hospital']);
    }

    public function testInvalidTableThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $service = new PublicIdBackfillService($connection);

        $this->expectException(\InvalidArgumentException::class);

        $service->run(tables: ['unknown_table']);
    }

    public function testGeneratedUuidsAreValidV4(): void
    {
        $uuid = Uuid::v4();

        self::assertSame(36, \strlen($uuid->toRfc4122()));
        self::assertTrue(Uuid::isValid($uuid->toRfc4122()));
    }
}
