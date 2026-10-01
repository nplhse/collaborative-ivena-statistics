<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Entity;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\SpecialityAlias;
use PHPUnit\Framework\TestCase;

final class ReferenceNameAliasTest extends TestCase
{
    public function testDepartmentAliasStoresMetadataAndIgnoresASecondAdd(): void
    {
        $department = new Department()->setName('Geburtshilfe');
        $alias = new DepartmentAlias(
            $department,
            '  Geburtsklinik  ',
            'historical',
            'local-catalog',
            null,
            '',
            null,
        );

        self::assertNull($alias->getId());
        self::assertSame($department, $alias->getDepartment());
        self::assertSame('  Geburtsklinik  ', $alias->getName());
        self::assertSame('geburtsklinik', $alias->getNormalizedName());
        self::assertNull($alias->getValidFrom());
        self::assertNull($alias->getValidTo());
        self::assertNull($alias->getNote());
        self::assertTrue($alias->matches('historical', 'local-catalog', null, null, null));
        self::assertFalse($alias->matches('historical', 'local-catalog', 'other note', null, null));
        self::assertCount(1, $department->getAliases());

        $department->addAlias($alias);

        self::assertCount(1, $department->getAliases());

        $alias->applyMetadata('faulty_catalog', 'ivena-export', 'catalog typo', '2019-01-01', '2024-12-31');

        self::assertSame('faulty_catalog', $alias->getClassification());
        self::assertSame('ivena-export', $alias->getSource());
        self::assertSame('catalog typo', $alias->getNote());
        self::assertSame('2019-01-01', $alias->getValidFrom()?->format('Y-m-d'));
        self::assertSame('2024-12-31', $alias->getValidTo()?->format('Y-m-d'));
        self::assertTrue($alias->matches('faulty_catalog', 'ivena-export', 'catalog typo', '2019-01-01', '2024-12-31'));
    }

    public function testDepartmentAliasRejectsAnUnparsedDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DepartmentAlias(new Department(), 'Geburtsklinik', 'historical', 'local-catalog', null, 'yesterday', null);
    }

    public function testSpecialityAliasStoresMetadataAndIgnoresASecondAdd(): void
    {
        $speciality = new Speciality()->setName('Innere Medizin');
        $alias = new SpecialityAlias(
            $speciality,
            'Innere',
            'historical',
            'local-catalog',
            null,
            '',
            null,
        );

        self::assertNull($alias->getId());
        self::assertSame($speciality, $alias->getSpeciality());
        self::assertSame('Innere', $alias->getName());
        self::assertSame('innere', $alias->getNormalizedName());
        self::assertSame('historical', $alias->getClassification());
        self::assertSame('local-catalog', $alias->getSource());
        self::assertNull($alias->getValidFrom());
        self::assertNull($alias->getValidTo());
        self::assertNull($alias->getNote());
        self::assertTrue($alias->matches('historical', 'local-catalog', null, null, null));
        self::assertFalse($alias->matches('unambiguous_alias', 'local-catalog', null, null, null));
        self::assertCount(1, $speciality->getAliases());

        $speciality->addAlias($alias);

        self::assertCount(1, $speciality->getAliases());

        $alias->applyMetadata('unambiguous_alias', 'ivena-export', 'short form', '2018-05-01', '2020-05-01');

        self::assertSame('unambiguous_alias', $alias->getClassification());
        self::assertSame('ivena-export', $alias->getSource());
        self::assertSame('short form', $alias->getNote());
        self::assertSame('2018-05-01', $alias->getValidFrom()?->format('Y-m-d'));
        self::assertSame('2020-05-01', $alias->getValidTo()?->format('Y-m-d'));
        self::assertTrue($alias->matches('unambiguous_alias', 'ivena-export', 'short form', '2018-05-01', '2020-05-01'));
    }

    public function testSpecialityAliasRejectsAnUnparsedDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SpecialityAlias(new Speciality(), 'Innere', 'historical', 'local-catalog', null, null, 'not-a-date');
    }
}
