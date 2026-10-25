<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Filesystem;

use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Application\Layout\ReleaseDirectory;
use App\Storage\Application\Layout\SharedLink;
use App\Storage\Application\Layout\StorageLayout;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deployer mode when the project runs from <deploy_path>/releases/<name>, <deploy_path>/current resolves to
 * that release and <deploy_path>/shared exists; standalone otherwise (ADR 017, "Deployment layout").
 *
 * Shared paths are the symlinks of the current release that resolve into shared/. Deployer links shallow
 * paths, so the search stops at a few levels and skips directories that never hold such links.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for StorageLayoutDetectorInterface.
 */
#[AsAlias(StorageLayoutDetectorInterface::class)]
final readonly class FilesystemStorageLayoutDetector implements StorageLayoutDetectorInterface
{
    private const int MAX_LINK_DEPTH = 4;

    /** @var list<string> */
    private const array SKIPPED_FOR_LINKS = ['vendor', 'node_modules', '.git', 'var/cache', 'var/log'];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        private DeployerReleasesLog $releasesLog = new DeployerReleasesLog(),
    ) {
    }

    #[\Override]
    public function detect(): StorageLayout
    {
        $project = realpath($this->projectDir);
        if (false === $project) {
            return StorageLayout::standalone($this->projectDir);
        }

        $releasesDirectory = \dirname($project);
        $deployPath = \dirname($releasesDirectory);
        $sharedDirectory = realpath($deployPath.'/shared');

        if ('releases' !== basename($releasesDirectory)
            || realpath($deployPath.'/current') !== $project
            || false === $sharedDirectory || !is_dir($sharedDirectory)) {
            return StorageLayout::standalone($project);
        }

        $dates = $this->releasesLog->read($deployPath);
        $currentName = basename($project);

        return StorageLayout::deployer(
            $deployPath,
            new ReleaseDirectory($currentName, $project, $dates[$currentName] ?? null),
            $this->previousReleases($releasesDirectory, $currentName, $dates),
            $sharedDirectory,
            $this->sharedLinks($project, $sharedDirectory),
        );
    }

    /**
     * @param array<array-key, \DateTimeImmutable> $dates
     *
     * @return list<ReleaseDirectory>
     */
    private function previousReleases(string $releasesDirectory, string $currentName, array $dates): array
    {
        $releases = [];
        foreach (scandir($releasesDirectory) ?: [] as $name) {
            $path = $releasesDirectory.'/'.$name;
            if ('.' === $name || '..' === $name || $name === $currentName || is_link($path) || !is_dir($path)) {
                continue;
            }

            $releases[] = new ReleaseDirectory($name, $path, $dates[$name] ?? null);
        }

        // Newest first: by logged date where known, otherwise by name (Deployer numbers its releases).
        usort($releases, static function (ReleaseDirectory $a, ReleaseDirectory $b): int {
            if ($a->createdAt instanceof \DateTimeImmutable && $b->createdAt instanceof \DateTimeImmutable && $a->createdAt != $b->createdAt) {
                return $b->createdAt <=> $a->createdAt;
            }

            return strnatcmp($b->name, $a->name);
        });

        return $releases;
    }

    /**
     * @return list<SharedLink>
     */
    private function sharedLinks(string $release, string $sharedDirectory): array
    {
        $links = [];
        $queue = [['', 0]];

        while ([] !== $queue) {
            [$relative, $depth] = array_shift($queue);
            $directory = '' === $relative ? $release : $release.'/'.$relative;

            foreach (scandir($directory) ?: [] as $name) {
                if ('.' === $name || '..' === $name) {
                    continue;
                }

                $childRelative = '' === $relative ? $name : $relative.'/'.$name;
                $childPath = $release.'/'.$childRelative;

                if (is_link($childPath)) {
                    $target = realpath($childPath);
                    if (false !== $target && str_starts_with($target, $sharedDirectory.'/')) {
                        $links[] = new SharedLink($childRelative, substr($target, \strlen($sharedDirectory) + 1));
                    }

                    continue;
                }

                if ($depth + 1 < self::MAX_LINK_DEPTH && is_dir($childPath) && !\in_array($childRelative, self::SKIPPED_FOR_LINKS, true)) {
                    $queue[] = [$childRelative, $depth + 1];
                }
            }
        }

        usort($links, static fn (SharedLink $a, SharedLink $b): int => strcmp($a->releasePath, $b->releasePath));

        return $links;
    }
}
