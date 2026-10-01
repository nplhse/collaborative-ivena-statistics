<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\SpecialityAlias;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Import\Application\Exception\ReferenceNotFoundException;
use App\Import\Infrastructure\Resolver\Strategy\SpecialityDepartmentReferenceStrategy;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class SpecialityDepartmentReferenceStrategyTest extends KernelTestCase
{
    private SpecialityDepartmentReferenceStrategy $strategy;

    protected function setUp(): void
    {
        self::bootKernel();

        UserFactory::createOne();
        $department = DepartmentFactory::createOne(['name' => 'Geburtshilfe']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([
            'Perinatalzentrum Level 1',
            'Perinatalzentrum Level 2',
            'Perinataler Schwerpunkt',
            'Geburtsklinik',
        ] as $alias) {
            $em->persist(new DepartmentAlias($department, $alias, 'historical', 'local-catalog'));
        }
        $em->flush();

        $this->strategy = self::getContainer()->get(SpecialityDepartmentReferenceStrategy::class);
        $this->strategy->warm();
    }

    #[DataProvider('obstetricsDepartmentAliasProvider')]
    public function testResolvesObstetricsDepartmentAliasesToGeburtshilfe(string $importValue): void
    {
        $allocation = new Allocation();

        $this->strategy->apply(
            $allocation,
            null,
            $importValue,
            false,
            static fn (?bool $v): bool => $v ?? false,
        );

        self::assertSame('Geburtshilfe', $allocation->getDepartment()?->getName());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function obstetricsDepartmentAliasProvider(): iterable
    {
        yield 'issue 125 Perinatalzentrum Level 1' => ['Perinatalzentrum Level 1'];
        yield 'issue 125 Perinataler Schwerpunkt' => ['Perinataler Schwerpunkt'];
        yield 'issue 125 Geburtsklinik' => ['Geburtsklinik'];
        yield 'perinatalzentrum level 2' => ['Perinatalzentrum Level 2'];
    }

    public function testUnknownDepartmentStillThrowsReferenceNotFoundException(): void
    {
        $allocation = new Allocation();

        $this->expectException(ReferenceNotFoundException::class);

        $this->strategy->apply(
            $allocation,
            null,
            'Unbekanntes Department',
            false,
            static fn (?bool $v): bool => $v ?? false,
        );
    }

    public function testRequirePairResolvesKnownNames(): void
    {
        $speciality = SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new SpecialityAlias($speciality, 'Innere', 'historical', 'local-catalog'));
        $em->flush();
        $this->strategy->warm();

        $pair = $this->strategy->requirePair('Innere', 'Kardiologie');

        self::assertSame('Innere Medizin', $pair['speciality']->getName());
        self::assertSame('Kardiologie', $pair['department']->getName());
    }

    public function testRequirePairResolvesCanonicalNames(): void
    {
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        $this->strategy->warm();

        $pair = $this->strategy->requirePair('Innere Medizin', 'Kardiologie');

        self::assertSame('Innere Medizin', $pair['speciality']->getName());
        self::assertSame('Kardiologie', $pair['department']->getName());
    }

    public function testRequirePairRejectsUnknownSpeciality(): void
    {
        $this->expectException(ReferenceNotFoundException::class);

        $this->strategy->requirePair('Unbekanntes Fachgebiet', 'Geburtshilfe');
    }

    public function testWarmRejectsAliasThatCollidesWithAnotherDepartment(): void
    {
        $other = DepartmentFactory::createOne(['name' => 'Gynäkologie']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new DepartmentAlias($other, 'Geburtshilfe', 'historical', 'local-catalog'));
        $em->flush();

        $this->expectException(\DomainException::class);
        $this->strategy->warm();
    }

    public function testWarmIgnoresABlankCanonicalName(): void
    {
        SpecialityFactory::createOne(['name' => '   ']);
        $this->strategy->warm();

        $this->expectException(ReferenceNotFoundException::class);
        $this->strategy->requirePair('   ', 'Kardiologie');
    }

    public function testWarmRejectsASpecialityAliasWhoseOwnerHasNoId(): void
    {
        $owner = $this->createStub(Speciality::class);
        $owner->method('getId')->willReturn(null);
        $alias = $this->createStub(SpecialityAlias::class);
        $alias->method('getSpeciality')->willReturn($owner);
        $alias->method('getNormalizedName')->willReturn('innere');
        $alias->method('getName')->willReturn('Innere');

        $this->expectException(\DomainException::class);
        $this->strategyWithAlias(SpecialityAlias::class, $alias)->warm();
    }

    public function testWarmRejectsADepartmentAliasWhoseOwnerHasNoId(): void
    {
        $owner = $this->createStub(Department::class);
        $owner->method('getId')->willReturn(null);
        $alias = $this->createStub(DepartmentAlias::class);
        $alias->method('getDepartment')->willReturn($owner);
        $alias->method('getNormalizedName')->willReturn('kardiologie');
        $alias->method('getName')->willReturn('Kardiologie');

        $this->expectException(\DomainException::class);
        $this->strategyWithAlias(DepartmentAlias::class, $alias)->warm();
    }

    private function strategyWithAlias(string $aliasClass, object $alias): SpecialityDepartmentReferenceStrategy
    {
        $aliasRepository = $this->createStub(EntityRepository::class);
        $aliasRepository->method('findBy')->willReturn([$alias]);
        $emptyRepository = $this->createStub(EntityRepository::class);
        $emptyRepository->method('findBy')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => $class === $aliasClass ? $aliasRepository : $emptyRepository,
        );

        return new SpecialityDepartmentReferenceStrategy(
            self::getContainer()->get(SpecialityRepository::class),
            self::getContainer()->get(DepartmentRepository::class),
            $entityManager,
        );
    }
}
