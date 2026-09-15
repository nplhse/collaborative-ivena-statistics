<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure;

use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\ReadOnlyAssociationReferencer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

final class ReadOnlyAssociationReferencerTest extends TestCase
{
    public function testMarksDoctrineReferenceReadOnly(): void
    {
        $entity = new Import();

        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects(self::once())->method('markReadOnly')->with($entity);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('getReference')
            ->with(Import::class, 5)
            ->willReturn($entity);
        $em->method('getUnitOfWork')->willReturn($uow);

        $referencer = new ReadOnlyAssociationReferencer($em);

        self::assertSame($entity, $referencer->readOnlyReference(Import::class, 5));
    }
}
