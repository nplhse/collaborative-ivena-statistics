<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Infrastructure\Media\MediaStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Media items with a real file (ADR 039): by default a sample file in the media storage. With a `filename` the item
 * is one not yet moved from the old upload directory, and the sample file is written there ({@see legacy()}).
 *
 * @extends PersistentObjectFactory<Media>
 */
final class MediaFactory extends PersistentObjectFactory
{
    private const string SAMPLE_PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const string SAMPLE_PDF_BYTES = "%PDF-1.1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    /** @psalm-suppress PossiblyUnusedMethod Foundry creates the factory as a service */
    public function __construct(
        private readonly MediaStorage $storage,
        #[Autowire(param: 'app.media_legacy_dir')]
        private readonly string $legacyDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    public static function class(): string
    {
        return Media::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'originalFilename' => 'sample.png',
            'mimeType' => 'image/png',
            'type' => MediaType::IMAGE,
            'altText' => 'Sample image',
            'title' => 'Sample',
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        /** @var static $factory */
        $factory = $this->afterInstantiate(function (Media $media): void {
            $this->ensureFileExists($media);
        });

        return $factory;
    }

    /** @psalm-suppress PossiblyUnusedMethod Used in tests via Foundry state API */
    public function asPdf(): self
    {
        return $this->with([
            'originalFilename' => 'sample.pdf',
            'mimeType' => 'application/pdf',
            'type' => MediaType::PDF,
            'altText' => null,
            'title' => 'Sample PDF',
        ]);
    }

    /**
     * A media item still in the old upload directory, as before the move to the storage.
     *
     * @psalm-suppress PossiblyUnusedMethod Used in tests via Foundry state API
     */
    public function legacy(?string $filename = null): self
    {
        return $this->with(['filename' => $filename ?? 'sample-'.bin2hex(random_bytes(4)).'.png']);
    }

    private function ensureFileExists(Media $media): void
    {
        $bytes = MediaType::PDF === $media->getType() ? self::SAMPLE_PDF_BYTES : $this->samplePngBytes();
        $filename = $media->getFilename();

        if (null !== $filename && '' !== $filename) {
            $target = Path::join($this->legacyDir, $filename);
            if (!is_file($target)) {
                new Filesystem()->dumpFile($target, $bytes);
            }
            $media->setSize($media->getSize() ?? \strlen($bytes));

            return;
        }

        if (null !== $media->getStorageKey()) {
            return;
        }

        $source = new Filesystem()->tempnam(sys_get_temp_dir(), 'media-fixture-');
        try {
            file_put_contents($source, $bytes);
            $stored = $this->storage->store($source, MediaType::PDF === $media->getType() ? 'pdf' : 'png', $media->getCreatedAt());
        } finally {
            new Filesystem()->remove($source);
        }

        $media->attachStoredFile($stored->storageKey, $stored->size, $stored->checksumSha256, $media->getCreatedAt());
    }

    private function samplePngBytes(): string
    {
        $decoded = base64_decode(self::SAMPLE_PNG_BYTES, true);

        return \is_string($decoded) ? $decoded : '';
    }
}
