<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageSidebarDataProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Shared\Application\Locale\SupportedLocales;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageSidebarDataProviderTest extends KernelTestCase
{
    use Factories;

    public function testGetDataExcludesPagesGuestCannotView(): void
    {
        self::bootKernel();

        PageFactory::createOne([
            'slug' => 'sidebar-public',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'sidebar-auth',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $provider = self::getContainer()->get(PageSidebarDataProvider::class);
        $data = $provider->getData();

        $slugs = $this->collectPathsFromTree($data['pageTree']);

        self::assertContains('/sidebar-public', $slugs);
        self::assertNotContains('/sidebar-auth', $slugs);
    }

    public function testGetDataUsesRequestLocaleAndIncludesLatestPosts(): void
    {
        self::bootKernel();

        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'sidebar-locale-root',
            'path' => '/sidebar-locale-root-legacy',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Sidebar EN',
            'slug' => 'sidebar-en',
            'path' => '/sidebar-en',
            'status' => \App\Content\Domain\Entity\PageTranslation::STATUS_PUBLISHED,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'Sidebar DE',
            'slug' => 'sidebar-de',
            'path' => '/sidebar-de',
            'status' => \App\Content\Domain\Entity\PageTranslation::STATUS_PUBLISHED,
        ]);

        PostFactory::createOne([
            'title' => 'Sidebar Post',
            'slug' => 'sidebar-post',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/sidebar-de'));
        $requestStack->getCurrentRequest()?->setLocale(SupportedLocales::GERMAN);

        $provider = self::getContainer()->get(PageSidebarDataProvider::class);
        $data = $provider->getData();

        $paths = $this->collectPathsFromTree($data['pageTree']);
        self::assertContains('/sidebar-de', $paths);
        self::assertNotContains('/sidebar-en', $paths);
        self::assertNotEmpty($data['latest_posts']);
    }

    public function testAuthenticatedUserSeesAuthenticatedPages(): void
    {
        self::bootKernel();

        PageFactory::createOne([
            'slug' => 'sidebar-auth-visible',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $user = UserFactory::createOne(['username' => 'sidebar-user-'.bin2hex(random_bytes(3))]);
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $provider = self::getContainer()->get(PageSidebarDataProvider::class);
        $data = $provider->getData();

        self::assertContains('/sidebar-auth-visible', $this->collectPathsFromTree($data['pageTree']));
    }

    /**
     * @param array<int, array{page: Page, title: string, path: string, children: array<int, mixed>}> $nodes
     *
     * @return list<string>
     */
    private function collectPathsFromTree(array $nodes): array
    {
        $paths = [];
        foreach ($nodes as $node) {
            if ('' !== $node['path']) {
                $paths[] = $node['path'];
            }
            $paths = array_merge($paths, $this->collectPathsFromTree($node['children']));
        }

        return $paths;
    }
}
