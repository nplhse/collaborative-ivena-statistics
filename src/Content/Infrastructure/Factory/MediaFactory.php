<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Media>
 */
final class MediaFactory extends PersistentObjectFactory
{
    private const string SAMPLE_PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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
            'filename' => 'sample-'.bin2hex(random_bytes(4)).'.png',
            'originalFilename' => 'sample.png',
            'mimeType' => 'image/png',
            'size' => 68,
            'type' => MediaType::IMAGE,
            'altText' => 'Sample image',
            'title' => 'Sample',
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        /** @var static $factory */
        $factory = $this->afterInstantiate(static function (Media $media): void {
            self::ensureFixtureFileExists($media);
        });

        return $factory;
    }

    /** @psalm-suppress PossiblyUnusedMethod Used in tests via Foundry state API */
    public function asPdf(): self
    {
        return $this->with([
            'filename' => 'sample-'.bin2hex(random_bytes(4)).'.pdf',
            'originalFilename' => 'sample.pdf',
            'mimeType' => 'application/pdf',
            'size' => 512,
            'type' => MediaType::PDF,
            'altText' => null,
            'title' => 'Sample PDF',
        ]);
    }

    private static function ensureFixtureFileExists(Media $media): void
    {
        $filename = $media->getFilename();
        if (null === $filename || '' === $filename) {
            return;
        }

        $projectDir = \dirname(__DIR__, 4);
        $targetDir = Path::join($projectDir, 'public', 'uploads', 'media');
        new Filesystem()->mkdir($targetDir);
        $target = Path::join($targetDir, $filename);

        if (is_file($target)) {
            return;
        }

        $bytes = MediaType::PDF === $media->getType()
            ? "%PDF-1.1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"
            : self::samplePngBytes();

        file_put_contents($target, $bytes);
    }

    private static function samplePngBytes(): string
    {
        $decoded = base64_decode(self::SAMPLE_PNG_BYTES, true);

        return is_string($decoded) ? $decoded : '';
    }
}
