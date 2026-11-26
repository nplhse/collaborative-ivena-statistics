<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu\Inspection;

use App\Shared\Application\Navigation\Menu\LiteralLabel;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A navigation label as the admin navigation inspector shows it: the text in the current locale and where
 * it comes from.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the admin navigation template.
 */
final readonly class InspectedLabel
{
    /**
     * @param string      $source `message`, `literal` or the class of another translatable label
     * @param string|null $key    translation key of a message
     * @param string|null $domain translation domain of a message
     */
    private function __construct(
        public string $text,
        public string $source,
        public ?string $key = null,
        public ?string $domain = null,
    ) {
    }

    public static function of(?TranslatableInterface $label, TranslatorInterface $translator): ?self
    {
        if (!$label instanceof TranslatableInterface) {
            return null;
        }

        $text = $label->trans($translator);

        return match (true) {
            $label instanceof TranslatableMessage => new self($text, 'message', $label->getMessage(), $label->getDomain()),
            $label instanceof LiteralLabel => new self($text, 'literal'),
            default => new self($text, $label::class),
        };
    }
}
