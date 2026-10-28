<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

/**
 * SHA-256 over the canonical JSON encoding of everything except `meta`.
 *
 * Whitespace and number notation of a file do not matter (shares are floats after reading), but any
 * change of content or key order does.
 */
final readonly class PatternPackageHasher
{
    public const int JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_FORCE_OBJECT | \JSON_THROW_ON_ERROR;

    public function compute(PatternPackage $package): string
    {
        return hash(PatternPackageFormat::CHECKSUM_ALGORITHM, json_encode($package->content(), self::JSON_FLAGS));
    }
}
