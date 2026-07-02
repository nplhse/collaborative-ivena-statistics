<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class ApplicationCodeSizeCalculator
{
    private const string CACHE_KEY = 'admin.storage.application_bytes';

    private const int CACHE_TTL_SECONDS = 900;

    /** @var list<string> */
    private const array EXCLUDED_DIR_NAMES = [
        'cache',
        'log',
        'test',
        'node_modules',
        '.git',
    ];

    public function __construct(
        private CacheInterface $cache,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function getBytes(): int
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): int {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->calculateBytes();
        });
    }

    private function calculateBytes(): int
    {
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->projectDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if ($this->isExcludedPath($path)) {
                continue;
            }

            $total += $file->getSize();
        }

        return $total;
    }

    private function isExcludedPath(string $path): bool
    {
        $relative = str_replace($this->projectDir.'/', '', $path);
        $parts = explode('/', $relative);

        return array_any($parts, fn ($part): bool => \in_array($part, self::EXCLUDED_DIR_NAMES, true));
    }
}
