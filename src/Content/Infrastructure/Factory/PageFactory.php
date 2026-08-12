<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Factory;

use App\Content\Domain\Entity\Page;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Page>
 */
final class PageFactory extends PersistentObjectFactory
{
    /**
     * Convenience attributes forwarded to the default PageTranslation.
     * They are not Page fields — Instantiator allows them as extras.
     */
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
        return [
            'visibility' => Page::VISIBILITY_PUBLIC,
            'sortOrder' => 0,
        ];
    }

    /**
     * Skip automatic default-locale translation (e.g. when PageTranslationFactory owns creation).
     */
    public function withoutDefaultTranslation(): static
    {
        /** @var static $factory */
        $factory = $this->afterInstantiate(static function (object $object): void {
            if (!$object instanceof Page) {
                return;
            }

            foreach ([...$object->getTranslations()] as $translation) {
                $object->removeTranslation($translation);
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
            ->afterInstantiate(self::addDefaultTranslation(...));

        return $factory;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function addDefaultTranslation(object $object, array $attributes): void
    {
        if (!$object instanceof Page || $object->getTranslations()->count() > 0) {
            return;
        }

        $translationAttributes = array_intersect_key($attributes, array_flip(self::TRANSLATION_ATTRIBUTE_KEYS));
        if (
            \array_key_exists('slug', $translationAttributes)
            && !\array_key_exists('path', $translationAttributes)
            && !$object->getParent() instanceof Page
        ) {
            $translationAttributes['path'] = '/'.ltrim((string) $translationAttributes['slug'], '/');
        }

        PageTranslationFactory::new()
            ->withoutPersisting()
            ->create(['page' => $object, ...$translationAttributes]);
    }
}
