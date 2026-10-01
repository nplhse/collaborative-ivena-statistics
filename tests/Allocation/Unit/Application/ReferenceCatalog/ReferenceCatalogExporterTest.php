<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogExporter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\SpecialityAlias;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ReferenceCatalogExporterTest extends TestCase
{
    public function testExportSkipsAliasesWithoutAnOwnerIdAndRowsThatAreNotNameLookups(): void
    {
        $owner = new Speciality()->setName('Chirurgie');
        $alias = new SpecialityAlias($owner, 'Chir', 'historical', 'local-catalog');
        $unnamed = new \stdClass();
        $withoutId = new class {
            public function getName(): string
            {
                return 'Ohne Id';
            }

            public function getId(): null
            {
                return null;
            }
        };

        $aliasRepository = $this->createStub(EntityRepository::class);
        $aliasRepository->method('findBy')->willReturn([$unnamed, $alias]);
        $specialityRepository = $this->createStub(EntityRepository::class);
        $specialityRepository->method('findBy')->willReturn([$withoutId]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => SpecialityAlias::class === $class
                ? $aliasRepository
                : $specialityRepository,
        );

        $document = new ReferenceCatalogExporter($entityManager)->export([ReferenceCatalogType::Speciality]);

        self::assertSame([], $document->specialities);
    }

    public function testExportRejectsAnEntityThatIsNotANameLookup(): void
    {
        $aliasRepository = $this->createStub(EntityRepository::class);
        $aliasRepository->method('findBy')->willReturn([]);
        $specialityRepository = $this->createStub(EntityRepository::class);
        $specialityRepository->method('findBy')->willReturn([new \stdClass()]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => SpecialityAlias::class === $class
                ? $aliasRepository
                : $specialityRepository,
        );

        $this->expectException(\LogicException::class);

        new ReferenceCatalogExporter($entityManager)->export([ReferenceCatalogType::Speciality]);
    }
}
