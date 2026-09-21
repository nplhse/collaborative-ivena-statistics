<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'ConfirmModal', template: '@Shared/components/ConfirmModal.html.twig')]
final class ConfirmModal
{
    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $id;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $title;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $message;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $action;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $csrfToken;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $confirmLabel;

    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public string $cancelLabel;

    public string $size = 'sm';
}
