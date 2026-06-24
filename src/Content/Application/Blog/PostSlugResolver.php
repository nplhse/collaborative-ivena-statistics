<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use App\Content\Application\Slug\SlugGenerator;
use App\Content\Domain\Entity\Post;

final readonly class PostSlugResolver
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private SlugGenerator $slugGenerator,
        private PostSlugExistsChecker $postSlugExistsChecker,
    ) {
    }

    public function resolve(Post $post, ?int $excludeId = null): void
    {
        $submitted = trim((string) $post->getSlug());

        if ('' === $submitted) {
            $slug = $this->slugGenerator->normalize((string) $post->getTitle(), SlugGenerator::MAX_LENGTH_POST);
            $slug = $this->slugGenerator->ensureUnique(
                $slug,
                fn (string $candidate): bool => $this->postSlugExistsChecker->slugExists($candidate, $excludeId),
                SlugGenerator::MAX_LENGTH_POST,
            );
        } else {
            $slug = $submitted;
        }

        $post->setSlug($slug);
    }
}
