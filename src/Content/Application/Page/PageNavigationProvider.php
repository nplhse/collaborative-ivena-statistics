<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Page\DTO\PageNavigationLink;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Repository\PageRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class PageNavigationProvider
{
    /** @var list<PageKey> */
    private const array GUEST_ONLY_HEADER_KEYS = [
        PageKey::About,
        PageKey::Features,
    ];

    /** @var list<PageKey> */
    private const array SHARED_HEADER_KEYS = [
        PageKey::Faq,
    ];

    /** @var array<string, Page>|null */
    private ?array $publishedByKey = null;

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PageAccessChecker $pageAccessChecker,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function getPageByKey(PageKey $key): ?Page
    {
        $page = $this->getPublishedByKey()[$key->value] ?? null;
        if (!$page instanceof Page) {
            return null;
        }

        if (!$this->pageAccessChecker->canView($page)) {
            return null;
        }

        return $page;
    }

    public function getUrlByKey(PageKey $key): ?string
    {
        $page = $this->getPageByKey($key);
        if (!$page instanceof Page) {
            return null;
        }

        return $this->buildUrl($page);
    }

    /**
     * @return list<PageNavigationLink>
     */
    public function getHeaderPages(): array
    {
        $keys = self::SHARED_HEADER_KEYS;
        if (!$this->authorizationChecker->isGranted('ROLE_USER')) {
            $keys = [...self::GUEST_ONLY_HEADER_KEYS, ...$keys];
        }

        return $this->buildLinksForKeys(...$keys);
    }

    /**
     * @return list<PageNavigationLink>
     */
    public function getFooterPages(): array
    {
        return $this->buildLinksForKeys(
            PageKey::Imprint,
            PageKey::Privacy,
            PageKey::Terms,
        );
    }

    /**
     * @return array<string, Page>
     */
    private function getPublishedByKey(): array
    {
        if (null !== $this->publishedByKey) {
            return $this->publishedByKey;
        }

        $this->publishedByKey = [];
        foreach ($this->pageRepository->findAllPublishedWithKey() as $page) {
            $key = $page->getKey();
            if (!$key instanceof PageKey) {
                continue;
            }

            $this->publishedByKey[$key->value] = $page;
        }

        return $this->publishedByKey;
    }

    /**
     * @return list<PageNavigationLink>
     */
    private function buildLinksForKeys(PageKey ...$keys): array
    {
        $links = [];
        foreach ($keys as $key) {
            $page = $this->getPageByKey($key);
            if (!$page instanceof Page) {
                continue;
            }

            $url = $this->buildUrl($page);
            if (null === $url) {
                continue;
            }

            $links[] = new PageNavigationLink(
                key: $key,
                page: $page,
                url: $url,
                label: (string) $page->getTitle(),
            );
        }

        return $links;
    }

    private function buildUrl(Page $page): ?string
    {
        $pathSegment = trim((string) $page->getPath(), '/');
        if ('' === $pathSegment) {
            return null;
        }

        return $this->urlGenerator->generate('app_page_show', ['path' => $pathSegment]);
    }
}
