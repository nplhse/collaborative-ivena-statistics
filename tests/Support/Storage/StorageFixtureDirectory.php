<?php

declare(strict_types=1);

namespace App\Tests\Support\Storage;

/**
 * A temporary directory tree for storage scanner and layout tests. Removed again with remove().
 */
final readonly class StorageFixtureDirectory
{
    public string $path;

    public function __construct()
    {
        $path = sys_get_temp_dir().'/storage-fixture-'.bin2hex(random_bytes(6));
        mkdir($path, 0o777, true);
        $this->path = (string) realpath($path);
    }

    public function file(string $relative, int $bytes = 1000): string
    {
        $path = $this->path.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }

        file_put_contents($path, str_repeat('x', $bytes));

        return $path;
    }

    public function directory(string $relative): string
    {
        $path = $this->path.'/'.$relative;
        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }

        return $path;
    }

    public function link(string $relative, string $target): void
    {
        $path = $this->path.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }

        symlink($target, $path);
    }

    public function hardlink(string $relative, string $existingRelative): void
    {
        $path = $this->path.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }

        link($this->path.'/'.$existingRelative, $path);
    }

    /**
     * Allocated size of one entry, as the scanner counts it.
     */
    public function allocated(string $relative): int
    {
        $stat = lstat('' === $relative ? $this->path : $this->path.'/'.$relative);
        \assert(false !== $stat);

        return $stat['blocks'] >= 0 ? $stat['blocks'] * 512 : $stat['size'];
    }

    /**
     * Sum of allocated sizes of the given entries.
     */
    public function allocatedSum(string ...$relatives): int
    {
        return array_sum(array_map($this->allocated(...), $relatives));
    }

    public function remove(): void
    {
        self::removeTree($this->path);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        chmod($path, 0o777);
        foreach (scandir($path) ?: [] as $name) {
            if ('.' !== $name && '..' !== $name) {
                self::removeTree($path.'/'.$name);
            }
        }

        rmdir($path);
    }
}
