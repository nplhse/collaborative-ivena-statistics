<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Catalog;

use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\DataFixtures\Generator\Application\Catalog\CatalogLookupType;
use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Generator\Application\Catalog\GeneratorVocabulary;
use App\DataFixtures\Pattern\Application\PatternSet;
use PHPUnit\Framework\TestCase;

final class GeneratorVocabularyTest extends TestCase
{
    public function testCollectsTheNamesOfEverySource(): void
    {
        $vocabulary = new GeneratorVocabulary(PatternSet::defaults());
        $departments = $vocabulary->departments();

        // Core department, optional department, closure department, indication group, flu-wave closure.
        foreach (['cardiology', 'neonatology', 'general-psychiatry', 'spine-surgery', 'infectious-diseases'] as $department) {
            self::assertContains($department, $departments);
        }
        self::assertSame(array_values(array_unique($departments)), $departments, 'no duplicates');
        self::assertSame('neurology', $vocabulary->specialityOfDepartment()['stroke-unit']);
        self::assertContains('paediatrics', $vocabulary->specialities());

        self::assertSame(['Arzt/Arzt', 'Einweisung', 'Notzuweisung', 'Patient', 'RD', 'ZLST'], $vocabulary->lookups(CatalogLookupType::Assignment));
        self::assertNotContains('_present', $vocabulary->lookups(CatalogLookupType::Infection));
    }

    public function testEveryKeyMatchesTheSyntheticCatalog(): void
    {
        // The default labels are the synthetic catalog; the speciality of a key is the field of its unit there.
        foreach (GeneratorKeys::DEPARTMENTS as $key => $department) {
            self::assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)*$/', $key);
            self::assertContains($department['label'], MedicalSpecialities::unitNames(), $key);
            self::assertSame(GeneratorKeys::SPECIALITIES[$department['speciality']], MedicalSpecialities::fieldOfUnit($department['label']), $key);
        }
        foreach (GeneratorKeys::SPECIALITIES as $key => $label) {
            self::assertContains($label, MedicalSpecialities::fieldNames(), $key);
        }
        self::assertSame('stroke-unit', GeneratorKeys::department('Schlaganfallmedizin'));
        self::assertSame('stroke-unit', GeneratorKeys::department('stroke-unit'));
        $this->expectException(\InvalidArgumentException::class);
        GeneratorKeys::department('Herzchirurgie');
    }

    public function testCollectsTheCodesOfPatternsAndScenarios(): void
    {
        $codes = new GeneratorVocabulary(PatternSet::defaults())->indicationCodes();

        self::assertContains('322', $codes, 'default pattern');
        self::assertContains('530', $codes, 'full-care pattern only');
        self::assertContains('224', $codes, 'scenarios');
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^\d{3}$/', $code);
        }
    }
}
