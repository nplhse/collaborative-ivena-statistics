<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use App\Shared\Application\Locale\SupportedLocales;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'LocaleSwitcher', template: '@Shared/components/LocaleSwitcher.html.twig')]
final class LocaleSwitcher
{
    /** @var list<string> */
    public array $supportedLocales = SupportedLocales::ALL;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function targetPathFor(string $locale): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return '/';
        }

        $targets = $request->attributes->get('locale_switch_targets');
        if (\is_array($targets)) {
            $target = $targets[$locale] ?? null;
            if (\is_string($target) && str_starts_with($target, '/') && !str_starts_with($target, '//')) {
                return $target;
            }
        }

        return $request->getRequestUri();
    }
}
