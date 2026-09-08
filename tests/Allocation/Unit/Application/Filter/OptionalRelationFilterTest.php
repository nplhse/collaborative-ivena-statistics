<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Filter;

use App\Allocation\Application\Filter\OptionalRelationFilter;
use App\Allocation\Application\Filter\OptionalRelationFilterState;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class OptionalRelationFilterTest extends TestCase
{
    public function testFromQueryParsesUnsetAbsentPresentAndEquals(): void
    {
        self::assertSame(OptionalRelationFilterState::Unset, OptionalRelationFilter::fromQuery(null)->state);
        self::assertSame(OptionalRelationFilterState::Unset, OptionalRelationFilter::fromQuery('')->state);
        self::assertSame(OptionalRelationFilterState::Unset, OptionalRelationFilter::fromQuery('0')->state);
        self::assertSame(OptionalRelationFilterState::Absent, OptionalRelationFilter::fromQuery('none')->state);
        self::assertSame(OptionalRelationFilterState::Present, OptionalRelationFilter::fromQuery('any')->state);
        self::assertSame(OptionalRelationFilterState::Unset, OptionalRelationFilter::fromQuery('any', allowPresent: false)->state);

        $equals = OptionalRelationFilter::fromQuery('42');
        self::assertSame(OptionalRelationFilterState::Equals, $equals->state);
        self::assertSame(42, $equals->equalsId);
        self::assertTrue($equals->isActive());
        self::assertFalse(OptionalRelationFilter::unset()->isActive());
        self::assertSame([0, null], OptionalRelationFilter::fromQuery('none')->toPresenceAndId());
        self::assertSame([1, null], OptionalRelationFilter::fromQuery('any')->toPresenceAndId());
        self::assertSame([1, 42], OptionalRelationFilter::fromQuery('42')->toPresenceAndId());
    }

    public function testFromPresenceAndValuePrefersConcreteId(): void
    {
        $equals = OptionalRelationFilter::fromPresenceAndValue(false, 7);
        self::assertSame(OptionalRelationFilterState::Equals, $equals->state);
        self::assertSame(7, $equals->equalsId);

        self::assertSame(OptionalRelationFilterState::Present, OptionalRelationFilter::fromPresenceAndValue(true, null)->state);
        self::assertSame(OptionalRelationFilterState::Absent, OptionalRelationFilter::fromPresenceAndValue(false, null)->state);
        self::assertSame(OptionalRelationFilterState::Unset, OptionalRelationFilter::fromPresenceAndValue(null, null)->state);
    }

    public function testApplyWritesNullNotNullAndEquality(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())->method('andWhere')->with('a.secondaryTransport IS NULL')->willReturnSelf();

        OptionalRelationFilter::fromQuery('none')->apply(
            $qb,
            'a.secondaryTransport IS NULL',
            'a.secondaryTransport IS NOT NULL',
            'st.id',
            'secondaryTransportId',
        );

        $presentQb = $this->createMock(QueryBuilder::class);
        $presentQb->expects(self::once())->method('andWhere')->with('a.secondaryTransport IS NOT NULL')->willReturnSelf();

        OptionalRelationFilter::fromQuery('any')->apply(
            $presentQb,
            'a.secondaryTransport IS NULL',
            'a.secondaryTransport IS NOT NULL',
            'st.id',
            'secondaryTransportId',
        );

        $equalsQb = $this->createMock(QueryBuilder::class);
        $equalsQb->expects(self::once())->method('andWhere')->with('st.id = :secondaryTransportId')->willReturnSelf();
        $equalsQb->expects(self::once())->method('setParameter')->with('secondaryTransportId', 9)->willReturnSelf();

        OptionalRelationFilter::fromQuery('9')->apply(
            $equalsQb,
            'a.secondaryTransport IS NULL',
            'a.secondaryTransport IS NOT NULL',
            'st.id',
            'secondaryTransportId',
        );

        $unsetQb = $this->createMock(QueryBuilder::class);
        $unsetQb->expects(self::never())->method('andWhere');

        OptionalRelationFilter::fromQuery(null)->apply(
            $unsetQb,
            'a.secondaryTransport IS NULL',
            'a.secondaryTransport IS NOT NULL',
            'st.id',
            'secondaryTransportId',
        );
    }
}
