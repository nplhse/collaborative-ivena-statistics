<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

final readonly class ReferenceNameEntry
{
    /**
     * @param list<string>                 $previousNames
     * @param list<ReferenceNameAliasSpec> $aliases
     */
    public function __construct(
        public string $name,
        public array $previousNames = [],
        public array $aliases = [],
    ) {
    }

    public static function fromYaml(mixed $item): ?self
    {
        if (\is_string($item) || \is_int($item)) {
            $name = trim((string) $item);

            return '' === $name ? null : new self($name);
        }

        if (!\is_array($item)) {
            return null;
        }

        $name = trim((string) ($item['name'] ?? ''));
        if ('' === $name) {
            return null;
        }

        $previousNames = [];
        foreach ($item['previous_names'] ?? [] as $previousName) {
            $previousName = trim((string) $previousName);
            if ('' !== $previousName) {
                $previousNames[] = $previousName;
            }
        }

        $aliases = [];
        foreach ($item['aliases'] ?? [] as $alias) {
            if (!\is_array($alias)) {
                continue;
            }

            $aliasName = trim((string) ($alias['name'] ?? ''));
            if ('' === $aliasName) {
                continue;
            }

            $classification = trim((string) ($alias['classification'] ?? ''));
            if (!ReferenceNameAliasClassification::tryFrom($classification) instanceof ReferenceNameAliasClassification) {
                throw new \InvalidArgumentException(sprintf('Unknown alias classification "%s" for "%s".', $classification, $aliasName));
            }

            $source = trim((string) ($alias['source'] ?? ''));
            if ('' === $source) {
                throw new \InvalidArgumentException(sprintf('Alias "%s" is missing a source.', $aliasName));
            }

            $note = trim((string) ($alias['note'] ?? ''));
            $validFrom = self::nullableString($alias['valid_from'] ?? null);
            $validTo = self::nullableString($alias['valid_to'] ?? null);

            $aliases[] = new ReferenceNameAliasSpec(
                $aliasName,
                $classification,
                $source,
                '' === $note ? null : $note,
                $validFrom,
                $validTo,
            );
        }

        return new self($name, $previousNames, $aliases);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function toYaml(): string|array
    {
        if ([] === $this->previousNames && [] === $this->aliases) {
            return $this->name;
        }

        $row = ['name' => $this->name];
        if ([] !== $this->previousNames) {
            $row['previous_names'] = $this->previousNames;
        }
        if ([] !== $this->aliases) {
            $row['aliases'] = array_map(
                static fn (ReferenceNameAliasSpec $alias): array => $alias->toYaml(),
                $this->aliases,
            );
        }

        return $row;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $string = trim((string) $value);

        return '' === $string ? null : $string;
    }
}
