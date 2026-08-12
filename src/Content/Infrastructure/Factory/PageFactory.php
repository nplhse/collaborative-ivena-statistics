<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Shared\Application\Locale\SupportedLocales;
use Zenstruck\Foundry\Object\Instantiator;
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

        // Convenience attributes are allowed as extras and applied to PageTranslation in afterInstantiate.
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
        $factory = $this->afterInstantiate(static function (object $page): void {
            if (!$page instanceof Page) {
                return;
            }

            foreach ([...$page->getTranslations()] as $translation) {
                $page->removeTranslation($translation);
            }
        });

        return $factory;
    }

    /** @psalm-suppress MoreSpecificReturnType */
    #[\Override]
    protected function initialize(): static
    {
        /** @var static $factory */
        $factory = $this
            ->instantiateWith(Instantiator::withConstructor()->allowExtra(...self::TRANSLATION_ATTRIBUTE_KEYS))
            ->afterInstantiate(function (object $page, array $attributes): void {
                if (!$page instanceof Page || $page->getTranslations()->count() > 0) {
                    return;
                }

                $attrs = [];
                foreach (self::TRANSLATION_ATTRIBUTE_KEYS as $key) {
                    if (\array_key_exists($key, $attributes)) {
                        $attrs[$key] = $attributes[$key];
                    }
                }

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
                $translation->setContent($this->normalizeTranslationContent($attrs['content'] ?? null));
                if (\array_key_exists('showToc', $attrs)) {
                    $translation->setShowToc((bool) $attrs['showToc']);
                }
                $page->addTranslation($translation);
            });

        return $factory;
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>, enabled?: bool}>
     */
    private function normalizeTranslationContent(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $content = [];
        foreach ($value as $block) {
            if (!\is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            $data = $block['data'] ?? null;
            if (!\is_string($type) || !\is_array($data)) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $item = [
                'type' => $type,
                'data' => $data,
            ];
            if (\array_key_exists('enabled', $block)) {
                $item['enabled'] = (bool) $block['enabled'];
            }
            $content[] = $item;
        }

        return $content;
    }
}
