<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Card', template: '@Shared/components/Card.html.twig')]
final class Card
{
    private const array SIZES = ['sm', 'md', 'lg'];

    private const array STATUSES = ['primary', 'success', 'info', 'warning', 'danger', 'secondary'];

    /** @psalm-suppress PossiblyUnusedProperty Consumed by Card.html.twig. */
    public ?string $title = null;

    public ?string $size = null;

    public string $padding = 'default';

    public ?string $status = null;

    public ?string $class = null;

    public ?string $headerClass = null;

    public ?string $bodyClass = null;

    public ?string $footerClass = null;

    public ?string $href = null;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by Card.html.twig. */
    public bool $body = true;

    public function getCssClass(): string
    {
        $classes = ['card'];

        if ($this->hasHref()) {
            $classes[] = 'card-link';
        }

        $size = $this->normalizedSize();
        if (null !== $size) {
            $classes[] = 'card-'.$size;
        }

        if (null !== $this->class && '' !== trim($this->class)) {
            $classes[] = trim($this->class);
        }

        return implode(' ', $classes);
    }

    public function getRootTag(): string
    {
        return $this->hasHref() ? 'a' : 'div';
    }

    /**
     * @return array<string, string>
     */
    public function getRootAttributes(): array
    {
        $attributes = ['class' => $this->getCssClass()];

        if ($this->hasHref()) {
            $attributes['href'] = trim((string) $this->href);
        }

        return $attributes;
    }

    public function getHeaderCssClass(): string
    {
        return $this->appendClass('card-header', $this->headerClass);
    }

    public function getBodyCssClass(): string
    {
        $base = 'none' === strtolower($this->padding) ? 'card-body p-0' : 'card-body';

        return $this->appendClass($base, $this->bodyClass);
    }

    public function getFooterCssClass(): string
    {
        return $this->appendClass('card-footer', $this->footerClass);
    }

    public function getStatusClass(): ?string
    {
        $status = $this->normalizedStatus();
        if (null === $status) {
            return null;
        }

        return 'card-status-top bg-'.$status;
    }

    private function hasHref(): bool
    {
        return null !== $this->href && '' !== trim($this->href);
    }

    private function appendClass(string $base, ?string $extra): string
    {
        if (null === $extra || '' === trim($extra)) {
            return $base;
        }

        return $base.' '.trim($extra);
    }

    private function normalizedSize(): ?string
    {
        if (null === $this->size || '' === $this->size) {
            return null;
        }

        $normalized = strtolower($this->size);

        return \in_array($normalized, self::SIZES, true) ? $normalized : null;
    }

    private function normalizedStatus(): ?string
    {
        if (null === $this->status || '' === $this->status) {
            return null;
        }

        $normalized = strtolower($this->status);

        return \in_array($normalized, self::STATUSES, true) ? $normalized : null;
    }
}
