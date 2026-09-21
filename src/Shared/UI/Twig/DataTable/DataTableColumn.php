<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

/** @psalm-suppress PossiblyUnusedProperty Consumed by DataTable cell and header templates. */
final readonly class DataTableColumn
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public DataTableColumnType $type = DataTableColumnType::Text,
        public string $labelDomain = 'messages',
        public ?string $property = null,
        public ?string $fallbackProperty = null,
        public bool $sortable = false,
        public ?string $sortKey = null,
        public ?string $width = null,
        public ?string $align = null,
        public bool $visible = true,
        public array $options = [],
    ) {
    }

    /**
     * @param array<string, mixed>|self $column
     */
    public static function fromMixed(array|self $column): self
    {
        return $column instanceof self ? $column : self::fromArray($column);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $key = self::stringValue($config['key'] ?? null);
        if ('' === $key) {
            throw new \InvalidArgumentException('DataTable column requires a key.');
        }

        $type = $config['type'] ?? DataTableColumnType::Text;
        if (\is_string($type)) {
            $type = DataTableColumnType::tryFrom($type) ?? DataTableColumnType::Text;
        }
        if (!$type instanceof DataTableColumnType) {
            $type = DataTableColumnType::Text;
        }

        $options = $config['options'] ?? [];
        if (!\is_array($options)) {
            $options = [];
        }

        foreach ([
            'route',
            'routeParams',
            'target',
            'format',
            'iconTrue',
            'iconFalse',
            'trueClass',
            'falseClass',
            'badgePalette',
            'numberProperty',
            'cellTemplate',
            'actionLabel',
            'actionClass',
        ] as $optionKey) {
            if (\array_key_exists($optionKey, $config)) {
                $options[$optionKey] = $config[$optionKey];
            }
        }

        return new self(
            key: $key,
            label: self::stringValue($config['label'] ?? $key),
            type: $type,
            labelDomain: self::stringValue($config['labelDomain'] ?? null) ?: 'messages',
            property: self::nullableString($config['property'] ?? null) ?? $key,
            fallbackProperty: self::nullableString($config['fallbackProperty'] ?? $config['fallback'] ?? null),
            sortable: (bool) ($config['sortable'] ?? false),
            sortKey: self::nullableString($config['sortKey'] ?? null),
            width: self::nullableString($config['width'] ?? null),
            align: self::nullableString($config['align'] ?? null),
            visible: (bool) ($config['visible'] ?? true),
            options: $options,
        );
    }

    public function resolvedSortKey(): string
    {
        return $this->sortKey ?? $this->property ?? $this->key;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    private static function stringValue(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
