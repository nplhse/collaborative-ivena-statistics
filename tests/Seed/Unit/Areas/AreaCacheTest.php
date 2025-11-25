<?php

namespace App\Tests\Seed\Unit\Areas;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\State;
use App\Seed\Infrastructure\Areas\AreaCache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class AreaCacheTest extends TestCase
{
    public function testWarmUpBuildsStateAndAreaCache(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $em->method('createQueryBuilder')->willReturn($qb);
        $qb->method('from')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $query->expects($this->exactly(2))
            ->method('getArrayResult')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1, 'name' => 'Hessen']],
                [['id' => 10, 'area' => 'Frankfurt', 'state' => 'Hessen']]
            );

        $cache = new AreaCache($em);
        $cache->warmUp();

        self::assertTrue($cache->hasState('Hessen'));
        self::assertTrue($cache->hasArea('Hessen', 'Frankfurt'));
        self::assertFalse($cache->hasState('Bayern'));
        self::assertFalse($cache->hasArea('Hessen', 'Kassel'));
    }

    public function testGetStateRefReturnsReference(): void
    {
        $stateRef = new State();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getReference')->with(State::class, 1)->willReturn($stateRef);

        $cache = new AreaCache($em);
        $refProperty = new \ReflectionProperty($cache, 'stateIdByName');
        $refProperty->setValue($cache, ['Hessen' => 1]);

        $result = $cache->getStateRef('Hessen');
        self::assertSame($stateRef, $result);
    }

    public function testGetStateRefThrowsIfNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $cache = new AreaCache($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("State 'Hessen' not found");

        $cache->getStateRef('Hessen');
    }

    public function testGetAreaRefReturnsReference(): void
    {
        $areaRef = new DispatchArea();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getReference')->with(DispatchArea::class, 5)->willReturn($areaRef);

        $cache = new AreaCache($em);
        $refProperty = new \ReflectionProperty($cache, 'areaIdByKey');
        $refProperty->setValue($cache, ['Hessen|Frankfurt' => 5]);

        $result = $cache->getAreaRef('Hessen', 'Frankfurt');
        self::assertSame($areaRef, $result);
    }

    public function testGetAreaRefThrowsIfNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $cache = new AreaCache($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("DispatchArea 'Frankfurt' in state 'Hessen' not found");

        $cache->getAreaRef('Hessen', 'Frankfurt');
    }

    public function testWarmUpIsIdempotent(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $em->method('createQueryBuilder')->willReturn($qb);
        $qb->method('from')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getArrayResult')->willReturn([]);

        $cache = new AreaCache($em);
        $cache->warmUp();

        $em->expects($this->never())->method('createQueryBuilder');

        $cache->warmUp();
    }
}
