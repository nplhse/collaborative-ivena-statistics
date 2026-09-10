<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportExportReferenceCatalogCommandTest extends KernelTestCase
{
    use Factories;

    public function testAddImportsMissingRowsSkipsAreaWithoutStateAndIsIdempotent(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog($this->minimalCatalog());

        $tester = $this->commandTester('app:reference:import');
        $first = $tester->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
        ]);

        self::assertSame(Command::SUCCESS, $first);
        self::assertStringContainsString('state is empty', $tester->getDisplay());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(State::class, $em->getRepository(State::class)->findOneBy(['name' => 'Hessen']));
        self::assertInstanceOf(DispatchArea::class, $em->getRepository(DispatchArea::class)->findOneBy(['name' => 'Frankfurt']));
        self::assertNull($em->getRepository(DispatchArea::class)->findOneBy(['name' => 'Göttingen']));
        self::assertInstanceOf(Department::class, $em->getRepository(Department::class)->findOneBy(['name' => 'Kardiologie']));
        self::assertInstanceOf(Occasion::class, $em->getRepository(Occasion::class)->findOneBy(['name' => 'aus Klinik']));

        $second = $tester->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
        ]);
        self::assertSame(Command::SUCCESS, $second);
        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
        self::assertSame(1, $em->getRepository(State::class)->count([]));
        self::assertSame(1, $em->getRepository(DispatchArea::class)->count([]));
    }

    public function testReplaceAbortsWhenAllocationsExist(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $hospital = HospitalFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
        AllocationFactory::createOne([
            'hospital' => $hospital,
            'dispatchArea' => $hospital->getDispatchArea(),
            'state' => $hospital->getState(),
            'import' => ImportFactory::createOne(['hospital' => $hospital]),
        ]);
        $source = $this->writeCatalog($this->minimalCatalog());

        $tester = $this->commandTester('app:reference:import');
        $exitCode = $tester->execute([
            '--source' => $source,
            '--mode' => 'replace',
            '--user' => 'admin',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Replace aborted', $tester->getDisplay());
    }

    public function testReplaceLoadsCatalogOnEmptyInstall(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog($this->minimalCatalog());

        $tester = $this->commandTester('app:reference:import');
        $exitCode = $tester->execute([
            '--source' => $source,
            '--mode' => 'replace',
            '--user' => 'admin',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getRepository(State::class)->count([]));
        self::assertSame(1, $em->getRepository(DispatchArea::class)->count([]));
        self::assertSame('Frankfurt', $em->getRepository(DispatchArea::class)->findBy([])[0]->getName());
    }

    public function testExportWritesCurrentCatalog(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog($this->minimalCatalog());
        $this->commandTester('app:reference:import')->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
        ]);

        $output = sys_get_temp_dir().'/catalog-export-'.bin2hex(random_bytes(4)).'.yaml';
        $exitCode = $this->commandTester('app:reference:export')->execute([
            '--output' => $output,
            '--types' => 'state,dispatch-area,department,occasion',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($output);
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($output);
        self::assertSame(['Hessen'], $parsed['states']);
        self::assertSame('Frankfurt', $parsed['dispatch_areas'][0]['name'] ?? null);
        self::assertContains('Kardiologie', $parsed['departments']);
        self::assertContains('aus Klinik', $parsed['occasions']);
        self::assertSame([], $parsed['hospitals']);
    }

    public function testDryRunDoesNotPersist(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog($this->minimalCatalog());

        $exitCode = $this->commandTester('app:reference:import')->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(State::class)->count([]));
        self::assertSame(0, $em->getRepository(Department::class)->count([]));
    }

    public function testReplaceDryRunDoesNotPurgeOrPersist(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog($this->minimalCatalog());
        $this->commandTester('app:reference:import')->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
        ]);

        $other = $this->writeCatalog([
            'states' => ['Thüringen'],
            'dispatch_areas' => [
                ['name' => 'Eichsfeld', 'state' => 'Thüringen'],
            ],
        ]);
        $tester = $this->commandTester('app:reference:import');
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--source' => $other,
            '--mode' => 'replace',
            '--user' => 'admin',
            '--dry-run' => true,
        ]));
        self::assertStringContainsString('Dry run finished', $tester->getDisplay());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(State::class, $em->getRepository(State::class)->findOneBy(['name' => 'Hessen']));
        self::assertNull($em->getRepository(State::class)->findOneBy(['name' => 'Thüringen']));
    }

    public function testReplaceWithTypesSubsetFails(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog($this->minimalCatalog());

        $tester = $this->commandTester('app:reference:import');
        $exitCode = $tester->execute([
            '--source' => $source,
            '--mode' => 'replace',
            '--types' => 'state',
            '--user' => 'admin',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Replace mode reloads the full catalog', $tester->getDisplay());
    }

    public function testUnknownTypeAndMissingUserFail(): void
    {
        $source = $this->writeCatalog($this->minimalCatalog());
        $tester = $this->commandTester('app:reference:import');

        self::assertSame(Command::FAILURE, $tester->execute([
            '--source' => $source,
            '--types' => 'widget',
        ]));
        self::assertStringContainsString('Unknown catalog type', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute([
            '--source' => $source,
            '--user' => 'nobody-here',
        ]));
        self::assertStringContainsString('No user found', $tester->getDisplay());
    }

    public function testAddCreatesHospitalThenSkipsExistingName(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog($this->hospitalCatalog(participating: true, beds: 120));

        $tester = $this->commandTester('app:reference:import');
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
        ]));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hospital = $em->getRepository(Hospital::class)->findOneBy(['name' => 'Catalog Test Klinikum']);
        self::assertInstanceOf(Hospital::class, $hospital);
        self::assertTrue($hospital->isParticipating());
        self::assertSame(120, $hospital->getBeds());

        $second = $this->writeCatalog($this->hospitalCatalog(participating: false, beds: 9));
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--source' => $second,
            '--mode' => 'add',
            '--user' => 'admin',
        ]));

        $em->clear();
        $unchanged = $em->getRepository(Hospital::class)->findOneBy(['name' => 'Catalog Test Klinikum']);
        self::assertInstanceOf(Hospital::class, $unchanged);
        self::assertTrue($unchanged->isParticipating());
        self::assertSame(120, $unchanged->getBeds());
        self::assertSame(1, $em->getRepository(Hospital::class)->count([]));

        $output = sys_get_temp_dir().'/catalog-export-'.bin2hex(random_bytes(4)).'.yaml';
        self::assertSame(Command::SUCCESS, $this->commandTester('app:reference:export')->execute([
            '--output' => $output,
            '--types' => 'hospital',
        ]));
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($output);
        self::assertSame('Catalog Test Klinikum', $parsed['hospitals'][0]['name'] ?? null);
        self::assertSame(120, $parsed['hospitals'][0]['beds'] ?? null);
        self::assertTrue($parsed['hospitals'][0]['participating'] ?? false);
    }

    public function testAddCreatesIndicationGroup(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog([
            'indications_normalized' => [
                ['code' => '143', 'name' => 'vaECMO Abholung'],
            ],
            'indication_groups' => [
                ['name' => 'ECMO Test Group', 'category' => 'Kardiologie', 'codes' => ['143']],
            ],
        ]);

        $exitCode = $this->commandTester('app:reference:import')->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
            '--types' => 'indication-normalized,indication-group',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $group = $em->getRepository(IndicationGroup::class)->findOneBy(['name' => 'ECMO Test Group']);
        self::assertInstanceOf(IndicationGroup::class, $group);
        self::assertSame('Kardiologie', $group->getCategory());
        self::assertCount(1, $group->getIndications());

        $skip = $this->commandTester('app:reference:import');
        self::assertSame(Command::SUCCESS, $skip->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
            '--types' => 'indication-group',
        ]));
        self::assertStringContainsString('Nothing to do', $skip->getDisplay());
        $em->clear();
        $unchanged = $em->getRepository(IndicationGroup::class)->findOneBy(['name' => 'ECMO Test Group']);
        self::assertInstanceOf(IndicationGroup::class, $unchanged);
        self::assertSame('Kardiologie', $unchanged->getCategory());

        $output = sys_get_temp_dir().'/catalog-export-'.bin2hex(random_bytes(4)).'.yaml';
        self::assertSame(Command::SUCCESS, $this->commandTester('app:reference:export')->execute([
            '--output' => $output,
            '--types' => 'indication-group',
        ]));
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($output);
        self::assertSame('ECMO Test Group', $parsed['indication_groups'][0]['name'] ?? null);
        self::assertContains('143', $parsed['indication_groups'][0]['codes'] ?? []);
    }

    public function testUpdateIndicationGroupChangesCategory(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog([
            'indications_normalized' => [
                ['code' => '143', 'name' => 'vaECMO Abholung'],
            ],
            'indication_groups' => [
                ['name' => 'ECMO Test Group', 'category' => 'Kardiologie', 'codes' => ['143']],
            ],
        ]);
        $this->commandTester('app:reference:import')->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
            '--types' => 'indication-normalized,indication-group',
        ]);

        $updated = $this->writeCatalog([
            'indication_groups' => [
                ['name' => 'ECMO Test Group', 'category' => 'Intensivmedizin', 'codes' => ['143', '999']],
            ],
        ]);
        $tester = $this->commandTester('app:reference:import');
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--source' => $updated,
            '--mode' => 'add',
            '--user' => 'admin',
            '--types' => 'indication-group',
            '--update' => true,
        ]));
        self::assertStringContainsString('no normalized indication for code 999', $tester->getDisplay());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $group = $em->getRepository(IndicationGroup::class)->findOneBy(['name' => 'ECMO Test Group']);
        self::assertInstanceOf(IndicationGroup::class, $group);
        self::assertSame('Intensivmedizin', $group->getCategory());
        self::assertCount(1, $group->getIndications());
    }

    public function testSkipsDispatchAreaWhenStateMissingAndHospitalWhenAreaMissing(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog([
            'states' => ['Hessen'],
            'dispatch_areas' => [
                ['name' => 'Northeim', 'state' => 'Niedersachsen'],
            ],
            'hospitals' => [[
                'name' => 'Orphan Klinikum',
                'state' => 'Hessen',
                'area' => 'Missing Area',
                'participating' => false,
                'tier' => 'Basic',
                'size' => 'Small',
                'beds' => 10,
                'location' => 'Urban',
                'address' => [
                    'street' => 'Teststraße 1',
                    'city' => 'Frankfurt am Main',
                    'state' => 'Hessen',
                    'postalCode' => '60311',
                    'country' => 'Deutschland',
                ],
            ]],
        ]);

        $tester = $this->commandTester('app:reference:import');
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
        ]));
        self::assertStringContainsString('state "Niedersachsen" does not exist', $tester->getDisplay());
        self::assertStringContainsString('area "Missing Area" not found', $tester->getDisplay());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(DispatchArea::class)->findOneBy(['name' => 'Northeim']));
        self::assertNull($em->getRepository(Hospital::class)->findOneBy(['name' => 'Orphan Klinikum']));
    }

    public function testIndicationRawIsSkippedByHashAndExported(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $source = $this->writeCatalog([
            'indications_normalized' => [
                ['code' => '143', 'name' => 'vaECMO Abholung'],
            ],
            'indications_raw' => [
                ['code' => '143', 'name' => 'vaECMO Abholung'],
            ],
        ]);

        $tester = $this->commandTester('app:reference:import');
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
            '--types' => 'indication-normalized,indication-raw',
        ]));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getRepository(IndicationRaw::class)->count([]));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--source' => $source,
            '--mode' => 'add',
            '--user' => 'admin',
            '--types' => 'indication-raw',
        ]));
        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
        self::assertSame(1, $em->getRepository(IndicationRaw::class)->count([]));

        $output = sys_get_temp_dir().'/catalog-export-'.bin2hex(random_bytes(4)).'.yaml';
        self::assertSame(Command::SUCCESS, $this->commandTester('app:reference:export')->execute([
            '--output' => $output,
            '--types' => 'indication-normalized,indication-raw,hospital',
        ]));
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($output);
        self::assertSame('143', $parsed['indications_normalized'][0]['code'] ?? null);
        self::assertSame('vaECMO Abholung', $parsed['indications_raw'][0]['name'] ?? null);
        self::assertSame([], $parsed['hospitals']);
    }

    public function testMissingCatalogFileAndUnknownExportTypeFail(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $tester = $this->commandTester('app:reference:import');
        self::assertSame(Command::FAILURE, $tester->execute([
            '--source' => sys_get_temp_dir().'/missing-catalog-'.bin2hex(random_bytes(4)).'.yaml',
            '--user' => 'admin',
        ]));
        self::assertStringContainsString('not found', $tester->getDisplay());

        $export = $this->commandTester('app:reference:export');
        self::assertSame(Command::FAILURE, $export->execute([
            '--output' => sys_get_temp_dir().'/catalog-export-'.bin2hex(random_bytes(4)).'.yaml',
            '--types' => 'widget',
        ]));
        self::assertStringContainsString('Unknown catalog type', $export->getDisplay());
    }

    /**
     * @param array<string, mixed> $catalog
     */
    private function writeCatalog(array $catalog): string
    {
        $path = sys_get_temp_dir().'/catalog-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($path, Yaml::dump($catalog, 8, 2));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalCatalog(): array
    {
        return [
            'states' => ['Hessen'],
            'dispatch_areas' => [
                ['name' => 'Frankfurt', 'state' => 'Hessen'],
                ['name' => 'Göttingen', 'state' => null],
            ],
            'departments' => ['Kardiologie'],
            'occasions' => ['aus Klinik'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hospitalCatalog(bool $participating, int $beds): array
    {
        return [
            'states' => ['Hessen'],
            'dispatch_areas' => [
                ['name' => 'Frankfurt', 'state' => 'Hessen'],
            ],
            'hospitals' => [[
                'name' => 'Catalog Test Klinikum',
                'state' => 'Hessen',
                'area' => 'Frankfurt',
                'participating' => $participating,
                'tier' => 'Basic',
                'size' => 'Small',
                'beds' => $beds,
                'location' => 'Urban',
                'address' => [
                    'street' => 'Teststraße 1',
                    'city' => 'Frankfurt am Main',
                    'state' => 'Hessen',
                    'postalCode' => '60311',
                    'country' => 'Deutschland',
                ],
            ]],
        ];
    }

    private function commandTester(string $name): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find($name));
    }
}
