<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportApplier;
use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportPlan;
use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportPlanner;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogExporter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\ReferenceCatalog\ReferenceCatalogPackageData as Data;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ReferenceCatalogImportTest extends KernelTestCase
{
    use Factories;

    public function testImportsACompletePackageWithItsIdsAndCuratedFields(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'importer']);

        $plan = $this->plan(Data::complete());
        self::assertSame([], $plan->conflicts);
        self::assertSame(['create' => 1, 'skip' => 0], $plan->counts['department-alias']);
        $this->applier()->apply($plan, $user);
        $this->em()->clear();

        $hospital = $this->em()->getRepository(Hospital::class)->findOneBy(['publicId' => Uuid::fromString(Data::HOSPITAL)]);
        self::assertInstanceOf(Hospital::class, $hospital);
        self::assertSame(Data::STATE, $hospital->getState()?->getPublicIdString());
        self::assertSame(Data::AREA, $hospital->getDispatchArea()?->getPublicIdString());
        self::assertFalse($hospital->isParticipating());
        self::assertNull($hospital->getOwner());
        self::assertSame(50.11, $hospital->getLatitude());
        self::assertSame('60311', $hospital->getAddress()->getPostalCode());

        $raw = $this->em()->getRepository(IndicationRaw::class)->findOneBy(['publicId' => Uuid::fromString(Data::RAW)]);
        self::assertInstanceOf(IndicationRaw::class, $raw);
        self::assertSame(Data::NORMALIZED, $raw->getTarget()?->getPublicIdString());
        self::assertSame(Data::NORMALIZED, $raw->getNormalized()?->getPublicIdString(), 'normalized is a copy of the target');
        self::assertSame(IndicationRawReviewStatus::Matched, $raw->getReviewStatus());
        self::assertSame('importer', $raw->getReviewedBy()?->getUserIdentifier());
        self::assertSame('2026-09-01T12:00:00+02:00', $raw->getReviewedAt()?->format(\DateTimeInterface::ATOM));
        self::assertNull($raw->getFirstMatchedBy());

        $unmapped = $this->em()->getRepository(IndicationRaw::class)->findOneBy(['publicId' => Uuid::fromString(Data::UNMAPPED_RAW)]);
        self::assertInstanceOf(IndicationRaw::class, $unmapped);
        self::assertNull($unmapped->getTarget());
        self::assertNull($unmapped->getReviewedBy());

        $group = $this->em()->getRepository(IndicationGroup::class)->findOneBy(['publicId' => Uuid::fromString(Data::GROUP)]);
        self::assertInstanceOf(IndicationGroup::class, $group);
        self::assertSame([Data::NORMALIZED], array_map(static fn (IndicationNormalized $member): string => $member->getPublicIdString(), $group->getIndications()->toArray()), 'exact members, not every row with code 143');

        $alias = $this->em()->getRepository(DepartmentAlias::class)->findOneBy(['name' => 'Allgemein Innere Medizin']);
        self::assertInstanceOf(DepartmentAlias::class, $alias);
        self::assertSame(Data::DEPARTMENT, $alias->getDepartment()->getPublicIdString());
        self::assertSame('typo in source', $alias->getNote());
    }

    public function testASecondImportCreatesNothing(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $this->applier()->apply($this->plan(Data::complete()), $user);

        $again = $this->plan(Data::complete());

        self::assertSame(0, $again->createCount());
        self::assertSame([], $again->conflicts);
        self::assertSame(['create' => 0, 'skip' => 1], $again->counts['hospital']);
        self::assertSame(['create' => 0, 'skip' => 1], $again->counts['department-alias']);
        self::assertSame(2, $this->em()->getRepository(State::class)->count([]));
    }

    public function testSameNameWithAnotherIdIsAConflict(): void
    {
        self::bootKernel();
        StateFactory::createOne(['name' => 'Hessen']);

        $plan = $this->plan(Data::complete());

        self::assertStringContainsString('same state (name) with another id', $this->messagesByPath($plan)['states[0]']);
    }

    public function testSameIdWithAnotherNameIsAConflict(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        DepartmentFactory::new()
            ->afterInstantiate(static function (Department $department): void {
                $department->setPublicId(Uuid::fromString(Data::DEPARTMENT));
            })
            ->create(['name' => 'Kardiologie']);

        $plan = $this->plan(Data::complete());

        self::assertStringContainsString('this id for another department', $this->messagesByPath($plan)['departments[0].id']);
    }

    public function testAliasOfAnotherDepartmentInTheDatabaseIsAConflict(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        $other = DepartmentFactory::createOne(['name' => 'Innere Medizin']);
        $this->em()->persist(new DepartmentAlias($other, 'Allgemein Innere Medizin', 'historical', 'test'));
        $this->em()->flush();

        $plan = $this->plan(Data::complete());

        self::assertStringContainsString('belongs to another department', $this->messagesByPath($plan)['departments[0].aliases[0].name']);
    }

    public function testANewNameThatIsAnAliasOfAnotherDepartmentIsAConflict(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        $other = DepartmentFactory::createOne(['name' => 'Innere']);
        $this->em()->persist(new DepartmentAlias($other, 'Allgemeine Innere Medizin', 'historical', 'test'));
        $this->em()->flush();

        $plan = $this->plan(Data::complete());

        self::assertStringContainsString('is an alias of another department', $this->messagesByPath($plan)['departments[0].name']);
    }

    public function testAnAliasThatIsTheNameOfAnotherDepartmentIsAConflict(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        DepartmentFactory::createOne(['name' => 'Allgemein Innere Medizin']);

        $plan = $this->plan(Data::complete());

        self::assertStringContainsString('is the name of another department', $this->messagesByPath($plan)['departments[0].aliases[0].name']);
    }

    public function testAHospitalInAnAreaOfAnotherStateIsAConflict(): void
    {
        self::bootKernel();
        UserFactory::createOne();
        $hessen = StateFactory::new()
            ->afterInstantiate(static function (State $state): void {
                $state->setPublicId(Uuid::fromString(Data::STATE));
            })
            ->create(['name' => 'Hessen']);
        $bayern = StateFactory::createOne(['name' => 'Bayern']);
        DispatchAreaFactory::new()
            ->afterInstantiate(static function (DispatchArea $area): void {
                $area->setPublicId(Uuid::fromString(Data::AREA));
            })
            ->create(['name' => 'Frankfurt', 'state' => $bayern]);
        self::assertSame(Data::STATE, $hessen->getPublicIdString());

        $plan = $this->plan($this->hospitalsOnly(Data::complete()));

        self::assertSame('Dispatch area belongs to another state than the hospital.', $this->messagesByPath($plan)['hospitals[0].area']);
    }

    public function testAPartialPackageRefersToRowsInTheDatabase(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $state = StateFactory::new()
            ->afterInstantiate(static function (State $state): void {
                $state->setPublicId(Uuid::fromString(Data::STATE));
            })
            ->create(['name' => 'Hessen']);
        DispatchAreaFactory::new()
            ->afterInstantiate(static function (DispatchArea $area): void {
                $area->setPublicId(Uuid::fromString(Data::AREA));
            })
            ->create(['name' => 'Frankfurt', 'state' => $state]);

        $plan = $this->plan($this->hospitalsOnly(Data::complete()));
        self::assertSame([], $plan->conflicts);
        $this->applier()->apply($plan, $user);

        self::assertSame(1, $this->em()->getRepository(Hospital::class)->count([]));
    }

    public function testAReferenceToAMissingRowIsAConflict(): void
    {
        self::bootKernel();

        $plan = $this->plan($this->hospitalsOnly(Data::complete()));

        $messages = $this->messagesByPath($plan);
        self::assertStringContainsString('neither in the package nor in the database', $messages['hospitals[0].state']);
        self::assertStringContainsString('neither in the package nor in the database', $messages['hospitals[0].area']);
        self::assertSame(0, $plan->counts['hospital']['create']);
    }

    public function testAPlanWithConflictsIsNeverWritten(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        StateFactory::createOne(['name' => 'Hessen']);
        $plan = $this->plan(Data::complete());
        self::assertNotSame([], $plan->conflicts);

        foreach ([fn () => $this->applier()->apply($plan, $user), fn () => $this->applier()->write($plan, $user)] as $attempt) {
            try {
                $attempt();
                self::fail('A plan with conflicts must be refused.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('conflicts', $exception->getMessage());
            }
        }

        self::assertSame(1, $this->em()->getRepository(State::class)->count([]));
    }

    public function testAnEmptyPlanLeavesTheCacheAlone(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $this->applier()->apply($this->plan(Data::complete()), $user);
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('cache.allocation.reference_data');
        $cache->save($cache->getItem('filter-options')->set(['current']));

        $this->applier()->apply($this->plan(Data::complete()), $user);

        self::assertTrue($cache->getItem('filter-options')->isHit());
    }

    public function testApplyClearsTheReferenceDataCache(): void
    {
        self::bootKernel();
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('cache.allocation.reference_data');
        $cache->save($cache->getItem('filter-options')->set(['stale']));

        $this->applier()->apply($this->plan(Data::complete()), UserFactory::createOne());

        self::assertFalse($cache->getItem('filter-options')->isHit());
    }

    public function testExportImportExportGivesTheSamePackage(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne();
        $this->applier()->apply($this->plan(Data::complete()), $user);
        $this->em()->clear();

        /** @var ReferenceCatalogExporter $exporter */
        $exporter = self::getContainer()->get(ReferenceCatalogExporter::class);
        $first = $this->writer()->encode($exporter->export(ReferenceCatalogType::importOrder()));

        $this->emptyCatalog();
        $user = $this->em()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);
        $read = $this->reader()->readJson($first);
        self::assertNotNull($read->package);
        $plan = $this->planner()->plan($read->package);
        self::assertSame([], $plan->conflicts);
        $this->applier()->apply($plan, $user);
        $this->em()->clear();

        $second = $this->writer()->encode($exporter->export(ReferenceCatalogType::importOrder()));

        self::assertSame($this->withoutExportTime($first), $this->withoutExportTime($second));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function plan(array $data): ReferenceCatalogImportPlan
    {
        return $this->planner()->plan($this->package($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function package(array $data): ReferenceCatalogPackage
    {
        /** @var DenormalizerInterface $denormalizer */
        $denormalizer = self::getContainer()->get(DenormalizerInterface::class);
        /** @var ReferenceCatalogChecksum $checksum */
        $checksum = self::getContainer()->get(ReferenceCatalogChecksum::class);

        $result = $this->reader()->readJson(json_encode(Data::withChecksum(Data::withCounts($data), $denormalizer, $checksum), \JSON_THROW_ON_ERROR));
        self::assertSame([], $result->violations);
        self::assertNotNull($result->package);

        return $result->package;
    }

    /**
     * @param array<string, mixed> $full
     *
     * @return array<string, mixed>
     */
    private function hospitalsOnly(array $full): array
    {
        /** @var array<string, mixed> $meta */
        $meta = $full['meta'];

        return [
            'meta' => ['types' => ['hospital'], 'complete' => false] + $meta,
            'hospitals' => $full['hospitals'],
        ];
    }

    private function emptyCatalog(): void
    {
        $em = $this->em();
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM department_alias');
        $connection->executeStatement('DELETE FROM speciality_alias');
        $connection->executeStatement('DELETE FROM indication_group_indication_normalized');
        $connection->executeStatement('DELETE FROM indication_group');
        $connection->executeStatement('DELETE FROM indication_raw');
        $connection->executeStatement('DELETE FROM indication_normalized');
        foreach (['hospital', 'dispatch_area', 'department', 'speciality', 'assignment', 'occasion', 'infection', 'secondary_transport', 'state'] as $table) {
            $connection->executeStatement(sprintf('DELETE FROM %s', $table));
        }
        $em->clear();
    }

    private function withoutExportTime(string $json): string
    {
        return (string) preg_replace('/"exported_at": "[^"]*"/', '"exported_at": ""', $json);
    }

    /**
     * @return array<string, string>
     */
    private function messagesByPath(ReferenceCatalogImportPlan $plan): array
    {
        $messages = [];
        foreach ($plan->conflicts as $conflict) {
            self::assertInstanceOf(ReferenceCatalogViolation::class, $conflict);
            $messages[$conflict->path] = $conflict->message;
        }

        return $messages;
    }

    private function planner(): ReferenceCatalogImportPlanner
    {
        /** @var ReferenceCatalogImportPlanner $planner */
        $planner = self::getContainer()->get(ReferenceCatalogImportPlanner::class);

        return $planner;
    }

    private function applier(): ReferenceCatalogImportApplier
    {
        /** @var ReferenceCatalogImportApplier $applier */
        $applier = self::getContainer()->get(ReferenceCatalogImportApplier::class);

        return $applier;
    }

    private function reader(): ReferenceCatalogPackageReader
    {
        /** @var ReferenceCatalogPackageReader $reader */
        $reader = self::getContainer()->get(ReferenceCatalogPackageReader::class);

        return $reader;
    }

    private function writer(): ReferenceCatalogPackageWriter
    {
        /** @var ReferenceCatalogPackageWriter $writer */
        $writer = self::getContainer()->get(ReferenceCatalogPackageWriter::class);

        return $writer;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
