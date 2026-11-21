<?php

declare(strict_types=1);

namespace App\Content\Domain\Enum;

/**
 * Kind of an editorial page (#596). Help articles live below the help start page ({@see PageKey::Help}); they are left
 * out of the general page navigation and searched by the help search.
 */
enum PageType: string
{
    case Page = 'page';
    case Help = 'help';

    public function translationKey(): string
    {
        return 'page.type.'.$this->value;
    }
}
