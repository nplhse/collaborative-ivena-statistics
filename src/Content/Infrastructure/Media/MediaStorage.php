<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Uid\Uuid;

/**
 * The only place that reads and writes media files (ADR 039).
 *
 * Layout below the storage root: `originals/<YYYY>/<MM>/<uuid>.<ext>` for uploaded files, `variants/<YYYY>/<MM>/<uuid>/`
 * for derived files, `.tmp/` for files being written. A file is written to `.tmp/` and moved into place with one
 * rename, so a key never points at a partly written file. Files of a key are never overwritten.
 */
final readonly class MediaStorage
{
    public const string ORIGINALS = 'originals';
    public const string VARIANTS = 'variants';
    public const string TEMPORARY = '.tmp';

    private const string KEY_PATTERN = '#^originals/(\d{4})/(\d{2})/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([a-z0-9]{1,8})$#';
    private const string EXTENSION_PATTERN = '#^[a-z0-9]{1,8}$#';
    private const int CHUNK_BYTES = 1024 * 1024;

    private Filesystem $filesystem;

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        #[Autowire(param: 'app.media_storage_dir')]
        private string $root,
        private ClockInterface $clock,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function root(): string
    {
        return rtrim($this->root, '/');
    }

    public static function isValidKey(string $key): bool
    {
        return 1 === preg_match(self::KEY_PATTERN, $key);
    }

    /**
     * Copies a file into the storage under a new key in the month of $storedAt (default: now).
     *
     * @throws MediaStorageException when the source cannot be read or the file cannot be written
     */
    public function store(string $sourcePath, string $extension, ?\DateTimeImmutable $storedAt = null): StoredMediaFile
    {
        $extension = strtolower($extension);
        if (1 !== preg_match(self::EXTENSION_PATTERN, $extension)) {
            throw new MediaStorageException(sprintf('"%s" is not a valid file extension for the media storage.', $extension));
        }

        $month = ($storedAt ?? $this->clock->now())->format('Y/m');
        $key = sprintf('%s/%s/%s.%s', self::ORIGINALS, $month, Uuid::v7()->toRfc4122(), $extension);
        $temporary = Path::join($this->root(), self::TEMPORARY, Uuid::v7()->toRfc4122().'.part');

        try {
            $this->filesystem->mkdir(\dirname($temporary));
        } catch (\Throwable $exception) {
            throw new MediaStorageException('Could not prepare the media storage for writing.', 0, $exception);
        }

        [$size, $checksum] = $this->copy($sourcePath, $temporary);

        $target = $this->absolutePath($key);
        try {
            $this->filesystem->mkdir(\dirname($target));
            $this->filesystem->rename($temporary, $target);
        } catch (\Throwable $exception) {
            $this->filesystem->remove($temporary);

            throw new MediaStorageException(sprintf('Could not move the stored file to %s.', $key), 0, $exception);
        }

        return new StoredMediaFile($key, $size, $checksum);
    }

    /**
     * @throws MediaStorageException for a key that does not follow the layout
     */
    public function absolutePath(string $key): string
    {
        if (!self::isValidKey($key)) {
            throw new MediaStorageException(sprintf('"%s" is not a media storage key.', $key));
        }

        return Path::join($this->root(), $key);
    }

    /** Directory of the derived files of a key; it does not have to exist. */
    public function variantDirectory(string $key): string
    {
        if (1 !== preg_match(self::KEY_PATTERN, $key, $match)) {
            throw new MediaStorageException(sprintf('"%s" is not a media storage key.', $key));
        }

        return Path::join($this->root(), self::VARIANTS, $match[1], $match[2], $match[3]);
    }

    /** Removes the file of a key and its derived files. A missing file is not an error. */
    public function delete(string $key): void
    {
        $this->filesystem->remove([$this->absolutePath($key), $this->variantDirectory($key)]);
    }

    /**
     * @return array{int, string} size and SHA-256 of the copy
     */
    private function copy(string $sourcePath, string $targetPath): array
    {
        $source = is_file($sourcePath) ? @fopen($sourcePath, 'rb') : false;
        if (false === $source) {
            throw new MediaStorageException(sprintf('Could not read the file %s.', basename($sourcePath)));
        }

        $target = @fopen($targetPath, 'xb');
        if (false === $target) {
            fclose($source);

            throw new MediaStorageException('Could not write to the media storage.');
        }

        $context = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if (false === $chunk) {
                    throw new MediaStorageException(sprintf('Could not read the file %s.', basename($sourcePath)));
                }

                if ('' === $chunk) {
                    continue;
                }

                if (false === fwrite($target, $chunk)) {
                    throw new MediaStorageException('Could not write to the media storage.');
                }

                hash_update($context, $chunk);
                $size += \strlen($chunk);
            }

            if (!fflush($target)) {
                throw new MediaStorageException('Could not write to the media storage.');
            }
        } catch (MediaStorageException $exception) {
            fclose($source);
            fclose($target);
            $this->filesystem->remove($targetPath);

            throw $exception;
        }

        fclose($source);
        fclose($target);
        @chmod($targetPath, 0o644);

        return [$size, hash_final($context)];
    }
}
