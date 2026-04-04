<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\AssignmentDistributionNameMapper;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssignmentDistributionNameMapperTest extends TestCase
{
    public function testLabelFetchesNameOnceAndCaches(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT name FROM assignment WHERE id = :id', ['id' => 42])
            ->willReturn('ALS North');

        $translator = $this->createMock(TranslatorInterface::class);

        $mapper = new AssignmentDistributionNameMapper($connection, $translator);

        self::assertSame('ALS North', $mapper->label(42));
        self::assertSame('ALS North', $mapper->label(42));
    }

    public function testLabelWithoutFetchWhenInvalidId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('statistics.distribution.assignment.invalid_id')
            ->willReturn('Unknown assignment');

        self::assertSame(
            'Unknown assignment',
            new AssignmentDistributionNameMapper($connection, $translator)->label(-1),
        );
    }
}
