<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogChecksum;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\ReferenceCatalog\ReferenceCatalogPackageData as Data;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportReferenceCatalogCommandTest extends KernelTestCase
{
    use Factories;

    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/reference-catalog-import-'.bin2hex(random_bytes(6)).'.json';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function testImportsThePackageAndSaysItIsNotARestore(): void
    {
        $tester = $this->tester();
        UserFactory::createOne(['username' => 'admin']);
        $this->writePackage(Data::complete());

        $exitCode = $tester->execute(['--source' => $this->file]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $this->flat($tester->getDisplay());
        self::assertStringContainsString('It is not a restore', $display);
        self::assertStringContainsString('Imported 17 row(s)', $display);
        self::assertSame(1, $this->em()->getRepository(Hospital::class)->count([]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--source' => $this->file]));
        self::assertStringContainsString('Nothing to do', $this->flat($tester->getDisplay()));
        self::assertSame(1, $this->em()->getRepository(Hospital::class)->count([]));
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->tester();
        UserFactory::createOne(['username' => 'admin']);
        $this->writePackage(Data::complete());

        $exitCode = $tester->execute(['--source' => $this->file, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('17 row(s) would be created', $this->flat($tester->getDisplay()));
        self::assertSame(0, $this->em()->getRepository(State::class)->count([]));
    }

    public function testConflictsStopTheImportBeforeAnyWrite(): void
    {
        $tester = $this->tester();
        UserFactory::createOne(['username' => 'admin']);
        StateFactory::createOne(['name' => 'Hessen']);
        $this->writePackage(Data::complete());

        $exitCode = $tester->execute(['--source' => $this->file]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $this->flat($tester->getDisplay());
        self::assertStringContainsString('conflicts with the database', $display);
        self::assertStringContainsString('states[0]', $display);
        self::assertSame(1, $this->em()->getRepository(State::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(Hospital::class)->count([]));
    }

    public function testAnInvalidPackageIsNotImported(): void
    {
        $tester = $this->tester();
        UserFactory::createOne(['username' => 'admin']);
        $data = Data::complete();
        $data['meta']['version'] = 99;
        file_put_contents($this->file, json_encode($data, \JSON_THROW_ON_ERROR));

        $exitCode = $tester->execute(['--source' => $this->file]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('not a valid reference catalog package', $this->flat($tester->getDisplay()));
        self::assertSame(0, $this->em()->getRepository(State::class)->count([]));
    }

    public function testAnUnknownUserFails(): void
    {
        $tester = $this->tester();
        $this->writePackage(Data::complete());

        $exitCode = $tester->execute(['--source' => $this->file, '--user' => 'nobody']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No user found for "nobody"', $this->flat($tester->getDisplay()));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writePackage(array $data): void
    {
        /** @var DenormalizerInterface $denormalizer */
        $denormalizer = self::getContainer()->get(DenormalizerInterface::class);
        /** @var ReferenceCatalogChecksum $checksum */
        $checksum = self::getContainer()->get(ReferenceCatalogChecksum::class);

        file_put_contents($this->file, json_encode(Data::withChecksum($data, $denormalizer, $checksum), \JSON_THROW_ON_ERROR));
    }

    private function flat(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:reference:import'));
    }
}
