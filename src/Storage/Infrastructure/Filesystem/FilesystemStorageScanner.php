<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Filesystem;

use App\Storage\Application\Contract\FileStorageScannerInterface;
use App\Storage\Application\Layout\StorageLayout;
use App\Storage\Application\Scan\FileScan;
use App\Storage\Application\Scan\StoragePathClassifier;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageLocation;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * One walk with lstat(): symlinks are never followed, every inode is counted once, sizes are allocated blocks.
 * In Deployer mode the order decides who owns a shared inode: shared/, current release, previous releases
 * (newest first), then the rest of the deploy root (ADR 017, "Deployment layout").
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for FileStorageScannerInterface.
 */
#[AsAlias(FileStorageScannerInterface::class)]
final readonly class FilesystemStorageScanner implements FileStorageScannerInterface
{
    private const int TYPE_MASK = 0o170000;
    private const int TYPE_DIRECTORY = 0o040000;
    private const int TYPE_LINK = 0o120000;

    public function __construct(
        private StoragePathClassifier $classifier,
    ) {
    }

    #[\Override]
    public function scan(StorageLayout $layout): FileScan
    {
        $accumulator = new ScanAccumulator();

        if (MeasurementMode::Standalone === $layout->mode || !$layout->currentRelease instanceof \App\Storage\Application\Layout\ReleaseDirectory || null === $layout->sharedDirectory) {
            $this->walk($accumulator, $layout->root, StorageLocation::Project, null, 'project');

            return $accumulator->result();
        }

        $this->walk($accumulator, $layout->sharedDirectory, StorageLocation::Shared, null, 'shared', linkedSharedPaths: $layout->linkedSharedPaths());
        $this->walk($accumulator, $layout->currentRelease->path, StorageLocation::CurrentRelease, $layout->currentRelease->name, 'release');

        foreach ($layout->previousReleases as $release) {
            $this->walk($accumulator, $release->path, StorageLocation::PreviousRelease, $release->name, 'release', leftoverPaths: $layout->sharedReleasePaths());
        }

        $this->walk($accumulator, $layout->root, StorageLocation::DeployRoot, null, 'deploy_root', skipped: ['releases', 'shared']);

        return $accumulator->result();
    }

    /**
     * @param list<string>|null $linkedSharedPaths shared paths the current release links to (shared location only)
     * @param list<string>      $leftoverPaths     release paths that should be links into shared/
     * @param list<string>      $skipped           top-level entries measured elsewhere
     */
    private function walk(
        ScanAccumulator $accumulator,
        string $base,
        StorageLocation $location,
        ?string $detail,
        string $locationContributor,
        ?array $linkedSharedPaths = null,
        array $leftoverPaths = [],
        array $skipped = [],
    ): void {
        $baseStat = @lstat($base);
        if (false === $baseStat || self::TYPE_DIRECTORY !== ($baseStat['mode'] & self::TYPE_MASK)) {
            $accumulator->failLocation($base, 'not a readable directory', $location, $detail, $locationContributor);

            return;
        }

        if (false === @scandir($base)) {
            $accumulator->failLocation($base, 'directory cannot be listed', $location, $detail, $locationContributor);

            return;
        }

        $accumulator->add($baseStat, $location, $detail, $this->classifier->classify($location, ''));
        $pending = [''];

        while ([] !== $pending) {
            $relative = array_pop($pending);
            $directory = '' === $relative ? $base : $base.'/'.$relative;
            $names = @scandir($directory);

            if (false === $names) {
                $accumulator->failPath($directory, 'directory cannot be listed', $location, $detail, $this->classifier->classify($location, $relative));

                continue;
            }

            foreach ($names as $name) {
                if ('.' === $name || '..' === $name) {
                    continue;
                }

                $childRelative = '' === $relative ? $name : $relative.'/'.$name;
                if ('' === $relative && \in_array($name, $skipped, true)) {
                    continue;
                }

                $classification = $this->classifier->classify($location, $childRelative);
                $path = $base.'/'.$childRelative;
                $stat = @lstat($path);

                if (false === $stat) {
                    $accumulator->failPath($path, 'cannot be read', $location, $detail, $classification);

                    continue;
                }

                $type = $stat['mode'] & self::TYPE_MASK;
                if (self::TYPE_LINK === $type) {
                    continue;
                }

                $flags = $this->flags($childRelative, $linkedSharedPaths, $leftoverPaths);
                $isNew = $accumulator->add($stat, $location, $detail, $classification, $flags);

                if ($isNew && self::TYPE_DIRECTORY === $type) {
                    $pending[] = $childRelative;
                }
            }
        }
    }

    /**
     * @param list<string>|null $linkedSharedPaths
     * @param list<string>      $leftoverPaths
     *
     * @return list<MeasurementItemFlag>
     */
    private function flags(string $relative, ?array $linkedSharedPaths, array $leftoverPaths): array
    {
        $flags = [];

        if (null !== $linkedSharedPaths && !$this->isLinkedOrAncestor($relative, $linkedSharedPaths)) {
            $flags[] = MeasurementItemFlag::UnusedSharedPath;
        }

        foreach ($leftoverPaths as $path) {
            if (StoragePathClassifier::isWithin($relative, $path)) {
                $flags[] = MeasurementItemFlag::LeftoverInRelease;

                break;
            }
        }

        return $flags;
    }

    /**
     * @param list<string> $linkedPaths
     */
    private function isLinkedOrAncestor(string $relative, array $linkedPaths): bool
    {
        return array_any($linkedPaths, fn (string $path): bool => StoragePathClassifier::isWithin($relative, $path) || StoragePathClassifier::isWithin($path, $relative));
    }
}
