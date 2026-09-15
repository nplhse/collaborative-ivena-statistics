<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Mapping;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\ImportCreatedById;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use App\Import\Infrastructure\ReadOnlyAssociationReferencer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

final class AllocationImportFactoryGuardsTest extends TestCase
{
    public function testFromDtoThrowsWhenImportHasNoHospital(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Import has no hospital assigned');

        $this->factory()->fromDto(new AllocationRowDTO(), new Import());
    }

    public function testFromDtoThrowsWhenHospitalHasNoId(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Import hospital has no id assigned');

        $import = new Import()->setHospital(new Hospital());
        $this->factory()->fromDto(new AllocationRowDTO(), $import);
    }

    public function testFromDtoThrowsWhenImportHasNoId(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Import has no id assigned');

        $hospital = new Hospital();
        $this->setId($hospital, 1);
        $import = new Import()->setHospital($hospital);

        $this->factory()->fromDto(new AllocationRowDTO(), $import);
    }

    private function factory(): AllocationImportFactory
    {
        $uow = $this->createStub(UnitOfWork::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getReference')->willReturnCallback(
            static fn (string $class, mixed $id): object => new $class(),
        );

        return new AllocationImportFactory(
            new ReadOnlyAssociationReferencer($em),
            new ImportCreatedById(),
            [],
        );
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionObject($entity);

        do {
            if ($ref->hasProperty('id')) {
                $prop = $ref->getProperty('id');
                $prop->setValue($entity, $id);

                return;
            }
            $ref = $ref->getParentClass();
        } while ($ref instanceof \ReflectionObject);

        self::fail('Entity has no id property.');
    }
}
