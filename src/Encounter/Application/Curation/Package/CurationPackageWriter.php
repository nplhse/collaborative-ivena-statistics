<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Writes encounter curation packages as deterministic JSON: fixed key order, pretty-printed, unescaped UTF-8 and a
 * trailing newline. Two exports of the same data differ only in `meta.exported_at`.
 */
final readonly class CurationPackageWriter
{
    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NormalizerInterface $normalizer,
        private CurationChecksum $checksum,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Returns the package with its counts and the checksum of its content.
     */
    public function seal(CurationPackage $package): CurationPackage
    {
        $meta = $package->meta;

        return $package->withMeta(new PackageMeta(
            $meta->format,
            $meta->version,
            $meta->exportedAt,
            $package->counts(),
            new PackageChecksum(CurationPackageFormat::CHECKSUM_ALGORITHM, $this->checksum->compute($package)),
            $meta->appVersion,
            $meta->sourceEnvironment,
            $meta->notes,
        ));
    }

    public function encode(CurationPackage $package): string
    {
        return json_encode($this->normalizer->normalize($package, 'json'), self::JSON_FLAGS)."\n";
    }

    public function write(string $path, CurationPackage $package): void
    {
        $this->filesystem->dumpFile($path, $this->encode($package));
    }
}
