<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

/**
 * Local overrides that bind the generator's names to a catalog that spells them differently (ADR 022).
 *
 * Keys are always keys of the generator vocabulary (department and speciality keys of `GeneratorKeys`,
 * IVENA lookup values, PZC codes), values always names in the installed catalog.
 * The file is private when it is written for a real catalog: it lives in `var/reference/` and is
 * never tracked.
 *
 * ```json
 * {
 *     "format": "ivena-generator-catalog-mapping",
 *     "version": 1,
 *     "names": { "department": { "stroke-unit": "<catalog department>" } },
 *     "speciality_of_department": { "stroke-unit": "<catalog speciality>" },
 *     "indications": { "211": { "normalized": "<catalog name>", "raw": "<catalog label>" } }
 * }
 * ```
 */
final readonly class CatalogMapping
{
    public const string FORMAT = 'ivena-generator-catalog-mapping';

    public const int VERSION = 1;

    private const array KEYS = ['format', 'version', 'names', 'speciality_of_department', 'indications'];

    /**
     * @param array<string, array<string, string>>                        $names                  type → generator key → catalog name
     * @param array<string, string>                                       $specialityOfDepartment department key → catalog speciality
     * @param array<int|string, array{normalized?: string, raw?: string}> $indications            PZC code → catalog names (JSON keys like "211" become int keys)
     */
    public function __construct(
        public array $names = [],
        public array $specialityOfDepartment = [],
        public array $indications = [],
        public ?string $source = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @throws \InvalidArgumentException with every problem of the file
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Catalog mapping "%s" does not exist or is not readable.', $path));
        }

        return self::fromJson((string) file_get_contents($path), $path);
    }

    /**
     * @throws \InvalidArgumentException with every problem of the file
     */
    public static function fromJson(string $json, string $source = 'mapping'): self
    {
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(sprintf('Catalog mapping "%s" is not valid JSON: %s.', $source, $exception->getMessage()), 0, $exception);
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(sprintf('Catalog mapping "%s" must be a JSON object.', $source));
        }

        $errors = [];
        foreach (array_diff(array_keys($data), self::KEYS) as $key) {
            $errors[] = sprintf('unknown key "%s"', $key);
        }
        if (self::FORMAT !== ($data['format'] ?? null) || self::VERSION !== ($data['version'] ?? null)) {
            $errors[] = sprintf('expected format "%s" version %d', self::FORMAT, self::VERSION);
        }

        $types = array_map(static fn (CatalogLookupType $type): string => $type->value, CatalogLookupType::cases());
        $names = [];
        foreach (self::map($data, 'names', $errors) as $type => $byName) {
            if (!\in_array($type, $types, true)) {
                $errors[] = sprintf('names.%s: unknown type, expected one of %s', $type, implode(', ', $types));
                continue;
            }
            $names[$type] = self::strings($byName, 'names.'.$type, $errors);
        }

        $specialityOfDepartment = self::strings(self::map($data, 'speciality_of_department', $errors), 'speciality_of_department', $errors);

        $indications = [];
        foreach (self::map($data, 'indications', $errors) as $code => $entry) {
            $code = (string) $code;
            if (1 !== preg_match('/^\d{3}$/', $code)) {
                $errors[] = sprintf('indications.%s: expected a three-digit PZC code', $code);
                continue;
            }
            if (!\is_array($entry) || [] === $entry || [] !== array_diff(array_keys($entry), ['normalized', 'raw'])) {
                $errors[] = sprintf('indications.%s: expected an object with "normalized" and/or "raw"', $code);
                continue;
            }
            /** @var array{normalized?: string, raw?: string} $strings */
            $strings = self::strings($entry, 'indications.'.$code, $errors);
            $indications[$code] = $strings;
        }

        if ([] !== $errors) {
            throw new \InvalidArgumentException(sprintf('Catalog mapping "%s" is invalid: %s.', $source, implode('; ', $errors)));
        }

        return new self($names, $specialityOfDepartment, $indications, $source);
    }

    public function name(CatalogLookupType $type, string $name): ?string
    {
        return $this->names[$type->value][$name] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->names && [] === $this->specialityOfDepartment && [] === $this->indications;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $errors
     *
     * @return array<array-key, mixed>
     */
    private static function map(array $data, string $key, array &$errors): array
    {
        $value = $data[$key] ?? [];
        if (!\is_array($value)) {
            $errors[] = sprintf('%s: expected an object', $key);

            return [];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $values
     * @param list<string>            $errors
     *
     * @return array<string, string>
     */
    private static function strings(array $values, string $path, array &$errors): array
    {
        $strings = [];
        foreach ($values as $key => $value) {
            if (!\is_string($value) || '' === trim($value)) {
                $errors[] = sprintf('%s.%s: expected a non-empty string', $path, $key);
                continue;
            }
            $strings[(string) $key] = $value;
        }

        return $strings;
    }
}
