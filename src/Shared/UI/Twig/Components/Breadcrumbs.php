<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Breadcrumbs', template: '@Shared/components/Breadcrumbs.html.twig')]
final class Breadcrumbs
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @var list<array{label:string, path?:string, icon?:string, label_params?:array<string, mixed>, label_domain?:string, translatable?:bool}>
     */
    public array $items = [];

    /**
     * @return list<array{label:string, path?:string, icon?:string, label_params?:array<string, mixed>, label_domain?:string, translatable?:bool}>
     */
    public function getFullItems(): array
    {
        $items = array_merge([
            [
                'label' => 'link.home',
                'label_domain' => 'shared',
                'path' => $this->urlGenerator->generate('app_default'),
                'icon' => 'tabler:home',
            ],
        ], $this->items);

        return array_map(
            $this->normalizeItem(...),
            $items,
        );
    }

    /**
     * @param array{label:string, path?:string, icon?:string, label_params?:array<string, mixed>, label_domain?:string, translatable?:bool} $item
     *
     * @return array{label:string, path?:string, icon?:string, label_params?:array<string, mixed>, label_domain?:string, translatable?:bool}
     */
    private function normalizeItem(array $item): array
    {
        if (!isset($item['translatable']) && !$this->looksLikeTranslationKey($item['label'])) {
            $item['translatable'] = false;
        }

        if (!isset($item['label_domain'])) {
            $item['label_domain'] = $this->resolveLabelDomain($item['label']);
        }

        return $item;
    }

    private function looksLikeTranslationKey(string $label): bool
    {
        if (!str_contains($label, '.')) {
            return false;
        }

        return array_any([
            'action.',
            'admin.',
            'allocation.',
            'blog.',
            'confirm.',
            'content.',
            'cookie.',
            'dashboard.',
            'email.',
            'engagement.',
            'error.',
            'feedback.',
            'field.',
            'flash.',
            'help.',
            'import.',
            'indication.',
            'label.',
            'link.',
            'locale.',
            'menu.',
            'monthly_reminder.',
            'onboarding.',
            'public.',
            'sitemap.',
            'stats.',
            'statistics.',
            'text.',
            'title.',
            'validation.',
        ], fn (string $prefix): bool => str_starts_with($label, $prefix));
    }

    private function resolveLabelDomain(string $label): string
    {
        if (str_starts_with($label, 'link.') || str_starts_with($label, 'sitemap.') || str_starts_with($label, 'cookie.')) {
            return 'shared';
        }

        if (str_starts_with($label, 'stats.') || str_starts_with($label, 'statistics.')) {
            return 'statistics';
        }

        if (str_starts_with($label, 'title.import.')) {
            return 'import';
        }

        if (str_starts_with($label, 'title.settings')) {
            return 'user';
        }

        foreach ([
            'blog.',
            'dashboard.',
            'public.',
            'error.blog.',
            'flash.blog.',
            'title.blog',
        ] as $prefix) {
            if (str_starts_with($label, $prefix)) {
                return 'content';
            }
        }

        foreach ([
            'title.indication.',
            'title.allocation.',
            'title.explore',
            'title.hospital',
            'title.mci_case.',
            'title.secondary_transport.',
            'title.hospitals.',
        ] as $prefix) {
            if (str_starts_with($label, $prefix)) {
                return 'allocation';
            }
        }

        return 'messages';
    }
}
