<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

/**
 * The empty template and the example of the hospital's private local map (#1130), offered on the
 * tool's landing page; the files come from the contribution that defines the local map (#1301). Neither contains hospital-specific codes; the tool's tests load both with the
 * same validation as a hospital's own map.
 */
final readonly class LocalMapFiles
{
    public const string TEMPLATE = 'template';
    public const string EXAMPLE = 'example';
    public const array VARIANTS = [self::TEMPLATE, self::EXAMPLE];

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private DataPrepContributions $contributions,
    ) {
    }

    public function content(string $variant): string
    {
        if (!\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown local map file "%s".', $variant));
        }

        $map = $this->contributions->localMap() ?? throw new \RuntimeException('No data preparation contribution defines a local map.');

        return $this->contributions->read(self::TEMPLATE === $variant ? $map->templateFile : $map->exampleFile);
    }

    public function fileName(string $variant): string
    {
        return sprintf('encounter-local-map-%s.json', $variant);
    }
}
