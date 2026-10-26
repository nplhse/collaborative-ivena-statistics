<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * SHA-256 over the canonical JSON encoding of the data sections (everything except `meta`).
 *
 * The encoding follows the package structure, so whitespace and key layout of a file do not
 * matter, but any change of content or entry order does.
 */
final readonly class ReferenceCatalogChecksum
{
    private const int JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function compute(ReferenceCatalogPackage $package): string
    {
        $data = $this->normalizer->normalize($package, 'json');
        if (!\is_array($data)) {
            throw new \LogicException('A reference catalog package must normalize to an array.');
        }

        unset($data['meta']);

        return hash(ReferenceCatalogFormat::CHECKSUM_ALGORITHM, json_encode($data, self::JSON_FLAGS));
    }
}
