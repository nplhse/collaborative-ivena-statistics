<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\InvalidReferenceCatalogTypeException;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogDocument;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use PHPUnit\Framework\TestCase;

final class ReferenceCatalogTypeTest extends TestCase
{
    public function testParseListReturnsImportOrderWhenEmpty(): void
    {
        self::assertSame(ReferenceCatalogType::importOrder(), ReferenceCatalogType::parseList(null));
        self::assertSame(ReferenceCatalogType::importOrder(), ReferenceCatalogType::parseList(''));
    }

    public function testParseListKeepsImportOrderAndAcceptsUnderscores(): void
    {
        $types = ReferenceCatalogType::parseList('hospital,state,dispatch-area');

        self::assertSame([
            ReferenceCatalogType::State,
            ReferenceCatalogType::DispatchArea,
            ReferenceCatalogType::Hospital,
        ], $types);
    }

    public function testParseListRejectsUnknownType(): void
    {
        $this->expectException(InvalidReferenceCatalogTypeException::class);
        ReferenceCatalogType::parseList('widget');
    }

    public function testParseListIgnoresBlankTokens(): void
    {
        self::assertSame(
            [ReferenceCatalogType::State, ReferenceCatalogType::Department],
            ReferenceCatalogType::parseList(' , state, , department , '),
        );
        self::assertSame(ReferenceCatalogType::importOrder(), ReferenceCatalogType::parseList(','));
    }

    public function testYamlKeyForEveryType(): void
    {
        self::assertSame('states', ReferenceCatalogType::State->yamlKey());
        self::assertSame('hospitals', ReferenceCatalogType::Hospital->yamlKey());
        self::assertSame('indication_groups', ReferenceCatalogType::IndicationGroup->yamlKey());
        self::assertSame('secondary_transports', ReferenceCatalogType::SecondaryTransport->yamlKey());
    }

    public function testDocumentFromArrayTreatsMissingSectionsAsEmptyAndNullState(): void
    {
        $document = ReferenceCatalogDocument::fromArray([
            'states' => ['Hessen', '', 12, ['nested']],
            'dispatch_areas' => [
                ['name' => 'Frankfurt', 'state' => 'Hessen'],
                ['name' => 'Göttingen', 'state' => null],
                ['name' => ''],
                'Frankfurt',
            ],
            'departments' => 'not-a-list',
            'indication_groups' => [
                ['name' => 'ECMO', 'category' => 'Kardiologie', 'codes' => [143, '144']],
                ['name' => ''],
            ],
            'hospitals' => [
                ['name' => 'KH', 'state' => 'Hessen', 'area' => 'Frankfurt', 'beds' => 10],
                ['name' => 'Incomplete'],
            ],
        ]);

        self::assertSame(['Hessen', '12'], $document->states);
        self::assertSame([], $document->departments);
        self::assertCount(2, $document->dispatchAreas);
        self::assertNull($document->dispatchAreas[1]['state']);
        self::assertSame(['143', '144'], $document->indicationGroups[0]['codes']);
        self::assertCount(1, $document->hospitals);
        self::assertTrue($document->isSectionEmpty(ReferenceCatalogType::Speciality));

        $nonEmpty = $document->withoutEmptySections(ReferenceCatalogType::importOrder());
        self::assertSame(['Hessen', '12'], $nonEmpty->states);
        self::assertSame([], $nonEmpty->departments);
        self::assertNotEmpty($nonEmpty->hospitals);
    }

    public function testNamesRejectsUnknownSection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReferenceCatalogDocument()->names('states');
    }
}
