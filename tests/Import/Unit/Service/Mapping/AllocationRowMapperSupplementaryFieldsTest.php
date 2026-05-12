<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use App\Import\Infrastructure\Mapping\AllocationRowMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperSupplementaryFieldsTest extends TestCase
{
    private AllocationRowMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AllocationRowMapper();
    }

    public function testMapsEnrAndFreitextToCaseIdAndNotes(): void
    {
        $dto = $this->mapper->mapAssoc([
            'enr' => '123456',
            'freitext' => 'Patient mit Begleitung',
        ]);

        self::assertSame('123456', $dto->caseId);
        self::assertSame('Patient mit Begleitung', $dto->notes);
    }

    /**
     * @param array<string, string> $row
     */
    #[DataProvider('emptySupplementaryFieldProvider')]
    public function testEmptyOrMissingSupplementaryFieldsMapToNull(array $row): void
    {
        $dto = $this->mapper->mapAssoc($row);

        self::assertNull($dto->caseId);
        self::assertNull($dto->notes);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function emptySupplementaryFieldProvider(): iterable
    {
        yield 'missing keys' => [[]];
        yield 'blank values' => [['enr' => '', 'freitext' => '   ']];
    }
}
