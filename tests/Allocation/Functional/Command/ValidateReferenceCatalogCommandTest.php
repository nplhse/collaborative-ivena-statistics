<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogChecksum;
use App\Tests\Support\ReferenceCatalog\ReferenceCatalogPackageData;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class ValidateReferenceCatalogCommandTest extends KernelTestCase
{
    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/reference-catalog-'.bin2hex(random_bytes(6)).'.json';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function testValidPackageListsItsTypes(): void
    {
        $tester = $this->tester();
        $this->writePackage(ReferenceCatalogPackageData::complete());

        $exitCode = $tester->execute(['--source' => $this->file]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('is a valid reference catalog package', $display);
        self::assertMatchesRegularExpression('/indication-raw\s+2/', $display);
    }

    public function testInvalidPackageListsEveryProblem(): void
    {
        $tester = $this->tester();
        $data = ReferenceCatalogPackageData::complete();
        $data['hospitals'][0]['size'] = 'Huge';
        $data['states'][0]['name'] = '';
        $this->writePackage($data);

        $exitCode = $tester->execute(['--source' => $this->file]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('2 problem(s)', $display);
        self::assertStringContainsString('hospitals[0].size', $display);
        self::assertStringContainsString('states[0].name', $display);
    }

    public function testMissingFileFails(): void
    {
        $exitCode = $this->tester()->execute(['--source' => $this->file]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:reference:validate'));
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

        $sealed = ReferenceCatalogPackageData::withChecksum($data, $denormalizer, $checksum);
        file_put_contents($this->file, json_encode($sealed, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
    }
}
