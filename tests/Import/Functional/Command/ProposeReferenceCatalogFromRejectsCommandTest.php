<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Command;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
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
final class ProposeReferenceCatalogFromRejectsCommandTest extends KernelTestCase
{
    use Factories;

    public function testWritesCatalogStubsReportAndRequeueIds(): void
    {
        $user = UserFactory::createOne(['username' => 'admin']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        DepartmentFactory::createOne(['name' => 'Geburtshilfe']);
        $hospital = HospitalFactory::createOne([
            'name' => 'Propose Hospital',
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::find(['name' => 'Frankfurt']),
        ]);
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'filePath' => 'var/imports/kassel.csv',
            'name' => 'kassel-import',
        ]);

        $this->persistReject(
            $import,
            'REF_NOT_FOUND | Reference not found for "dispatchArea" | field=dispatchArea | value="_Kommunale Regionalleitstelle Göttingen"',
            [
                'zuweisung_durch' => '_Kommunale Regionalleitstelle Göttingen',
                'anlass' => 'aus Klinik',
                'fachbereich' => 'Kardiologie',
            ],
            10,
        );
        $this->persistReject(
            $import,
            'REF_NOT_FOUND | Reference not found for "department" | field=department | value="Chir. Überwachung"',
            ['fachbereich' => 'Chir. Überwachung'],
            11,
        );
        $this->persistReject(
            $import,
            'REF_NOT_FOUND | Reference not found for "department" | field=department | value="Perinatalzentrum Level 2"',
            ['fachbereich' => 'Perinatalzentrum Level 2'],
            12,
        );
        $this->persistReject(
            $import,
            'REF_NOT_FOUND | Reference not found for "dispatchArea" | field=dispatchArea | value="Frankfurt Führungsstab"',
            ['zuweisung_durch' => 'Frankfurt Führungsstab'],
            13,
        );
        $this->persistReject(
            $import,
            'createdAt: This value should not be blank.',
            [
                'anlass' => 'Erhängen',
                'sekundaeranlass' => 'https://smed.health/#/',
            ],
            14,
        );

        $outputDir = sys_get_temp_dir().'/reference-from-rejects-'.bin2hex(random_bytes(4));
        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--output' => $outputDir]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputDir.'/catalog.yaml');
        self::assertFileExists($outputDir.'/report.md');
        self::assertFileExists($outputDir.'/requeue-import-ids.txt');
        self::assertFileExists($outputDir.'/requeue-imports.md');

        /** @var array<string, mixed> $catalog */
        $catalog = Yaml::parseFile($outputDir.'/catalog.yaml');
        $areaNames = array_map(static fn (mixed $row): string => \is_array($row) ? (string) ($row['name'] ?? '') : '', $catalog['dispatch_areas'] ?? []);
        self::assertContains('Göttingen', $areaNames);
        self::assertNotContains('Frankfurt', $areaNames);
        foreach ($catalog['dispatch_areas'] ?? [] as $row) {
            if (\is_array($row) && 'Göttingen' === ($row['name'] ?? null)) {
                self::assertArrayHasKey('state', $row);
                self::assertNull($row['state']);
            }
        }

        self::assertContains('Chir. Überwachung', $catalog['departments'] ?? []);
        self::assertNotContains('Perinatalzentrum Level 2', $catalog['departments'] ?? []);
        self::assertContains('aus Klinik', $catalog['occasions'] ?? []);
        self::assertNotContains('Erhängen', $catalog['occasions'] ?? []);
        self::assertSame([], $catalog['secondary_transports'] ?? []);

        $ids = trim((string) file_get_contents($outputDir.'/requeue-import-ids.txt'));
        self::assertSame((string) $import->getId(), $ids);

        $report = (string) file_get_contents($outputDir.'/report.md');
        self::assertStringContainsString('Göttingen', $report);
        self::assertStringContainsString('kassel.csv', $report);

        $requeue = (string) file_get_contents($outputDir.'/requeue-imports.md');
        self::assertStringContainsString('Propose Hospital', $requeue);
        self::assertStringContainsString((string) $import->getId(), $requeue);
    }

    public function testRelativeOutputPathAndMinCount(): void
    {
        $user = UserFactory::createOne(['username' => 'admin']);
        $hospital = HospitalFactory::createOne();
        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'filePath' => 'var/imports/once.csv',
        ]);
        $this->persistReject(
            $import,
            'REF_NOT_FOUND | Reference not found for "speciality" | field=speciality | value="Nuklearmedizin"',
            ['fachgebiet' => 'Nuklearmedizin'],
            1,
        );

        $relative = 'var/export/reference-from-rejects-test-'.bin2hex(random_bytes(4));
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--output' => $relative,
            '--min-count' => '2',
        ]));
        self::assertStringContainsString('Proposed catalog values: 0', $tester->getDisplay());

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $absolute = $projectDir.'/'.$relative;
        self::assertFileExists($absolute.'/catalog.yaml');
        /** @var array<string, mixed> $catalog */
        $catalog = Yaml::parseFile($absolute.'/catalog.yaml');
        self::assertNotContains('Nuklearmedizin', $catalog['specialities'] ?? []);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persistReject(Import $import, string $message, array $row, int $lineNumber): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $reject = new ImportReject();
        $reject->setImport($import);
        $reject->setMessages([$message]);
        $reject->setRow($row);
        $reject->setLineNumber($lineNumber);
        $em->persist($reject);
        $em->flush();
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:reference:propose-from-rejects'));
    }
}
