<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Temporary storage of files the import form uploads in the background (#977), outside the public directory
 * under `<imports_base_dir>/pending/<user id>/`. Each upload is a `<token>.upload` file next to a `<token>.json`
 * with its client name, MIME type, size and time. The token is random (128 bit) and only valid within the
 * folder of the user who uploaded it, so it can neither reach another user's files nor an arbitrary path.
 *
 * The import form takes the file over with {@see UploadedFile()}; the regular pipeline then moves it into the
 * import storage, so a token can be consumed only once. Replaced and removed uploads are deleted at once,
 * abandoned ones by the hourly `import_pending_upload_purge` job after {@see RETENTION_HOURS}.
 */
final readonly class PendingImportUploadStore
{
    public const int RETENTION_HOURS = 24;

    private const string TOKEN_PATTERN = '/^[0-9a-f]{32}$/';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        #[Autowire(param: 'app.imports_base_dir')]
        private string $baseDir,
        private Filesystem $filesystem,
        private ClockInterface $clock,
    ) {
    }

    public function store(UploadedFile $file, int $userId): PendingImportUpload
    {
        $token = bin2hex(random_bytes(16));
        $directory = $this->userDirectory($userId);
        $this->filesystem->mkdir($directory, 0770);

        $upload = new PendingImportUpload(
            $token,
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            (int) $file->getSize(),
            $this->clock->now(),
        );

        $file->move($directory, $token.'.upload');
        $this->filesystem->dumpFile($this->metadataPath($userId, $token), json_encode([
            'originalName' => $upload->originalName,
            'mimeType' => $upload->mimeType,
            'size' => $upload->size,
            'uploadedAt' => $upload->uploadedAt->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR));

        return $upload;
    }

    public function find(string $token, int $userId): ?PendingImportUpload
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        $metadataPath = $this->metadataPath($userId, $token);
        if (!is_file($metadataPath) || !is_file($this->filePath($userId, $token))) {
            return null;
        }

        try {
            $metadata = json_decode((string) file_get_contents($metadataPath), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($metadata)
            || !\is_string($metadata['originalName'] ?? null)
            || !\is_string($metadata['mimeType'] ?? null)
            || !\is_int($metadata['size'] ?? null)
            || !\is_string($metadata['uploadedAt'] ?? null)) {
            return null;
        }

        $uploadedAt = \DateTimeImmutable::createFromFormat('!'.\DateTimeInterface::ATOM, $metadata['uploadedAt']);
        if (!$uploadedAt instanceof \DateTimeImmutable) {
            return null;
        }

        return new PendingImportUpload($token, $metadata['originalName'], $metadata['mimeType'], $metadata['size'], $uploadedAt);
    }

    /**
     * The stored file as an upload of the import form, so the form validates and moves it like a direct upload.
     * Test mode makes {@see UploadedFile::move()} rename the file instead of expecting a PHP upload.
     */
    public function uploadedFile(string $token, int $userId): ?UploadedFile
    {
        $upload = $this->find($token, $userId);
        if (!$upload instanceof PendingImportUpload) {
            return null;
        }

        return new UploadedFile($this->filePath($userId, $token), $upload->originalName, $upload->mimeType, \UPLOAD_ERR_OK, true);
    }

    /**
     * Removes an upload of this user; a missing or foreign token is ignored.
     */
    public function delete(string $token, int $userId): void
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return;
        }

        $this->filesystem->remove([$this->filePath($userId, $token), $this->metadataPath($userId, $token)]);
    }

    /**
     * Deletes uploads older than {@see RETENTION_HOURS}, judged by the file time so that a file without its
     * metadata is removed as well. Returns the number of deleted files.
     */
    public function purgeExpired(): int
    {
        $root = Path::join($this->baseDir, 'pending');
        if (!is_dir($root)) {
            return 0;
        }

        $cutoff = $this->clock->now()->modify(\sprintf('-%d hours', self::RETENTION_HOURS))->getTimestamp();
        $expired = [];
        foreach (new Finder()->files()->in($root)->depth(1) as $file) {
            if ($file->getMTime() < $cutoff) {
                $expired[] = $file->getPathname();
            }
        }

        $this->filesystem->remove($expired);

        return \count($expired);
    }

    private function userDirectory(int $userId): string
    {
        return Path::join($this->baseDir, 'pending', (string) $userId);
    }

    private function filePath(int $userId, string $token): string
    {
        return Path::join($this->userDirectory($userId), $token.'.upload');
    }

    private function metadataPath(int $userId, string $token): string
    {
        return Path::join($this->userDirectory($userId), $token.'.json');
    }
}
