<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExportReferenceCatalogCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testWritesAValidPackageToATimestampedFileUnderVarReference(): void
    {
        $tester = $this->tester();
        self::mockTime(new \DateTimeImmutable('2026-10-27 14:05:09'));
        $state = StateFactory::createOne(['name' => 'Hessen']);
        HospitalFactory::createOne(['state' => $state, 'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state])]);

        $expected = self::getContainer()->getParameter('kernel.project_dir').'/var/reference/catalog-20261027-140509.json';
        try {
            $exitCode = $tester->execute(['--notes' => 'nightly']);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('catalog-20261027-140509.json', (string) preg_replace('/\s+/', '', $tester->getDisplay()));

            /** @var ReferenceCatalogPackageReader $reader */
            $reader = self::getContainer()->get(ReferenceCatalogPackageReader::class);
            $result = $reader->read($expected);
            self::assertSame([], $result->violations);
            self::assertSame('nightly', $result->package?->meta->notes);
            self::assertCount(1, $result->package->hospitals);
        } finally {
            if (is_file($expected)) {
                unlink($expected);
            }
        }
    }

    public function testWritesNothingWhenTheDataDoesNotFormAValidPackage(): void
    {
        $tester = $this->tester();
        $hessen = StateFactory::createOne(['name' => 'Hessen']);
        $bayern = StateFactory::createOne(['name' => 'Bayern']);
        HospitalFactory::createOne(['state' => $hessen, 'dispatchArea' => DispatchAreaFactory::createOne(['state' => $bayern])]);
        $output = sys_get_temp_dir().'/catalog-export-'.bin2hex(random_bytes(4)).'.json';

        $exitCode = $tester->execute(['--output' => $output]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFileDoesNotExist($output);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Nothing was written', $display);
        self::assertStringContainsString('hospitals[0].area', $display);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:reference:export'));
    }
}
