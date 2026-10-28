<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Catalog;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\DataFixtures\Generator\Application\Catalog\CatalogCompatibility;
use App\DataFixtures\Generator\Application\Catalog\CatalogLookupType;
use App\DataFixtures\Generator\Application\Catalog\CatalogMapping;
use App\DataFixtures\Generator\Application\Catalog\CatalogMatch;
use App\DataFixtures\Generator\Application\Catalog\CatalogMismatchException;
use App\DataFixtures\Generator\Application\Catalog\CatalogReport;
use App\DataFixtures\Generator\Application\Catalog\GeneratorCatalog;
use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Reference\SyntheticReferenceCatalogBuilder;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The generator on a catalog that is not the synthetic one (#875): the alternative catalog has other
 * labels, other public ids, another insert order and two normalized indications for one code.
 */
#[ResetDatabase]
final class InterchangeableCatalogTest extends KernelTestCase
{
    private const string UNTIL = '2026-09-30';

    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ivena-875-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['FIXTURES_REFERENCE_PACKAGE', 'FIXTURES_CATALOG_MAPPING', 'FIXTURES_UNTIL'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testTheComposedCatalogKeepsTheSyntheticHospitalsAndTakesTheOtherCatalog(): void
    {
        $this->bootWith($this->writePackage(false));
        $this->load(['reference']);

        $connection = $this->connection();
        /** @var SyntheticReferenceCatalogBuilder $builder */
        $builder = self::getContainer()->get(SyntheticReferenceCatalogBuilder::class);
        $synthetic = $builder->build();
        $expectedHospitals = array_map(static fn (\App\Allocation\Application\ReferenceCatalog\Package\HospitalEntry $hospital): string => $hospital->id, $synthetic->hospitals);
        sort($expectedHospitals);

        self::assertSame($expectedHospitals, $connection->fetchFirstColumn('SELECT public_id FROM hospital ORDER BY public_id'), 'hospitals stay synthetic');
        self::assertSame(24, (int) $connection->fetchOne('SELECT COUNT(*) FROM dispatch_area'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM department WHERE name = 'Stroke Unit'"));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM department WHERE name = 'Schlaganfallmedizin'"));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM assignment WHERE name = 'Rettungsdienst'"));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM indication_normalized WHERE code = 322'));
    }

    public function testTheCheckListsEveryNameTheCatalogLacks(): void
    {
        $this->bootWith($this->writePackage(false));
        $this->load(['reference']);

        $report = $this->compatibility()->check();

        self::assertFalse($report->isCompatible());
        // Missing names are reported by their key; the renamed labels are Allgemeinchirurgie, Geburtshilfe, Schlaganfallmedizin.
        self::assertSame(['general-surgery', 'obstetrics', 'stroke-unit'], $this->names($report, 'department', CatalogMatch::MISSING));
        self::assertSame(['paediatrics'], $this->names($report, 'speciality', CatalogMatch::MISSING));
        self::assertSame(['RD'], $this->names($report, 'assignment', CatalogMatch::MISSING));
        self::assertSame([], $this->names($report, 'occasion', CatalogMatch::MISSING));
        // The paediatric units are written with the missing speciality paediatrics (Pädiatrie).
        self::assertSame(
            ['general-paediatrics', 'neonatology', 'paediatric-cardiology', 'paediatric-gastroenterology'],
            $this->names($report, CatalogCompatibility::SPECIALITY_OF_DEPARTMENT, CatalogMatch::MISSING),
        );
        self::assertSame([], $this->names($report, CatalogCompatibility::INDICATION, CatalogMatch::MISSING));

        // The old name is an alias of the renamed department, so it resolves like in the import.
        $pulmology = $this->catalog()->match(CatalogLookupType::Department, 'Pulmologie');
        self::assertSame(['Pneumologie', 'alias'], [$pulmology->catalogName, $pulmology->via]);

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('indication "322": 2 normalized indications have this code', $report->warnings()[0]);
        self::assertCount(9, $report->errors(), '3 departments, 1 speciality, 1 assignment, 4 specialities of departments');
    }

    public function testWithTheMappingEveryNameResolves(): void
    {
        $this->bootWith($this->writePackage(false));
        $this->load(['reference']);
        $this->catalog()->useMapping(AlternativeCatalog::mapping());

        $report = $this->compatibility()->check();

        self::assertSame([], $report->errors());
        $catalog = $this->catalog();
        $stroke = $catalog->match(CatalogLookupType::Department, 'stroke-unit');
        self::assertSame(['Stroke Unit', 'mapping'], [$stroke->catalogName, $stroke->via]);
        self::assertSame('Innere Medizin', $catalog->matchSpecialityOf('stroke-unit')->catalogName, 'speciality_of_department wins over the field of the unit');
        self::assertSame('Kinder- und Jugendmedizin', $catalog->matchSpecialityOf('Neonatologie')->catalogName, 'the speciality key goes through names.speciality');
        self::assertSame('Neurologie', $catalog->matchSpecialityOf('Epileptologie')->catalogName);

        // By name, not by database id: "Kollaps unklarer Genese" sorts before "Synkope / Kollaps".
        $syncope = $catalog->matchIndication('322');
        self::assertSame([AlternativeCatalog::SECOND_NAME, AlternativeCatalog::SECOND_NAME], [$syncope->catalogName, $syncope->rawName]);
        // The raw label with the official wording wins over a generated variant.
        $chestPain = $catalog->matchIndication('331');
        self::assertSame($chestPain->catalogName, $chestPain->rawName);
    }

    public function testAPinnedIndicationAndUnknownMappingEntries(): void
    {
        $this->bootWith($this->writePackage(false));
        $this->load(['reference']);
        $this->catalog()->useMapping(new CatalogMapping(
            names: AlternativeCatalog::mapping()->names + ['infection' => ['Pest' => 'MRSA']],
            specialityOfDepartment: AlternativeCatalog::SPECIALITY_OF_DEPARTMENT + ['Herzchirurgie' => 'Chirurgie'],
            indications: ['322' => ['normalized' => 'Synkope / Kollaps'], '999' => ['normalized' => 'Unbekannt']],
        ));

        $report = $this->compatibility()->check();

        self::assertSame('Synkope / Kollaps', $this->catalog()->matchIndication('322')->catalogName);
        self::assertSame([], $report->warnings(), 'a pinned code is no longer ambiguous');
        self::assertSame([
            'mapping: names.infection."Pest" is not a infection the generator uses',
            'mapping: speciality_of_department."Herzchirurgie" is not a department the generator uses',
            'mapping: indications."999" is not a PZC code the generator uses',
        ], $report->errors());
    }

    public function testTheCheckCommandNamesTheProblemsAndAcceptsAMappingFile(): void
    {
        $this->bootWith($this->writePackage(false));
        $this->load(['reference']);
        $mapping = $this->directory.'/mapping.json';
        new Filesystem()->dumpFile($mapping, AlternativeCatalog::mappingJson());

        $without = $this->checkCommand([]);
        self::assertSame(Command::FAILURE, $without->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $without->getDisplay());
        self::assertStringContainsString('department "stroke-unit" is missing', (string) $display);
        self::assertStringContainsString('names.department', (string) $display, 'the fix is named');

        $with = $this->checkCommand(['--mapping' => $mapping]);
        self::assertSame(Command::SUCCESS, $with->getStatusCode(), $with->getDisplay());
        self::assertStringContainsString('322', $with->getDisplay(), 'the ambiguous code is a warning');
    }

    public function testAnAliasThatLeadsToTwoDepartmentsIsAmbiguous(): void
    {
        self::bootKernel();
        $this->load(['reference']);
        // An alias of Gastroenterologie that spells Kardiologie: the import could not tell them apart either.
        $connection = $this->connection();
        $connection->executeStatement(
            "INSERT INTO department_alias (id, department_id, name, normalized_name, classification, source)
             SELECT nextval('department_alias_id_seq'), id, 'KARDIOLOGIE', 'kardiologie', 'faulty_catalog', 'test' FROM department WHERE name = 'Gastroenterologie'",
        );

        $match = $this->catalog()->match(CatalogLookupType::Department, 'Kardiologie');

        self::assertSame(CatalogMatch::AMBIGUOUS, $match->status);
        self::assertSame(['Gastroenterologie', 'Kardiologie'], $match->candidates);
        $this->expectException(CatalogMismatchException::class);
        $this->compatibility()->assertCompatible();
    }

    public function testTheFixtureStopsBeforeTheFirstRowWhenTheCatalogDoesNotFit(): void
    {
        $this->bootWith($this->writePackage(false));

        try {
            $this->load(['allocations']);
            self::fail('The load must stop.');
        } catch (CatalogMismatchException $exception) {
            self::assertStringContainsString('department "stroke-unit" is missing', $exception->getMessage());
            self::assertStringContainsString('assignment "RD" is missing', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation'));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import'));
    }

    public function testTheGeneratorWritesTheMappedCatalogWhateverTheDatabaseIds(): void
    {
        $mapping = $this->directory.'/mapping.json';
        new Filesystem()->dumpFile($mapping, AlternativeCatalog::mappingJson());

        $this->bootWith($this->writePackage(false), $mapping);
        $this->load(['allocations']);
        $first = $this->describe();
        $this->assertWrittenWithTheAlternativeCatalog();

        // The same catalog inserted in reverse order: other database ids, same data.
        self::ensureKernelShutdown();
        $this->bootWith($this->writePackage(true), $mapping);
        $this->load(['allocations']);
        $second = $this->describe();

        self::assertNotSame([], $first);
        self::assertSame(md5(serialize($first)), md5(serialize($second)), 'same catalog content, same rows');
    }

    private function assertWrittenWithTheAlternativeCatalog(): void
    {
        $connection = $this->connection();
        /** @var list<string> $departments */
        $departments = $connection->fetchFirstColumn('SELECT DISTINCT d.name FROM allocation a INNER JOIN department d ON d.id = a.department_id ORDER BY d.name');
        self::assertContains('Stroke Unit', $departments);
        self::assertContains('Pneumologie', $departments);
        self::assertSame([], array_intersect($departments, ['Schlaganfallmedizin', 'Allgemeinchirurgie', 'Geburtshilfe', 'Pulmologie']));

        $back = array_flip(AlternativeCatalog::RENAMED_DEPARTMENTS + AlternativeCatalog::ALIASED_DEPARTMENTS);
        /** @var list<array{department: string, speciality: string}> $pairs */
        $pairs = [
            ...$connection->fetchAllAssociative('SELECT DISTINCT d.name AS department, s.name AS speciality FROM allocation a INNER JOIN department d ON d.id = a.department_id INNER JOIN speciality s ON s.id = a.speciality_id'),
            ...$connection->fetchAllAssociative('SELECT DISTINCT d.name AS department, s.name AS speciality FROM closure_interval c INNER JOIN department d ON d.id = c.department_id INNER JOIN speciality s ON s.id = c.speciality_id'),
        ];
        foreach ($pairs as $pair) {
            $unit = $back[$pair['department']] ?? $pair['department'];
            $field = AlternativeCatalog::SPECIALITY_OF_DEPARTMENT[GeneratorKeys::department($unit)] ?? MedicalSpecialities::fieldOfUnit($unit);
            self::assertSame(AlternativeCatalog::RENAMED_SPECIALITIES[$field] ?? $field, $pair['speciality'], $pair['department']);
            self::assertNotContains($pair['speciality'], array_keys(AlternativeCatalog::RENAMED_SPECIALITIES), 'old speciality names are gone');
        }

        /** @var list<string> $assignments */
        $assignments = $connection->fetchFirstColumn('SELECT DISTINCT s.name FROM allocation a INNER JOIN assignment s ON s.id = a.assignment_id');
        self::assertContains('Rettungsdienst', $assignments);
        self::assertNotContains('RD', $assignments);

        self::assertSame(
            [AlternativeCatalog::SECOND_NAME],
            $connection->fetchFirstColumn('SELECT DISTINCT n.name FROM allocation a INNER JOIN indication_normalized n ON n.id = a.indication_normalized_id WHERE n.code = 322'),
            'every 322 case uses the normalized indication first by name',
        );
    }

    /**
     * Every generated row by content: names and public ids, never database ids.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function describe(): array
    {
        $connection = $this->connection();

        return [
            'allocations' => $connection->fetchAllAssociative(
                'SELECT h.public_id AS hospital, a.created_at, a.arrival_at, a.gender, a.age, a.urgency, a.requires_resus,
                        a.requires_cathlab, a.is_cpr, a.department_was_closed, d.name AS department, s.name AS speciality,
                        n.name AS normalized, r.name AS raw, x.name AS assignment, o.name AS occasion
                 FROM allocation a
                 INNER JOIN hospital h ON h.id = a.hospital_id
                 INNER JOIN department d ON d.id = a.department_id
                 INNER JOIN speciality s ON s.id = a.speciality_id
                 INNER JOIN indication_raw r ON r.id = a.indication_raw_id
                 LEFT JOIN indication_normalized n ON n.id = a.indication_normalized_id
                 LEFT JOIN assignment x ON x.id = a.assignment_id
                 LEFT JOIN occasion o ON o.id = a.occasion_id
                 ORDER BY h.public_id, a.created_at, a.arrival_at, a.age, a.gender, d.name, r.name, a.urgency',
            ),
            'closures' => $connection->fetchAllAssociative(
                'SELECT h.public_id AS hospital, c.starts_at, c.ends_at, d.name AS department, s.name AS speciality
                 FROM closure_interval c
                 INNER JOIN hospital h ON h.id = c.hospital_id
                 INNER JOIN department d ON d.id = c.department_id
                 INNER JOIN speciality s ON s.id = c.speciality_id
                 ORDER BY h.public_id, c.starts_at, d.name',
            ),
        ];
    }

    private function writePackage(bool $reversed): string
    {
        self::bootKernel();
        /** @var ReferenceCatalogPackageWriter $writer */
        $writer = self::getContainer()->get(ReferenceCatalogPackageWriter::class);
        /** @var SyntheticReferenceCatalogBuilder $builder */
        $builder = self::getContainer()->get(SyntheticReferenceCatalogBuilder::class);
        $path = sprintf('%s/catalog-%s.json', $this->directory, $reversed ? 'reversed' : 'ordered');
        $writer->write($path, new AlternativeCatalog($writer)->from($builder->build(), $reversed));
        self::ensureKernelShutdown();

        return $path;
    }

    private function bootWith(string $package, string $mapping = ''): void
    {
        foreach (['FIXTURES_REFERENCE_PACKAGE' => $package, 'FIXTURES_CATALOG_MAPPING' => $mapping, 'FIXTURES_UNTIL' => self::UNTIL] as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        self::bootKernel();
    }

    /**
     * @param list<string> $groups
     */
    private function load(array $groups): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = self::getContainer()->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = self::getContainer()->get('doctrine.fixtures.purger.orm_purger_factory');

        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures($groups), false);
        $entityManager->clear();
    }

    /**
     * @return list<string>
     */
    private function names(CatalogReport $report, string $section, string $status): array
    {
        $names = array_map(
            static fn (CatalogMatch $match): string => $match->name,
            array_values(array_filter($report->sections[$section] ?? [], static fn (CatalogMatch $match): bool => $status === $match->status)),
        );
        sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * @param array<string, string> $input
     */
    private function checkCommand(array $input): CommandTester
    {
        $kernel = self::$kernel ?? throw new \LogicException('Boot the kernel first.');
        $tester = new CommandTester(new Application($kernel)->find('app:generate:check-catalog'));
        $tester->execute($input);

        return $tester;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    private function compatibility(): CatalogCompatibility
    {
        /** @var CatalogCompatibility $compatibility */
        $compatibility = self::getContainer()->get(CatalogCompatibility::class);

        return $compatibility;
    }

    private function catalog(): GeneratorCatalog
    {
        /** @var GeneratorCatalog $catalog */
        $catalog = self::getContainer()->get(GeneratorCatalog::class);

        return $catalog;
    }
}
