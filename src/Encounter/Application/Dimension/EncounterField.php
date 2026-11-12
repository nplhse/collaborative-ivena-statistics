<?php

declare(strict_types=1);

namespace App\Encounter\Application\Dimension;

/**
 * Contract of one column of a canonical exchange file. Empty cells are null; `required` fields must
 * not be empty. Rules across fields (for example "one of two timestamps") belong to the import.
 */
final readonly class EncounterField
{
    /**
     * @param list<string> $allowedValues for Code and CodeSet fields
     */
    public function __construct(
        public string $name,
        public EncounterFieldType $type,
        public bool $required = false,
        public array $allowedValues = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
    ) {
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    public static function code(string $name, string $enum, bool $required = false): self
    {
        return new self($name, EncounterFieldType::Code, $required, self::values($enum));
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    public static function codeSet(string $name, string $enum): self
    {
        return new self($name, EncounterFieldType::CodeSet, false, self::values($enum));
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @return list<string>
     */
    private static function values(string $enum): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
    }
}
