<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Command;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
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
final class AnalyzeImportRejectsCommandTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testZeroRejectsWritesCsvWithHeaderOnly(): void
    {
        $output = $this->tempOutputPath('csv');

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--output' => $output]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($output);
        $lines = file($output, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        self::assertStringContainsString('Total rejects: 0', $tester->getDisplay());
    }

    public function testAggregatesGroupsWithMinCountLimitAndMarkdownFormat(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'filePath' => 'var/imports/sample.csv',
            'name' => 'sample-import',
        ]);

        $refMessage = 'REF_NOT_FOUND | Reference not found for "speciality" | field=speciality | value="Innere Medizin"';
        $blankMessage = 'createdAt: This value should not be blank.';

        $this->persistReject($import, $refMessage, ['fachgebiet' => 'Innere Medizin'], 10);
        $this->persistReject($import, $refMessage, ['fachgebiet' => 'Innere Medizin'], 11);
        $this->persistReject($import, $blankMessage, ['datum_erstellungsdatum' => ''], 12);

        $output = $this->tempOutputPath('md');
        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--format' => 'md',
            '--output' => $output,
            '--min-count' => '2',
            '--limit' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $content = file_get_contents($output);
        self::assertNotFalse($content);
        self::assertStringContainsString('Total rejects: 3', $content);
        self::assertStringContainsString('Distinct groups: 1', $tester->getDisplay());
        self::assertStringContainsString('speciality', $content);
        self::assertStringNotContainsString('createdAt', $content);
    }

    public function testIncludeExamplesAddsRawRowToCsv(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne([
            'hospital' => $seed['hospital'],
            'createdBy' => $seed['user'],
            'filePath' => 'var/imports/with-examples.csv',
        ]);

        $this->persistReject(
            $import,
            'Unable to detect a supported row type.',
            ['fachgebiet' => 'Test'],
            5,
        );

        $output = $this->tempOutputPath('csv');
        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--output' => $output,
            '--include-examples' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $content = file_get_contents($output);
        self::assertNotFalse($content);
        self::assertStringContainsString('fachgebiet', $content);
        self::assertStringContainsString('with-examples.csv', $content);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persistReject(Import $import, string $message, array $row, int $lineNumber): void
    {
        $reject = new ImportReject();
        $reject->setImport($import);
        $reject->setMessages([$message]);
        $reject->setRow($row);
        $reject->setLineNumber($lineNumber);

        $this->em->persist($reject);
        $this->em->flush();
    }

    /**
     * @return array{user: object, hospital: object}
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'reject-analysis-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'RejectAnalysisState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'RejectAnalysisDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'RejectAnalysisHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'RejectAnalysisSpeciality']);
        DepartmentFactory::createOne(['name' => 'RejectAnalysisDepartment']);
        AssignmentFactory::createOne(['name' => 'RejectAnalysisAssignment']);
        OccasionFactory::createOne(['name' => 'RejectAnalysisOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'RejectAnalysisSecondary']);
        InfectionFactory::createOne(['name' => 'RejectAnalysisInfection']);
        IndicationRawFactory::createOne(['name' => 'RejectAnalysisRaw', 'code' => 800002]);
        IndicationNormalizedFactory::createOne(['name' => 'RejectAnalysisNormalized']);

        return [
            'user' => $user,
            'hospital' => $hospital,
        ];
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:import:analyze-rejects');

        return new CommandTester($command);
    }

    private function tempOutputPath(string $extension): string
    {
        return sys_get_temp_dir().'/import-reject-analysis-'.bin2hex(random_bytes(6)).'.'.$extension;
    }
}
