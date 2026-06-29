<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use App\Shared\Application\Locale\SupportedLocales;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'LocaleSwitcher', template: '@Shared/components/LocaleSwitcher.html.twig')]
final class LocaleSwitcher
{
    /** @var list<string> */
    public array $supportedLocales = SupportedLocales::ALL;
}
