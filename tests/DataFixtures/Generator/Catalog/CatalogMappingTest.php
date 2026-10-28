<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Catalog;

use App\DataFixtures\Generator\Application\Catalog\CatalogLookupType;
use App\DataFixtures\Generator\Application\Catalog\CatalogMapping;
use PHPUnit\Framework\TestCase;

final class CatalogMappingTest extends TestCase
{
    public function testReadsTheMappingOfTheAlternativeCatalog(): void
    {
        $mapping = CatalogMapping::fromJson(AlternativeCatalog::mappingJson());

        self::assertSame('Stroke Unit', $mapping->name(CatalogLookupType::Department, 'stroke-unit'));
        self::assertSame('Rettungsdienst', $mapping->name(CatalogLookupType::Assignment, 'RD'));
        self::assertNull($mapping->name(CatalogLookupType::Department, 'cardiology'));
        self::assertSame(['stroke-unit' => 'Innere Medizin'], $mapping->specialityOfDepartment);
        self::assertFalse($mapping->isEmpty());
        self::assertTrue(CatalogMapping::none()->isEmpty());
    }

    public function testReadsAPinnedIndication(): void
    {
        $mapping = CatalogMapping::fromJson('{"format": "ivena-generator-catalog-mapping", "version": 1, "indications": {"211": {"normalized": "Normalized label", "raw": "Raw label"}}}');

        self::assertSame(['211' => ['normalized' => 'Normalized label', 'raw' => 'Raw label']], $mapping->indications);
    }

    public function testListsEveryProblemOfAFile(): void
    {
        try {
            CatalogMapping::fromJson('{"format": "other", "names": {"hospital": {"A": "B"}, "department": {"Kardiologie": ""}}, "indications": {"21": {"normalized": "x"}, "322": {"code": "x"}}, "extra": 1}', 'test.json');
            self::fail('An invalid mapping must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Catalog mapping "test.json" is invalid: unknown key "extra"; expected format "ivena-generator-catalog-mapping" version 1; '
                .'names.hospital: unknown type, expected one of department, speciality, assignment, occasion, infection, secondary_transport; '
                .'names.department.Kardiologie: expected a non-empty string; indications.21: expected a three-digit PZC code; '
                .'indications.322: expected an object with "normalized" and/or "raw".',
                $exception->getMessage(),
            );
        }
    }

    public function testRefusesAMissingFile(): void
    {
        $this->expectExceptionMessage('does not exist');
        CatalogMapping::fromFile('/nonexistent/mapping.json');
    }

    public function testRefusesBrokenJson(): void
    {
        $this->expectExceptionMessage('is not valid JSON');
        CatalogMapping::fromJson('{', 'broken.json');
    }
}
