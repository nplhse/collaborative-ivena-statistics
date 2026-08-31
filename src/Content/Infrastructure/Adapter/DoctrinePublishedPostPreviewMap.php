<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Adapter;

use App\Content\Application\Blog\PostContentSanitizer;
use App\Content\Infrastructure\Repository\PostRepository;
use App\User\Application\Contract\PublishedPostPreviewMapInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for PublishedPostPreviewMapInterface. */
#[AsAlias(PublishedPostPreviewMapInterface::class)]
final readonly class DoctrinePublishedPostPreviewMap implements PublishedPostPreviewMapInterface
{
    public function __construct(
        private PostRepository $postRepository,
        private PostContentSanitizer $sanitizer,
    ) {
    }

    #[\Override]
    public function forSlugs(array $slugs): array
    {
        $normalized = [];
        foreach ($slugs as $slug) {
            if (!\is_string($slug) || '' === $slug) {
                continue;
            }

            $normalized[] = $slug;
        }

        $posts = $this->postRepository->findPublishedBySlugs(array_values(array_unique($normalized)));
        $previews = [];
        foreach ($posts as $post) {
            $slug = $post->getSlug();
            if (!\is_string($slug) || '' === $slug) {
                continue;
            }

            $preview = $this->sanitizer->compactPreview((string) $post->getContent());
            if ('' === $preview) {
                continue;
            }

            $previews[$slug] = $preview;
        }

        return $previews;
    }
}
