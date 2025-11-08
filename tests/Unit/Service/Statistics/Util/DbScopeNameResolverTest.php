<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Statistics\Util;

use App\Service\Statistics\Util\DbScopeNameResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DbScopeNameResolverTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    protected function setUp(): void
    {
        // Arrange (shared): mock DBAL connection
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $this->db = $db;
    }

    public function testResolveHospitalQueriesByIdAndReturnsName(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT name FROM hospital WHERE id = :id', ['id' => '123'])
            ->willReturn('Saint Mary');

        $sut = new DbScopeNameResolver($this->db);

        // Act
        $result = $sut->resolve('hospital', '123');

        // Assert
        self::assertSame('Saint Mary', $result);
    }

    public function testResolveDispatchAreaQueriesById(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT name FROM dispatch_area WHERE id = :id', ['id' => '42'])
            ->willReturn('Area 42');

        $sut = new DbScopeNameResolver($this->db);

        // Act
        $result = $sut->resolve('dispatch_area', '42');

        // Assert
        self::assertSame('Area 42', $result);
    }

    public function testResolveStateQueriesById(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT name FROM state WHERE id = :id', ['id' => 'BY'])
            ->willReturn('Bavaria');

        $sut = new DbScopeNameResolver($this->db);

        // Act
        $result = $sut->resolve('state', 'BY');

        // Assert
        self::assertSame('Bavaria', $result);
    }

    public function testResolveDefaultBranchReturnsNullAndDoesNotHitDatabase(): void
    {
        // Arrange
        $this->db->expects(self::never())->method('fetchOne');

        $sut = new DbScopeNameResolver($this->db);

        // Act
        $result = $sut->resolve('region', '99');

        // Assert
        self::assertNull($result);
    }

    public function testResolveConvertsFalseReturnValueToNull(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT name FROM hospital WHERE id = :id', ['id' => 'not-found'])
            ->willReturn(false);

        $sut = new DbScopeNameResolver($this->db);

        // Act
        $result = $sut->resolve('hospital', 'not-found');

        // Assert
        self::assertNull($result);
    }

    public function testResolvePropagatesExceptionsFromDbal(): void
    {
        // Arrange
        $this->db
            ->expects(self::once())
            ->method('fetchOne')
            ->willThrowException(new \RuntimeException('DB down'));

        $sut = new DbScopeNameResolver($this->db);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB down');
        $sut->resolve('hospital', '123');
    }
}
