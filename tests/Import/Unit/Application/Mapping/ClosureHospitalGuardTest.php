<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Mapping;

use App\Import\Application\Exception\ImportException;
use App\Import\Application\Mapping\ClosureHospitalGuard;
use PHPUnit\Framework\TestCase;

final class ClosureHospitalGuardTest extends TestCase
{
    private ClosureHospitalGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new ClosureHospitalGuard();
    }

    public function testCollapsedSpellingMatchesTheSelectedHospital(): void
    {
        $profile = $this->guard->profile(1, [1 => 'Klinikum Beispiel'], ['  klinikum   beispiel ']);

        self::assertFalse($profile->multiple);
        self::assertNull($profile->differingFileLabel);
        $this->guard->assertRow($profile, '  klinikum   beispiel ');
    }

    public function testSingleUnknownShortNameIsAcceptedForTheSelectedHospital(): void
    {
        $names = [
            7 => 'Universitätsklinikum Gießen und Marburg, Standort Marburg',
            8 => 'Universitätsklinikum Gießen und Marburg, Standort Gießen',
            9 => 'Klinikum Kassel',
        ];
        $profile = $this->guard->profile(7, $names, ['Universitätsklinikum Marburg']);

        self::assertFalse($profile->multiple);
        self::assertSame('Universitätsklinikum Marburg', $profile->differingFileLabel);
        $this->guard->assertRow($profile, 'Universitätsklinikum Marburg');
    }

    public function testSingleShortNameThatMatchesAnotherHospitalConflicts(): void
    {
        $names = [
            7 => 'Universitätsklinikum Gießen und Marburg, Standort Marburg',
            9 => 'Klinikum Kassel',
        ];
        $profile = $this->guard->profile(7, $names, ['Klinikum Kassel']);

        $this->expectException(ImportException::class);
        try {
            $this->guard->assertRow($profile, 'Klinikum Kassel');
        } catch (ImportException $e) {
            self::assertSame('HOSPITAL_CONFLICT', $e->getCodeStr());
            self::assertStringContainsString('Klinikum Kassel', $e->getMessage());
            self::assertStringContainsString('Standort Marburg', $e->getMessage());
            self::assertStringContainsString('Start the import', $e->getMessage());
            throw $e;
        }
    }

    public function testDuplicateCatalogNameIsNotAUniqueConflict(): void
    {
        $names = [
            1 => 'Klinikum Beispiel',
            2 => 'Klinikum Beispiel',
            3 => 'Gewähltes Haus',
        ];
        $profile = $this->guard->profile(3, $names, ['Klinikum Beispiel']);

        self::assertSame('Klinikum Beispiel', $profile->differingFileLabel);
        $this->guard->assertRow($profile, 'Klinikum Beispiel');
    }

    public function testMultipleNamesKeepOnlyTheExactSelectedHospital(): void
    {
        $names = [
            1 => 'Klinikum Beispiel',
            9 => 'Klinikum Kassel',
        ];
        $rows = [
            ['krankenhaus_kurzname' => 'Klinikum Beispiel'],
            ['krankenhaus_kurzname' => '  klinikum   beispiel '],
            ['krankenhaus_kurzname' => 'Andere Klinik'],
            ['krankenhaus_kurzname' => ''],
        ];
        $profile = $this->guard->profile(1, $names, $this->guard->distinctShortNames($rows));

        self::assertTrue($profile->multiple);
        self::assertSame(['Klinikum Beispiel', 'Andere Klinik'], $profile->distinctDisplays);
        $this->guard->assertRow($profile, 'Klinikum Beispiel');

        try {
            $this->guard->assertRow($profile, 'Andere Klinik');
            self::fail('Expected a mismatch for the other hospital.');
        } catch (ImportException $e) {
            self::assertSame('HOSPITAL_MISMATCH', $e->getCodeStr());
            self::assertStringContainsString('Andere Klinik', $e->getMessage());
            self::assertStringContainsString('Split the file', $e->getMessage());
        }
    }

    public function testMultipleNamesThatMissTheSelectedCatalogNameRejectEveryRow(): void
    {
        $names = [
            7 => 'Universitätsklinikum Gießen und Marburg, Standort Marburg',
            9 => 'Klinikum Kassel',
        ];
        $profile = $this->guard->profile(7, $names, ['Universitätsklinikum Marburg', 'Klinikum Kassel']);

        self::assertTrue($profile->multiple);

        foreach (['Universitätsklinikum Marburg', 'Klinikum Kassel'] as $shortName) {
            try {
                $this->guard->assertRow($profile, $shortName);
                self::fail('Expected a mismatch for '.$shortName);
            } catch (ImportException $e) {
                self::assertSame('HOSPITAL_MISMATCH', $e->getCodeStr());
                self::assertStringContainsString('Split the file', $e->getMessage());
            }
        }
    }

    public function testMissingSelectedHospitalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->guard->profile(4, [1 => 'Klinikum Beispiel'], ['Klinikum Beispiel']);
    }

    public function testFileWithoutAShortNameStaysAssignedToTheSelectedHospital(): void
    {
        $profile = $this->guard->profile(1, [1 => 'Klinikum Beispiel'], []);

        self::assertFalse($profile->multiple);
        self::assertNull($profile->differingFileLabel);
        $this->guard->assertRow($profile, null);
    }
}
