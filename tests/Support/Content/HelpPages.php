<?php

declare(strict_types=1);

namespace App\Tests\Support\Content;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Domain\Enum\PageType;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\Shared\Application\Locale\SupportedLocales;

/**
 * Builds a help section (#596) for tests: the start page and articles with explicit paths and text.
 */
final class HelpPages
{
    public static function root(string $html = '<p>Help start</p>'): Page
    {
        return PageFactory::createOne([
            'title' => 'Help',
            'slug' => 'help',
            'path' => '/help',
            'key' => PageKey::Help,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [
                ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => $html]],
                ['type' => 'help_search', 'enabled' => true, 'data' => []],
                ['type' => 'help_topics', 'enabled' => true, 'data' => []],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes extra page or translation attributes (helpKey, visibility, status, …)
     */
    public static function article(Page $parent, string $title, string $path, string $html, int $sortOrder = 0, array $attributes = []): Page
    {
        return PageFactory::createOne([
            'title' => $title,
            'slug' => basename($path),
            'path' => $path,
            'parent' => $parent,
            'type' => PageType::Help,
            'sortOrder' => $sortOrder,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'content' => [['type' => 'richtext', 'enabled' => true, 'data' => ['html' => $html]]],
            ...$attributes,
        ]);
    }

    public static function german(Page $page, string $title, string $path, string $html, string $status = PageTranslation::STATUS_PUBLISHED): void
    {
        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => $title,
            'slug' => basename($path),
            'path' => $path,
            'status' => $status,
            'content' => [['type' => 'richtext', 'enabled' => true, 'data' => ['html' => $html]]],
        ]);
    }
}
