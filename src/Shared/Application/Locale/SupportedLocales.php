<?php

declare(strict_types=1);

namespace App\Shared\Application\Locale;

final class SupportedLocales
{
    public const string DEFAULT = 'en';

    public const string GERMAN = 'de';

    /** @var list<string> */
    public const array ALL = [self::DEFAULT, self::GERMAN];

    public static function isSupported(?string $locale): bool
    {
        return \in_array($locale, self::ALL, true);
    }

    public static function normalize(?string $locale): ?string
    {
        if (null === $locale || '' === $locale || '0' === $locale) {
            return null;
        }

        return self::isSupported($locale) ? $locale : null;
    }
}
