<?php

declare(strict_types=1);

namespace App\Content\Domain\Enum;

enum PageKey: string
{
    case Imprint = 'imprint';
    case Privacy = 'privacy';
    case Terms = 'terms';
    case About = 'about';
    case Features = 'features';
    case Faq = 'faq';

    public function translationKey(): string
    {
        return 'page.key.'.$this->value;
    }

    public function navIcon(): string
    {
        return match ($this) {
            self::About => 'tabler:users-group',
            self::Features => 'tabler:sparkles',
            self::Faq => 'tabler:info-square-rounded',
            default => 'tabler:file-text',
        };
    }
}
