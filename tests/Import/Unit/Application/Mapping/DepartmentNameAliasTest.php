<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Mapping;

use App\Import\Application\Mapping\DepartmentNameAlias;
use PHPUnit\Framework\TestCase;

final class DepartmentNameAliasTest extends TestCase
{
    public function testMapsObstetricsAliasesToGeburtshilfe(): void
    {
        self::assertSame('geburtshilfe', DepartmentNameAlias::canonicalKey('perinatalzentrum level 2'));
        self::assertSame('geburtshilfe', DepartmentNameAlias::canonicalKey('perinatalzentrum level 1'));
        self::assertSame('kardiologie', DepartmentNameAlias::canonicalKey('kardiologie'));
    }
}
