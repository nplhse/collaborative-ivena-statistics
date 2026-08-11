<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Shared\Application\Locale\SupportedLocales;
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
        $slug = self::faker()->unique()->slug(2);
        $title = ucfirst(str_replace('-', ' ', $slug));
        $content = [
            [
                'type' => 'richtext',
                'enabled' => true,
                'data' => ['html' => '<p>Beispielseite</p>'],
            ],
        ];

        return [
            // Transitional legacy columns (kept in sync with default-locale translation).
            'title' => $title,
            'slug' => $slug,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 0,
            'content' => $content,
            'path' => '/'.$slug,
        ];
    }

    /**
     * Skip automatic default-locale translation (e.g. when PageTranslationFactory owns creation).
     */
    public function withoutDefaultTranslation(): static
    {
        /** @var static $factory */
        $factory = $this->afterInstantiate(static function (Page $page): void {
            foreach ([...$page->getTranslations()] as $translation) {
                $page->removeTranslation($translation);
            }
        });

        return $factory;
    }

    #[\Override]
    protected function initialize(): static
    {
        /** @var static $factory */
        $factory = $this->afterInstantiate(function (Page $page): void {
            // Keep transitional legacy path aligned with slug for roots; child translation paths
            // are rebuilt by PagePathSubscriber on flush.
            if (!$page->getParent() instanceof Page) {
                /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
                $page->setPath('/'.ltrim((string) $page->getSlug(), '/'));
            }

            if ($page->getTranslations()->count() > 0) {
                return;
            }

            $translation = new PageTranslation();
            $translation->setLocale(SupportedLocales::DEFAULT);
            /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
            $translation->setTitle((string) $page->getTitle());
            /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
            $translation->setSlug((string) $page->getSlug());
            /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
            $translation->setPath((string) $page->getPath());
            /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
            $translation->setStatus($page->getStatus());
            /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
            $translation->setContent($page->getContent());
            $page->addTranslation($translation);
        });

        return $factory;
    }
}
