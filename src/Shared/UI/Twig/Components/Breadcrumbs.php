<?php

namespace App\Shared\UI\Twig\Components;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Breadcrumbs', template: '@Shared/components/Breadcrumbs.html.twig')]
final class Breadcrumbs
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @var list<array{label:string, path?:string, icon?:string}>
     */
    public array $items = [];

    /**
     * @return list<array{label:string, path?:string, icon?:string}>
     */
    public function getFullItems(): array
    {
        return array_merge([
            [
                'label' => 'link.home',
                'path' => $this->urlGenerator->generate('app_default'),
                'icon' => 'tabler:home',
            ],
        ], $this->items);
    }
}
