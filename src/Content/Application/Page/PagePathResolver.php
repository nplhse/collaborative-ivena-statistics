<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Slug\SlugGenerator;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;

final readonly class PagePathResolver
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private SlugGenerator $slugGenerator,
    ) {
    }

    public function synchronize(PageTranslation $translation): void
    {
        $raw = trim((string) $translation->getSlug());

        if ('' === $raw) {
            $translation->setSlug($this->slugGenerator->normalize((string) $translation->getTitle(), SlugGenerator::MAX_LENGTH_PAGE));
        } else {
            $translation->setSlug($raw);
        }

        $translation->setPath($this->buildPath($translation));
    }

    public function buildPath(PageTranslation $translation): string
    {
        $slug = (string) $translation->getSlug();
        $page = $translation->getPage();
        $locale = (string) $translation->getLocale();

        if (!$page instanceof Page) {
            return '/'.$slug;
        }

        $parent = $page->getParent();
        if (!$parent instanceof Page) {
            return '/'.$slug;
        }

        $this->assertNoCycle($page);

        $parentTranslation = $parent->translation($locale);
        if (!$parentTranslation instanceof PageTranslation) {
            throw new \InvalidArgumentException(sprintf('Cannot build path for page translation locale "%s": parent page has no translation in that locale.', $locale));
        }

        $parentPath = $parentTranslation->getPath();
        if (null === $parentPath || '' === $parentPath) {
            $parentPath = $this->buildPath($parentTranslation);
        }

        return rtrim($parentPath, '/').'/'.$slug;
    }

    private function assertNoCycle(Page $page): void
    {
        $current = $page->getParent();
        $seen = [];

        while ($current instanceof Page) {
            $id = spl_object_id($current);

            if (isset($seen[$id])) {
                throw new \InvalidArgumentException('Cyclic parent reference detected.');
            }

            $seen[$id] = true;
            if ($current === $page) {
                throw new \InvalidArgumentException('A page cannot be its own ancestor.');
            }

            $current = $current->getParent();
        }
    }
}
