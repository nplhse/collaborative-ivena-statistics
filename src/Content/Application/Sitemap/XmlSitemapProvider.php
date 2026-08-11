<?php

declare(strict_types=1);

namespace App\Content\Application\Sitemap;

use App\Content\Application\Sitemap\DTO\XmlSitemapUrl;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Entity\Post;
use App\Content\Infrastructure\Repository\PageTranslationRepository;
use App\Content\Infrastructure\Repository\PostRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class XmlSitemapProvider
{
    public function __construct(
        private PageTranslationRepository $pageTranslationRepository,
        private PostRepository $postRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<XmlSitemapUrl>
     */
    public function getUrls(): array
    {
        $urlsByLoc = [];

        $this->addUrl(
            $urlsByLoc,
            $this->urlGenerator->generate('app_default', [], UrlGeneratorInterface::ABSOLUTE_URL),
        );
        $this->addUrl(
            $urlsByLoc,
            $this->urlGenerator->generate('app_blog_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        foreach ($this->pageTranslationRepository->findAllPublishedPublic() as $translation) {
            $loc = $this->buildPageTranslationUrl($translation);
            if (null === $loc) {
                continue;
            }

            $lastmod = $translation->getUpdatedAt() ?? $translation->getCreatedAt();
            $this->addUrl($urlsByLoc, $loc, $lastmod->format('Y-m-d'));
        }

        foreach ($this->postRepository->findPublishedForIndex() as $post) {
            $loc = $this->buildPostUrl($post);
            if (null === $loc) {
                continue;
            }

            $lastmod = $post->getUpdatedAt() ?? $post->getPublishedAt();
            $this->addUrl(
                $urlsByLoc,
                $loc,
                $lastmod instanceof \DateTimeImmutable ? $lastmod->format('Y-m-d') : null,
            );
        }

        return array_values($urlsByLoc);
    }

    /**
     * @param array<string, XmlSitemapUrl> $urlsByLoc
     */
    private function addUrl(array &$urlsByLoc, string $loc, ?string $lastmod = null): void
    {
        if (isset($urlsByLoc[$loc])) {
            return;
        }

        $urlsByLoc[$loc] = new XmlSitemapUrl($loc, $lastmod);
    }

    private function buildPageTranslationUrl(PageTranslation $translation): ?string
    {
        $pathSegment = trim((string) $translation->getPath(), '/');
        if ('' === $pathSegment) {
            return null;
        }

        return $this->urlGenerator->generate(
            'app_page_show',
            ['path' => $pathSegment],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function buildPostUrl(Post $post): ?string
    {
        $slug = $post->getSlug();
        if (null === $slug || '' === $slug) {
            return null;
        }

        return $this->urlGenerator->generate(
            'app_blog_show',
            ['slug' => $slug],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
