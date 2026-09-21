<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Alert', template: '@Shared/components/Alert.html.twig')]
final class Alert
{
    private const array VARIANT_ALIASES = [
        'error' => 'danger',
        'validation' => 'danger',
    ];

    private const array VARIANTS = ['info', 'success', 'warning', 'danger'];

    private const array AUTO_ICONS = [
        'info' => 'tabler:info-circle',
        'success' => 'tabler:circle-check',
        'warning' => 'tabler:alert-triangle',
        'danger' => 'tabler:alert-circle',
    ];

    public string $type = 'info';

    /** @psalm-suppress PossiblyUnusedProperty Consumed by Alert.html.twig. */
    public string $message = '';

    /** @psalm-suppress PossiblyUnusedProperty Consumed by Alert.html.twig. */
    public ?string $title = null;

    public string $icon = 'auto';

    public bool $dismissible = false;

    public bool $important = false;

    public function getVariant(): string
    {
        $normalized = strtolower($this->type);
        $variant = self::VARIANT_ALIASES[$normalized] ?? $normalized;

        return \in_array($variant, self::VARIANTS, true) ? $variant : 'info';
    }

    public function getCssClass(): string
    {
        $classes = ['alert', 'alert-'.$this->getVariant()];

        if ($this->dismissible) {
            $classes[] = 'alert-dismissible';
        }

        if ($this->important) {
            $classes[] = 'alert-important';
        }

        return implode(' ', $classes);
    }

    public function getIconName(): ?string
    {
        if ('none' === $this->icon || '' === $this->icon) {
            return null;
        }

        if ('auto' === $this->icon) {
            return self::AUTO_ICONS[$this->getVariant()];
        }

        return $this->icon;
    }

    public function getRole(): string
    {
        return \in_array($this->getVariant(), ['danger', 'warning'], true) ? 'alert' : 'status';
    }
}
