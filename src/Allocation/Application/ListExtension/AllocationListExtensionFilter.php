<?php

declare(strict_types=1);

namespace App\Allocation\Application\ListExtension;

/**
 * A select filter contributed to the Explore allocation list. Labels are translation keys of `domain`.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the filter drawer and the active filter badges.
 */
final readonly class AllocationListExtensionFilter
{
    /**
     * @param array<string, string> $choices value => label key, without the empty "all" choice
     */
    public function __construct(
        public string $label,
        public string $allLabel,
        public array $choices,
        public string $domain,
        public ?string $help = null,
    ) {
        if ([] === $choices || \array_key_exists('', $choices)) {
            throw new \InvalidArgumentException('An extension filter needs choices with non-empty values.');
        }
    }
}
