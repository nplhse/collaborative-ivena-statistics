<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Slug\SlugGenerator;
use App\Content\Domain\Entity\Page;

final readonly class PagePathResolver
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private SlugGenerator $slugGenerator,
    ) {
    }

    public function synchronize(Page $page): void
    {
        $raw = trim((string) $page->getSlug());

        if ('' === $raw) {
            $page->setSlug($this->slugGenerator->normalize((string) $page->getTitle(), SlugGenerator::MAX_LENGTH_PAGE));
        } else {
            $page->setSlug($raw);
        }

        $page->setPath($this->buildPath($page));
    }

    public function buildPath(Page $page): string
    {
        $slug = (string) $page->getSlug();
        $parent = $page->getParent();

        if (!$parent instanceof Page) {
            return '/'.$slug;
        }

        $this->assertNoCycle($page);

        $parentPath = $parent->getPath();
        if (null === $parentPath || '' === $parentPath) {
            $parentPath = $this->buildPath($parent);
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
