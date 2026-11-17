<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Functional\Command;

use App\Tests\Support\Encounter\CurationPackageData;
use App\User\Domain\Factory\UserFactory;
use Cois\Plugin\EdVisit\Domain\Entity\MtsDiagram;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogSource;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCatalogStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * app:encounter:curation:export and app:encounter:curation:import (#1198).
 */
#[ResetDatabase]
final class CurationPackageCommandTest extends KernelTestCase
{
    use Factories;

    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/curation-'.bin2hex(random_bytes(4)).'.json';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function testExportWritesAPackageThatImportsBackUnchanged(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        $export = $this->command('app:encounter:curation:export');

        self::assertSame(Command::SUCCESS, $export->execute(['--output' => $this->file]));
        self::assertFileExists($this->file);
        self::assertStringContainsString('problem_areas   24', $export->getDisplay());

        $import = $this->command('app:encounter:curation:import');
        self::assertSame(Command::SUCCESS, $import->execute(['--source' => $this->file]));
        self::assertStringContainsString('Nothing to add', $import->getDisplay());
    }

    public function testDryRunListsTheAdditionsAndWritesNothing(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        file_put_contents($this->file, CurationPackageData::json(CurationPackageData::newInstance()));
        $tester = $this->command('app:encounter:curation:import');

        self::assertSame(Command::SUCCESS, $tester->execute(['--source' => $this->file, '--dry-run' => true]));

        $display = $this->flat($tester->getDisplay());
        self::assertStringContainsString('diagrams 2 1 0', $display);
        self::assertStringContainsString('problem area haut_und_augen "Haut und Augen": PZC 990, 991; rules hautprobleme +3', $display);
        self::assertStringContainsString('diagram alias hautproblem_alt → hautprobleme', $display);
        self::assertStringContainsString('seed draft', $display);
        self::assertStringContainsString('Dry run: 10 row(s) would be added.', $display);
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM encounter_mts_diagram WHERE code = 'hautprobleme'"));
    }

    public function testImportRecordsTheUserAsAuditActor(): void
    {
        $user = UserFactory::createOne(['username' => 'curator']);
        file_put_contents($this->file, CurationPackageData::json(CurationPackageData::newInstance()));
        $tester = $this->command('app:encounter:curation:import');

        self::assertSame(Command::SUCCESS, $tester->execute(['--source' => $this->file, '--user' => 'curator']));
        self::assertStringContainsString('Added 10 row(s).', $this->flat($tester->getDisplay()));
        self::assertSame(
            [(string) $user->getId()],
            array_map(strval(...), $this->connection()->fetchFirstColumn("SELECT DISTINCT actor_id FROM audit_log WHERE intent = 'encounter.curation.entry_created'")),
        );
    }

    public function testConflictsStopTheImport(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        file_put_contents($this->file, CurationPackageData::json(CurationPackageData::package(
            diagrams: [CurationPackageData::entry('allergie', 'Allergie (Erwachsene)', 'Allergy', 'orbis_export'), CurationPackageData::entry('kieferprobleme', 'Kieferprobleme')],
        )));
        $tester = $this->command('app:encounter:curation:import');

        self::assertSame(Command::FAILURE, $tester->execute(['--source' => $this->file]));

        $display = $this->flat($tester->getDisplay());
        self::assertStringContainsString('Another curated diagram has this code on the target.', $display);
        self::assertStringContainsString('1 conflict(s). Nothing was imported.', $display);
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM encounter_mts_diagram WHERE code = 'kieferprobleme'"));
    }

    public function testExportNamesWhatItLeftOutAndImportNeedsASource(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new MtsDiagram('unbekannt', 'unbekannt', MtsCatalogSource::FirstSeen, MtsCatalogStatus::Provisional));
        $entityManager->flush();

        $export = $this->command('app:encounter:curation:export');
        self::assertSame(Command::SUCCESS, $export->execute(['--output' => $this->file]));
        self::assertStringContainsString('Not curated, so not exported: 1 entries.', $this->flat($export->getDisplay()));

        $import = $this->command('app:encounter:curation:import');
        self::assertSame(Command::INVALID, $import->execute([]));
        self::assertStringContainsString('Pass the package file with --source=.', $this->flat($import->getDisplay()));
    }

    public function testDryRunListsRulesAddedToAnExistingArea(): void
    {
        UserFactory::createOne(['username' => 'admin']);
        file_put_contents($this->file, CurationPackageData::json(CurationPackageData::package(
            diagrams: [CurationPackageData::seededAllergy(), ...CurationPackageData::seededTraumaDiagrams()],
            areas: [CurationPackageData::seededAbdominalTrauma(rules: [
                ['code' => 'abdominelle_schmerzen_bei_erwachsenen', 'points' => 3],
                ['code' => 'allergie', 'points' => 1],
                ['code' => 'schweres_trauma', 'points' => 3],
            ])],
        )));
        $tester = $this->command('app:encounter:curation:import');

        self::assertSame(Command::SUCCESS, $tester->execute(['--source' => $this->file, '--dry-run' => true]));
        self::assertStringContainsString('problem area abdominaltrauma: add PZC –; add rules allergie +1', $this->flat($tester->getDisplay()));
    }

    public function testUnknownUserAndInvalidFileFail(): void
    {
        file_put_contents($this->file, '{"meta": {"format": "ivena-encounter-curation", "version": 7}}');
        $tester = $this->command('app:encounter:curation:import');

        self::assertSame(Command::FAILURE, $tester->execute(['--source' => $this->file, '--user' => 'nobody']));
        self::assertStringContainsString('No user found for "nobody"', $this->flat($tester->getDisplay()));

        UserFactory::createOne(['username' => 'admin']);
        self::assertSame(Command::FAILURE, $tester->execute(['--source' => $this->file]));
        $display = $this->flat($tester->getDisplay());
        self::assertStringContainsString('is not a valid curation package (1 problem(s))', $display);
        self::assertStringContainsString('found format "ivena-encounter-curation" version 7', $display);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find($name));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function flat(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
