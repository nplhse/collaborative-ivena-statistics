<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Badge', template: '@Shared/components/Badge.html.twig')]
final class Badge
{
    private const array VARIANTS = ['secondary', 'green', 'red', 'yellow', 'azure', 'purple', 'blue', 'orange'];

    public string $variant = 'secondary';

    public string $label = '';

    public function getVariant(): string
    {
        $normalized = strtolower($this->variant);

        return \in_array($normalized, self::VARIANTS, true) ? $normalized : 'secondary';
    }

    public function getCssClass(): string
    {
        $variant = $this->getVariant();

        return \sprintf('badge bg-%s-lt text-%s-lt-fg', $variant, $variant);
    }
}
