<?php

declare(strict_types=1);

namespace App\Content\Domain\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * An icon name like `tabler:file-import` that the icon set knows (#1590); an unknown icon would fail when the page is shown.
 * Empty values are valid.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class KnownIcon extends Constraint
{
    public string $formatMessage = 'page.validation.icon_format';

    public string $unknownMessage = 'page.validation.cards_unknown_icon';

    #[\Override]
    public function validatedBy(): string
    {
        return KnownIconValidator::class;
    }
}
