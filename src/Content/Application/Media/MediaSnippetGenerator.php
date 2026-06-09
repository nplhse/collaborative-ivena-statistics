<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;

final readonly class MediaSnippetGenerator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private MediaPublicUrlResolver $publicUrlResolver,
        private MediaImageFigureHtmlBuilder $imageFigureHtmlBuilder,
    ) {
    }

    public function getPublicUrl(Media $media): string
    {
        return $this->publicUrlResolver->resolve($media);
    }

    public function generateHtml(Media $media): string
    {
        if (MediaType::IMAGE === $media->getType()) {
            return $this->generateImageFigureHtml($media);
        }

        $url = htmlspecialchars($this->getPublicUrl($media), ENT_QUOTES | ENT_HTML5);
        $label = htmlspecialchars($this->resolveLinkLabel($media), ENT_QUOTES | ENT_HTML5);

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            $url,
            $label,
        );
    }

    public function generateImageFigureHtml(
        Media $media,
        string $size = 'auto',
        string $float = 'none',
    ): string {
        return $this->imageFigureHtmlBuilder->build(
            $this->getPublicUrl($media),
            $this->resolveAltText($media),
            $size,
            $float,
            $media->getWidth(),
            $media->getHeight(),
        );
    }

    private function resolveAltText(Media $media): string
    {
        $alt = trim($media->getAltText() ?? '');
        if ('' !== $alt) {
            return $alt;
        }

        $title = trim($media->getTitle() ?? '');
        if ('' !== $title) {
            return $title;
        }

        return (string) $media->getOriginalFilename();
    }

    private function resolveLinkLabel(Media $media): string
    {
        $title = trim($media->getTitle() ?? '');
        if ('' !== $title) {
            return $title;
        }

        return $media->getOriginalFilename() ?? 'PDF ansehen';
    }
}
