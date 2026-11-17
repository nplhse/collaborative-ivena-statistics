<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation\Package;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * SHA-256 over the canonical JSON encoding of the data sections (everything except `meta`), as for the reference
 * catalog package (ADR 020): whitespace and layout of a file do not matter, any change of content or order does.
 */
final readonly class CurationChecksum
{
    private const int JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function compute(CurationPackage $package): string
    {
        $data = $this->normalizer->normalize($package, 'json');
        if (!\is_array($data)) {
            throw new \LogicException('An encounter curation package must normalize to an array.');
        }

        unset($data['meta']);

        return hash(CurationPackageFormat::CHECKSUM_ALGORITHM, json_encode($data, self::JSON_FLAGS));
    }
}
