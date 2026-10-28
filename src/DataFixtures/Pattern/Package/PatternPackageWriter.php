<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes pattern packages as deterministic JSON (ADR 022).
 *
 * The same package always gives the same bytes: file order, pretty-printed, unescaped UTF-8 and a
 * trailing newline. Every table is a JSON object, also hours 0–23 and empty flag tables.
 */
final readonly class PatternPackageWriter
{
    private const int JSON_FLAGS = PatternPackageHasher::JSON_FLAGS | \JSON_PRETTY_PRINT;

    public function __construct(
        private PatternPackageHasher $hasher,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Returns the package with its counts and the checksum of its content.
     */
    public function seal(PatternPackage $package): PatternPackage
    {
        return $package->withMeta($package->meta->withSeal(
            $package->counts(),
            new PatternPackageChecksum(PatternPackageFormat::CHECKSUM_ALGORITHM, $this->hasher->compute($package)),
        ));
    }

    public function encode(PatternPackage $package): string
    {
        return json_encode($package->toArray(), self::JSON_FLAGS)."\n";
    }

    public function write(string $path, PatternPackage $package): void
    {
        $this->filesystem->dumpFile($path, $this->encode($package));
    }
}
