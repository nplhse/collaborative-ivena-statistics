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
    private const array TRANSLATION_ATTRIBUTE_KEYS = [
        'title',
        'slug',
        'path',
        'status',
        'content',
        'showToc',
        'locale',
    ];

    /** @var array<string, mixed> */
    private array $translationAttributes = [];

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

        // Convenience attributes are stripped in beforeInstantiate and applied to PageTranslation.
        return [
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 0,
            'title' => $title,
            'slug' => $slug,
            'path' => '/'.$slug,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => ['html' => '<p>Beispielseite</p>'],
                ],
            ],
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
        $factory = $this
            ->beforeInstantiate(function (array $attributes): array {
                $this->translationAttributes = [];
                foreach (self::TRANSLATION_ATTRIBUTE_KEYS as $key) {
                    if (\array_key_exists($key, $attributes)) {
                        $this->translationAttributes[$key] = $attributes[$key];
                        unset($attributes[$key]);
                    }
                }

                return $attributes;
            })
            ->afterInstantiate(function (Page $page): void {
                if ($page->getTranslations()->count() > 0) {
                    $this->translationAttributes = [];

                    return;
                }

                $attrs = $this->translationAttributes;
                $this->translationAttributes = [];
                if ([] === $attrs) {
                    return;
                }

                $slug = (string) ($attrs['slug'] ?? self::faker()->unique()->slug(2));
                $title = (string) ($attrs['title'] ?? ucfirst(str_replace('-', ' ', $slug)));
                $path = (string) ($attrs['path'] ?? '/'.ltrim($slug, '/'));
                if (!$page->getParent() instanceof Page) {
                    $path = '/'.ltrim($slug, '/');
                }

                $translation = new PageTranslation();
                $translation->setLocale((string) ($attrs['locale'] ?? SupportedLocales::DEFAULT));
                $translation->setTitle($title);
                $translation->setSlug($slug);
                $translation->setPath($path);
                $translation->setStatus((string) ($attrs['status'] ?? PageTranslation::STATUS_PUBLISHED));
                /** @var list<array{type: string, data: array<string, mixed>, enabled?: bool}> $content */
                $content = \is_array($attrs['content'] ?? null) ? $attrs['content'] : [];
                $translation->setContent($content);
                if (\array_key_exists('showToc', $attrs)) {
                    $translation->setShowToc((bool) $attrs['showToc']);
                }
                $page->addTranslation($translation);
            });

        return $factory;
    }
}
