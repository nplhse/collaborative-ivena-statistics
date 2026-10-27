<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Command;

use App\Allocation\Domain\Entity\DepartmentAlias;
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
        $obstetrics = DepartmentFactory::createOne(['name' => 'Geburtshilfe']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new DepartmentAlias($obstetrics, 'Geburtszentrum B', 'historical', 'local-catalog'));
        $em->flush();
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
            'REF_NOT_FOUND | Reference not found for "department" | field=department | value="Geburtszentrum B"',
            ['fachbereich' => 'Geburtszentrum B'],
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
        self::assertFileDoesNotExist($outputDir.'/catalog.yaml');
        self::assertFileExists($outputDir.'/report.md');
        self::assertFileExists($outputDir.'/requeue-import-ids.txt');
        self::assertFileExists($outputDir.'/requeue-imports.md');

        $report = (string) file_get_contents($outputDir.'/report.md');
        self::assertStringContainsString('| dispatch-area | Göttingen |', $report);
        self::assertStringNotContainsString('| dispatch-area | Frankfurt |', $report);
        self::assertStringContainsString('| department | Chir. Überwachung |', $report);
        self::assertStringNotContainsString('Geburtszentrum B', $report);
        self::assertStringContainsString('| occasion | aus Klinik |', $report);
        self::assertStringNotContainsString('Erhängen', $report);
        self::assertStringNotContainsString('| secondary-transport |', $report);
        self::assertStringContainsString('admin pages', $report);

        $ids = trim((string) file_get_contents($outputDir.'/requeue-import-ids.txt'));
        self::assertSame((string) $import->getId(), $ids);

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
        self::assertFileExists($absolute.'/report.md');
        self::assertStringNotContainsString('Nuklearmedizin', (string) file_get_contents($absolute.'/report.md'));
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
