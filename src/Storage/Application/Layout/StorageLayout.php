<?php

declare(strict_types=1);

namespace App\Storage\Application\Layout;

use App\Storage\Domain\Enum\MeasurementMode;

/**
 * What lies on disk and in which order it is measured (ADR 017, "Deployment layout").
 */
final readonly class StorageLayout
{
    /**
     * @param list<ReleaseDirectory> $previousReleases newest first
     * @param list<SharedLink>       $sharedLinks
     */
    private function __construct(
        public MeasurementMode $mode,
        public string $root,
        public ?ReleaseDirectory $currentRelease,
        public array $previousReleases,
        public ?string $sharedDirectory,
        public array $sharedLinks,
    ) {
    }

    public static function standalone(string $projectDirectory): self
    {
        return new self(MeasurementMode::Standalone, $projectDirectory, null, [], null, []);
    }

    /**
     * @param list<ReleaseDirectory> $previousReleases newest first
     * @param list<SharedLink>       $sharedLinks
     */
    public static function deployer(
        string $deployPath,
        ReleaseDirectory $currentRelease,
        array $previousReleases,
        string $sharedDirectory,
        array $sharedLinks,
    ): self {
        return new self(MeasurementMode::Deployer, $deployPath, $currentRelease, $previousReleases, $sharedDirectory, $sharedLinks);
    }

    /**
     * Release paths that the current release links into shared/.
     *
     * @return list<string>
     */
    public function sharedReleasePaths(): array
    {
        return array_map(static fn (SharedLink $link): string => $link->releasePath, $this->sharedLinks);
    }

    /**
     * Paths inside shared/ that the current release links to.
     *
     * @return list<string>
     */
    public function linkedSharedPaths(): array
    {
        return array_map(static fn (SharedLink $link): string => $link->sharedPath, $this->sharedLinks);
    }
}
