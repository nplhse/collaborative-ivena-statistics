<?php

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Alert', template: '@Shared/components/Alert.html.twig')]
final class Alert
{
    public string $type = 'success';
    public string $message = '';
}
