<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Internal numeric ID of an object, shown to administrators only (#1354). Rendered as the first
 * item of a detail page's header metadata (`list-inline list-inline-dots`).
 */
#[AsTwigComponent(name: 'AdminObjectId', template: '@Shared/components/AdminObjectId.html.twig')]
final class AdminObjectId
{
    public ?int $id = null;
}
