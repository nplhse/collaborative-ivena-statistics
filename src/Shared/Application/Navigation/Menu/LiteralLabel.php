<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A label that is already in the reader's language, for example the title of a CMS page translation.
 */
final readonly class LiteralLabel implements TranslatableInterface
{
    public function __construct(private string $text)
    {
    }

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->text;
    }
}
