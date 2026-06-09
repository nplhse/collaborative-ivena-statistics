<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Page\DTO\PageImagePresentation;

final readonly class PageImageBlockPresenter
{
    /** @var list<string> */
    private const array ALLOWED_SIZES = ['auto', 'sm', 'md', 'lg'];

    /** @var list<string> */
    private const array ALLOWED_FLOATS = ['none', 'left', 'right'];

    /**
     * @param array<string, mixed> $data
     */
    public function present(array $data): PageImagePresentation
    {
        $size = $this->normalizeSize($data);
        $float = $this->normalizeFloat($data);

        $classes = ['card', 'card-sm', 'page-content-image', 'page-content-image--size-'.$size, 'mb-0'];

        if ('left' === $float) {
            $classes = [...$classes, 'page-content-image--float-left', 'float-start', 'me-3', 'mb-3'];
        } elseif ('right' === $float) {
            $classes = [...$classes, 'page-content-image--float-right', 'float-end', 'ms-3', 'mb-3'];
        }

        $imgWidth = $this->extractPositiveInt($data['width'] ?? null);
        $imgHeight = $this->extractPositiveInt($data['height'] ?? null);

        return new PageImagePresentation($classes, $imgWidth, $imgHeight);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalizeSize(array $data): string
    {
        $size = $data['size'] ?? null;
        if (is_string($size) && in_array($size, self::ALLOWED_SIZES, true)) {
            return $size;
        }

        $preset = (string) ($data['widthPreset'] ?? '');

        return match ($preset) {
            'sm' => 'sm',
            'md' => 'md',
            'lg' => 'lg',
            default => 'auto',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalizeFloat(array $data): string
    {
        $float = (string) ($data['float'] ?? 'none');

        return in_array($float, self::ALLOWED_FLOATS, true) ? $float : 'none';
    }

    private function extractPositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
