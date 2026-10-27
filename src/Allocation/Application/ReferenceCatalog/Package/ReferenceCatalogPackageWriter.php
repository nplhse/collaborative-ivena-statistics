<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Writes reference catalog packages as deterministic JSON (ADR 020).
 *
 * The same package always gives the same bytes: fixed key order, pretty-printed,
 * unescaped UTF-8 and a trailing newline, so diffs in a private data repository stay readable.
 */
final readonly class ReferenceCatalogPackageWriter
{
    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public function __construct(
        private NormalizerInterface $normalizer,
        private ReferenceCatalogChecksum $checksum,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Returns the package with counts for its listed types and the checksum of its content.
     */
    public function seal(ReferenceCatalogPackage $package): ReferenceCatalogPackage
    {
        $meta = $package->meta;
        $counts = [];
        foreach (ReferenceCatalogType::importOrder() as $type) {
            if (\in_array($type->value, $meta->types, true)) {
                $counts[$type->value] = \count($package->entriesOf($type));
            }
        }

        // The checksum covers the sections only, so the meta block it is computed with does not matter.
        $value = $this->checksum->compute($package);

        return $this->withMeta($package, new PackageMeta(
            $meta->format,
            $meta->version,
            $meta->exportedAt,
            $meta->complete,
            $meta->types,
            $counts,
            new PackageChecksum(ReferenceCatalogFormat::CHECKSUM_ALGORITHM, $value),
            $meta->appVersion,
            $meta->sourceEnvironment,
            $meta->sources,
            $meta->notes,
        ));
    }

    public function encode(ReferenceCatalogPackage $package): string
    {
        return json_encode($this->normalizer->normalize($package, 'json'), self::JSON_FLAGS)."\n";
    }

    public function write(string $path, ReferenceCatalogPackage $package): void
    {
        $this->filesystem->dumpFile($path, $this->encode($package));
    }

    private function withMeta(ReferenceCatalogPackage $package, PackageMeta $meta): ReferenceCatalogPackage
    {
        return new ReferenceCatalogPackage(
            $meta,
            $package->states,
            $package->dispatchAreas,
            $package->departments,
            $package->specialities,
            $package->assignments,
            $package->occasions,
            $package->infections,
            $package->secondaryTransports,
            $package->indicationsNormalized,
            $package->indicationsRaw,
            $package->indicationGroups,
            $package->hospitals,
        );
    }
}
