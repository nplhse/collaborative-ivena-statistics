<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Page;
use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class PagePathResolver
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private SluggerInterface $slugger,
    ) {
    }

    public function normalizeSlug(?string $value): string
    {
        $slug = strtolower($this->slugger->slug((string) $value)->toString());

        if ('' === $slug) {
            throw new \InvalidArgumentException('Slug must not be empty.');
        }

        return $slug;
    }

    public function synchronize(Page $page): void
    {
        $page->setSlug($this->normalizeSlug($page->getSlug()));
        $page->setPath($this->buildPath($page));
    }

    public function buildPath(Page $page): string
    {
        $slug = $this->normalizeSlug($page->getSlug());
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
