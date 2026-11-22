<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\DTO\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\CatalogCode;
use App\Allocation\Application\DTO\DataCatalog\CatalogVariable;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogType;
use PHPUnit\Framework\TestCase;

final class CatalogVariableTest extends TestCase
{
    public function testCodeListsCollapseAboveFiveCodes(): void
    {
        self::assertFalse($this->variableWithCodes(0)->collapsesCodes());
        self::assertFalse($this->variableWithCodes(5)->collapsesCodes());
        self::assertTrue($this->variableWithCodes(6)->collapsesCodes());
    }

    public function testReferenceObjectKeepsTheStorageType(): void
    {
        $variable = new CatalogVariable('test.ref', 'allocation', DataCatalogType::REFERENCE, false, 'test', 'ref_id', storageType: 'int');

        self::assertSame('int', $variable->withReferenceObject('target', 'allocation')->storageType);
    }

    private function variableWithCodes(int $count): CatalogVariable
    {
        $codes = 0 === $count ? [] : array_map(static fn (int $i): CatalogCode => new CatalogCode((string) $i, null, null), range(1, $count));

        return new CatalogVariable('test.code', 'allocation', DataCatalogType::CODE, false, 'test', 'code', $codes);
    }
}
