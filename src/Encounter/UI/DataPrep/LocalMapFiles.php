<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The empty template and the example of the hospital's private local map (#1130), offered on the
 * tool's landing page. Neither contains hospital-specific codes; the tool's tests load both with the
 * same validation as a hospital's own map.
 */
final readonly class LocalMapFiles
{
    public const string TEMPLATE = 'template';
    public const string EXAMPLE = 'example';
    public const array VARIANTS = [self::TEMPLATE, self::EXAMPLE];

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Encounter/UI/DataPrep/local-map')]
        private string $directory,
    ) {
    }

    public function content(string $variant): string
    {
        if (!\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown local map file "%s".', $variant));
        }

        $content = file_get_contents($this->directory.'/'.$variant.'.json');
        if (false === $content) {
            throw new \RuntimeException(sprintf('Cannot read local map file "%s".', $variant));
        }

        return $content;
    }

    public function fileName(string $variant): string
    {
        return sprintf('encounter-local-map-%s.json', $variant);
    }
}
