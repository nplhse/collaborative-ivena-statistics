<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\PageTranslation;
use App\Shared\Application\Locale\SupportedLocales;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<PageTranslation>
 */
final class PageTranslationFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return PageTranslation::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $slug = self::faker()->unique()->slug(2);

        return [
            'page' => PageFactory::new()->withoutDefaultTranslation(),
            'locale' => SupportedLocales::DEFAULT,
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'path' => '/'.$slug,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'showToc' => false,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => ['html' => '<p>Beispielseite</p>'],
                ],
            ],
        ];
    }
}
