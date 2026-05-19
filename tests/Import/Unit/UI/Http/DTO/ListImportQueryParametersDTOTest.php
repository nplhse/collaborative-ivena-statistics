<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\UI\Http\DTO;

use App\Import\UI\Http\DTO\ListImportQueryParametersDTO;
use PHPUnit\Framework\TestCase;

final class ListImportQueryParametersDTOTest extends TestCase
{
    public function testDefaultCreatedFromIsFirstOfJanuary2017(): void
    {
        $dto = new ListImportQueryParametersDTO();

        self::assertSame('2017-01-01', $dto->createdFrom);
        self::assertTrue($dto->isDefaultCreatedFrom());
    }

    public function testDefaultCreatedUntilIsToday(): void
    {
        $dto = new ListImportQueryParametersDTO();

        self::assertSame(ListImportQueryParametersDTO::defaultCreatedUntil(), $dto->createdUntil);
        self::assertTrue($dto->isDefaultCreatedUntil());
    }

    public function testExplicitDatesOverrideDefaults(): void
    {
        $dto = new ListImportQueryParametersDTO(
            createdFrom: '2020-06-15',
            createdUntil: '2021-01-01',
        );

        self::assertSame('2020-06-15', $dto->createdFrom);
        self::assertSame('2021-01-01', $dto->createdUntil);
        self::assertFalse($dto->isDefaultCreatedFrom());
        self::assertFalse($dto->isDefaultCreatedUntil());
    }
}
