<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\OccasionDistributionNameMapper;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OccasionDistributionNameMapperTest extends TestCase
{
    public function testLabelZeroMeansNoOccasionWithoutQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('statistics.distribution.occasion.none')
            ->willReturn('No occasion');

        self::assertSame(
            'No occasion',
            new OccasionDistributionNameMapper($connection, $translator)->label(0),
        );
    }

    public function testLabelFetchesNameById(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT name FROM occasion WHERE id = :id', ['id' => 9])
            ->willReturn('Storm');

        $translator = $this->createMock(TranslatorInterface::class);

        self::assertSame('Storm', new OccasionDistributionNameMapper($connection, $translator)->label(9));
    }
}
