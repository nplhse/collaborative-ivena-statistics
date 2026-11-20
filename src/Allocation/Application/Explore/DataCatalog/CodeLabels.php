<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

/**
 * How the codes of an enum variable are labelled: with the enum's `label()` key, or with
 * `$prefix` followed by the code. Without CodeLabels the code itself is shown.
 */
final readonly class CodeLabels
{
    private function __construct(
        public string $domain,
        public ?string $prefix,
    ) {
    }

    public static function fromEnumLabel(string $domain): self
    {
        return new self($domain, null);
    }

    public static function prefixed(string $prefix, string $domain): self
    {
        return new self($domain, $prefix);
    }
}
