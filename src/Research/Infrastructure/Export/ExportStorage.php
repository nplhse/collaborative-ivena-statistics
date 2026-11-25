<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Export;

use App\Research\Application\Export\ExportStorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Files of export runs under `var/research/exports` (ADR 045): one working directory per run while it writes, then one
 * `.tar.gz` archive with an unguessable name until the cleanup removes it. Archives are served only through the
 * download controller.
 *
 * @psalm-suppress UnusedClass Autowired through its interface.
 */
final readonly class ExportStorage implements ExportStorageInterface
{
    private Filesystem $filesystem;

    public function __construct(
        #[Autowire(param: 'app.research.exports_dir')]
        private string $directory,
    ) {
        $this->filesystem = new Filesystem();
    }

    #[\Override]
    public function workDirectory(string $key): string
    {
        $path = $this->workPath($key);
        $this->filesystem->remove($path);
        $this->filesystem->mkdir($path, 0o700);

        return $path;
    }

    #[\Override]
    public function pack(string $key, string $folder, array $files, array $extras): string
    {
        $name = bin2hex(random_bytes(16));
        $tarPath = $this->directory.'/'.$name.'.tar';
        $this->filesystem->mkdir($this->directory, 0o700);

        $tar = new \PharData($tarPath);
        foreach ($files as $file) {
            $tar->addFile($file->path, $folder.'/'.$file->fileName());
        }

        foreach ($extras as $fileName => $content) {
            $tar->addFromString($folder.'/'.$fileName, $content);
        }

        $tar->compress(\Phar::GZ);
        unset($tar);
        $this->filesystem->remove($tarPath);
        $this->discard($key);

        return $name.'.tar.gz';
    }

    #[\Override]
    public function path(string $archiveFile): ?string
    {
        if (1 !== preg_match('/^[a-f0-9]{32}\.tar\.gz$/', $archiveFile)) {
            return null;
        }

        $path = $this->directory.'/'.$archiveFile;

        return is_file($path) ? $path : null;
    }

    #[\Override]
    public function discard(string $key): void
    {
        $this->filesystem->remove($this->workPath($key));
    }

    private function workPath(string $key): string
    {
        return $this->directory.'/work/'.preg_replace('/[^a-z0-9_-]/i', '', $key);
    }
}
