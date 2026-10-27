<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\InvalidReferenceCatalogTypeException;
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

    public function testSectionKeyForEveryType(): void
    {
        self::assertSame('states', ReferenceCatalogType::State->sectionKey());
        self::assertSame('hospitals', ReferenceCatalogType::Hospital->sectionKey());
        self::assertSame('indication_groups', ReferenceCatalogType::IndicationGroup->sectionKey());
        self::assertSame('secondary_transports', ReferenceCatalogType::SecondaryTransport->sectionKey());
    }
}
