<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration\Application\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\CatalogCode;
use App\Allocation\Application\DTO\DataCatalog\CatalogObject;
use App\Allocation\Application\DTO\DataCatalog\CatalogVariable;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogMetadataResolver;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogType;
use App\Encounter\Application\Security\EncounterAttribute;
use Cois\Plugin\EdVisit\Application\DataCatalog\EdVisitDataCatalogContributor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The ED visit object of the Explore data catalog (#1415): columns from the plugin's Doctrine mapping, ranges
 * from the exchange-format field contract (exchange-format.md lists the same limits).
 */
final class EdVisitDataCatalogContributorTest extends KernelTestCase
{
    public function testTheObjectIsAGatedPluginContribution(): void
    {
        $definitions = self::getContainer()->get(EdVisitDataCatalogContributor::class)->dataObjects();

        self::assertCount(1, $definitions);
        self::assertSame(['ed_visit', true, EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL], [$definitions[0]->key, $definitions[0]->contributedByPlugin, $definitions[0]->grantedAttribute]);
        self::assertSame(42, $this->object()->variableCount());
    }

    public function testEmbeddedFieldsResolveToTheirPrefixedColumns(): void
    {
        $object = $this->object();

        $admission = $this->variable($object, 'ed_visit.ed_admission_at');
        self::assertSame(['ed_visit', 'ed_admission_at', DataCatalogType::DATETIME, true], [$admission->table, $admission->column, $admission->type, $admission->nullable]);

        $category = $this->variable($object, 'ed_visit.mts_category');
        self::assertSame(['mts_category', DataCatalogType::CODE], [$category->column, $category->type]);
        self::assertSame(['red', 'orange', 'yellow', 'green', 'blue'], array_map(static fn (CatalogCode $code): string => $code->code, $category->codes));
        self::assertSame(['encounter.mts.category.red.name', 'encounter'], [$category->codes[0]->labelKey, $category->codes[0]->domain]);

        self::assertSame('retriage_at', $this->variable($object, 'ed_visit.retriage_at')->column);
        self::assertSame('disposition_code', $this->variable($object, 'ed_visit.disposition')->column);
        self::assertSame('vitals_reference', $this->variable($object, 'ed_visit.vitals_reference')->column);

        $encounter = $this->variable($object, 'ed_visit.encounter');
        self::assertSame(['encounter_id', DataCatalogType::REFERENCE, false, 'encounter'], [$encounter->column, $encounter->type, $encounter->nullable, $encounter->referenceTable]);
    }

    public function testIsolationIsACodeSetWithTheNineExchangeFormatCodes(): void
    {
        $isolation = $this->variable($this->object(), 'ed_visit.isolation');

        self::assertSame(['ed_visit', 'isolation', DataCatalogType::CODE_SET, false], [$isolation->table, $isolation->column, $isolation->type, $isolation->nullable]);
        self::assertSame(
            ['gastroenteritis', 'clostridioides', 'covid19', 'mdro', 'mrsa', 'esbl', 'pseudomonas', 'tb', 'other'],
            array_map(static fn (CatalogCode $code): string => $code->code, $isolation->codes),
        );
        self::assertSame(['encounter.visit.isolation.tb', 'encounter'], [$isolation->codes[7]->labelKey, $isolation->codes[7]->domain]);
    }

    public function testRangesComeFromTheFieldContract(): void
    {
        $object = $this->object();

        $expected = [
            'ed_visit.pain_score' => [0, 10, null],
            'ed_visit.bp_systolic' => [40, 300, 'data_catalog.unit.mmhg'],
            'ed_visit.bp_diastolic' => [20, 200, 'data_catalog.unit.mmhg'],
            'ed_visit.heart_rate' => [20, 300, 'data_catalog.unit.per_minute'],
            'ed_visit.temperature' => [25.0, 45.0, 'data_catalog.unit.celsius'],
            'ed_visit.respiratory_rate' => [3, 80, 'data_catalog.unit.per_minute'],
            'ed_visit.glucose' => [10, 1500, 'data_catalog.unit.mg_dl'],
            'ed_visit.spo2' => [30, 100, 'data_catalog.unit.percent'],
            'ed_visit.gcs' => [3, 15, null],
        ];

        foreach ($expected as $key => $range) {
            $variable = $this->variable($object, $key);
            self::assertSame($range, [$variable->range?->min, $variable->range?->max, $variable->range?->unitKey], $key);
        }

        self::assertSame(DataCatalogType::DECIMAL, $this->variable($object, 'ed_visit.temperature')->type);
    }

    private function object(): CatalogObject
    {
        $definitions = self::getContainer()->get(EdVisitDataCatalogContributor::class)->dataObjects();

        return self::getContainer()->get(DataCatalogMetadataResolver::class)->resolve($definitions[0]);
    }

    private function variable(CatalogObject $object, string $key): CatalogVariable
    {
        $variable = $object->variable($key);
        self::assertInstanceOf(CatalogVariable::class, $variable, $key);

        return $variable;
    }
}
