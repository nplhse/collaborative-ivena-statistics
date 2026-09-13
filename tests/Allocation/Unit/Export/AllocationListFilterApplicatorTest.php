<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Export;

use App\Allocation\Application\Export\AllocationListFilterApplicator;
use App\Allocation\Application\Export\DTO\AllocationListFilterCriteria;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AllocationListFilterApplicatorTest extends TestCase
{
    private AllocationListFilterApplicator $applicator;

    #[\Override]
    protected function setUp(): void
    {
        $this->applicator = new AllocationListFilterApplicator();
    }

    public function testAppliesSecondaryTransportAbsenceAndPresence(): void
    {
        $noneQb = $this->createQueryBuilderMock();
        $noneQb->expects(self::once())
            ->method('andWhere')
            ->with('a.secondaryTransport IS NULL');

        $this->applicator->apply($noneQb, new AllocationListFilterCriteria(secondaryTransport: 'none'));

        $anyQb = $this->createQueryBuilderMock();
        $anyQb->expects(self::once())
            ->method('andWhere')
            ->with('a.secondaryTransport IS NOT NULL');

        $this->applicator->apply($anyQb, new AllocationListFilterCriteria(secondaryTransport: 'any'));
    }

    public function testAppliesSecondaryTransportIdEquality(): void
    {
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::once())
            ->method('andWhere')
            ->with('st.id = :secondaryTransportId');
        $qb->expects(self::once())
            ->method('setParameter')
            ->with('secondaryTransportId', 15);

        $this->applicator->apply($qb, new AllocationListFilterCriteria(secondaryTransport: '15'));
    }

    public function testAppliesOccasionAbsenceAndIgnoresAny(): void
    {
        $noneQb = $this->createQueryBuilderMock();
        $noneQb->expects(self::once())
            ->method('andWhere')
            ->with('a.occasion IS NULL');

        $this->applicator->apply($noneQb, new AllocationListFilterCriteria(occasion: 'none'));

        $anyQb = $this->createQueryBuilderMock();
        $anyQb->expects(self::never())->method('andWhere');

        $this->applicator->apply($anyQb, new AllocationListFilterCriteria(occasion: 'any'));
    }

    public function testAppliesSecondaryIndicationAbsenceIgnoresAnyAndEqualsId(): void
    {
        $noneQb = $this->createQueryBuilderMock();
        $noneQb->expects(self::once())
            ->method('andWhere')
            ->with('a.secondaryIndicationNormalized IS NULL');

        $this->applicator->apply($noneQb, new AllocationListFilterCriteria(secondaryIndication: 'none'));

        $anyQb = $this->createQueryBuilderMock();
        $anyQb->expects(self::never())->method('andWhere');

        $this->applicator->apply($anyQb, new AllocationListFilterCriteria(secondaryIndication: 'any'));

        $equalsQb = $this->createQueryBuilderMock();
        $equalsQb->expects(self::once())
            ->method('andWhere')
            ->with('IDENTITY(a.secondaryIndicationNormalized) = :secondaryIndicationNormalizedId');
        $equalsQb->expects(self::once())
            ->method('setParameter')
            ->with('secondaryIndicationNormalizedId', 21);

        $this->applicator->apply($equalsQb, new AllocationListFilterCriteria(secondaryIndication: '21'));
    }

    public function testAppliesInfectiousAbsence(): void
    {
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::once())
            ->method('andWhere')
            ->with('a.infection IS NULL');

        $this->applicator->apply($qb, new AllocationListFilterCriteria(isInfectious: 0));
    }

    public function testAppliesCreatedAtRange(): void
    {
        $from = new \DateTimeImmutable('2026-03-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2026-04-01 00:00:00');
        $qb = $this->createQueryBuilderMock();
        $qb->expects(self::exactly(2))
            ->method('andWhere')
            ->willReturnCallback(static function (string $where) use ($qb): QueryBuilder {
                self::assertContains($where, ['a.createdAt >= :createdFrom', 'a.createdAt < :createdToExclusive']);

                return $qb;
            });

        $this->applicator->apply($qb, new AllocationListFilterCriteria(
            createdFrom: $from,
            createdToExclusive: $toExclusive,
        ));
    }

    /**
     * @return QueryBuilder&MockObject
     */
    private function createQueryBuilderMock(): QueryBuilder
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        return $qb;
    }
}
