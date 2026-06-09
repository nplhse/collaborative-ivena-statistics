<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

final readonly class MediaImageFigureHtmlBuilder
{
    public function build(
        string $url,
        string $alt,
        string $size = 'auto',
        string $float = 'none',
        ?int $width = null,
        ?int $height = null,
    ): string {
        $size = \in_array($size, ['auto', 'sm', 'md', 'lg'], true) ? $size : 'auto';
        $float = \in_array($float, ['none', 'left', 'right'], true) ? $float : 'none';

        $classes = ['card', 'card-sm', 'page-content-image', 'page-content-image--size-'.$size, 'mb-0'];

        if ('left' === $float) {
            $classes = [...$classes, 'page-content-image--float-left', 'float-start', 'me-3', 'mb-3'];
        } elseif ('right' === $float) {
            $classes = [...$classes, 'page-content-image--float-right', 'float-end', 'ms-3', 'mb-3'];
        }

        $classAttr = htmlspecialchars(implode(' ', $classes), ENT_QUOTES | ENT_HTML5);
        $urlEsc = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);
        $altEsc = htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5);
        $dimensionAttrs = $this->buildDimensionAttributes($width, $height);

        return sprintf(
            '<figure class="%s"><a href="%s" data-fslightbox="content-gallery" class="d-block page-content-image__link"><img class="card-img-top" src="%s" alt="%s"%s loading="lazy"></a></figure>',
            $classAttr,
            $urlEsc,
            $urlEsc,
            $altEsc,
            $dimensionAttrs,
        );
    }

    private function buildDimensionAttributes(?int $width, ?int $height): string
    {
        if (null === $width || $width <= 0 || null === $height || $height <= 0) {
            return '';
        }

        return sprintf(' width="%d" height="%d"', $width, $height);
    }
}
