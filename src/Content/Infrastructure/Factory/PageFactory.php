<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\Page;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Page>
 */
final class PageFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Page::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $slug = self::faker()->slug(2);

        return [
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 0,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => ['html' => '<p>Beispielseite</p>'],
                ],
            ],
            'path' => '/'.$slug,
        ];
    }
}
