<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Application\Page\PageNavigationTreeProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
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
final class PageNavigationTreeProviderTest extends KernelTestCase
{
    use Factories;

    public function testGuestSeesOnlyPublicPublishedPagesInOrder(): void
    {
        self::bootKernel();
        $this->createTree();

        self::assertSame(
            ['A' => ['A2' => ['A2a' => []], 'A1' => []]],
            $this->shape($this->provider()->visibleTree()),
        );
    }

    public function testSignedInUserAlsoSeesAuthenticatedPagesAndTheirChildren(): void
    {
        self::bootKernel();
        $this->createTree();
        $this->signIn();

        self::assertSame(
            ['A' => ['A2' => ['A2a' => []], 'A1' => []], 'B' => ['B1' => []]],
            $this->shape($this->provider()->visibleTree()),
        );
    }

    public function testNodesCarryKeyAndPageUrl(): void
    {
        self::bootKernel();
        PageFactory::createOne([
            'title' => 'FAQ',
            'slug' => 'tree-faq',
            'key' => PageKey::Faq,
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        $node = $this->provider()->visibleTree()[0];

        self::assertSame(PageKey::Faq, $node->key);
        self::assertSame('/tree-faq', $node->url);
    }

    public function testUsesRequestLocale(): void
    {
        self::bootKernel();

        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'tree-locale-root',
            'path' => '/tree-locale-root-legacy',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);
        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Tree EN',
            'slug' => 'tree-en',
            'path' => '/tree-en',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);
        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'Tree DE',
            'slug' => 'tree-de',
            'path' => '/tree-de',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/tree-de'));
        $requestStack->getCurrentRequest()?->setLocale(SupportedLocales::GERMAN);

        $node = $this->provider()->visibleTree()[0];

        self::assertSame('Tree DE', $node->title);
        self::assertSame('/tree-de', $node->url);
    }

    public function testTreeIsLoadedOnceUntilReset(): void
    {
        self::bootKernel();
        PageFactory::createOne(['title' => 'First', 'slug' => 'tree-first', 'status' => PageTranslation::STATUS_PUBLISHED]);
        $provider = $this->provider();

        self::assertSame(['First' => []], $this->shape($provider->visibleTree()));

        // A page created after the first call is not visible: the second call reuses the loaded tree.
        PageFactory::createOne(['title' => 'Second', 'slug' => 'tree-second', 'sortOrder' => 1, 'status' => PageTranslation::STATUS_PUBLISHED]);
        self::assertSame(['First' => []], $this->shape($provider->visibleTree()));

        $provider->reset();
        self::assertSame(['First' => [], 'Second' => []], $this->shape($provider->visibleTree()));
    }

    public function testTreeIsKeptPerViewer(): void
    {
        self::bootKernel();
        $this->createTree();
        $provider = $this->provider();

        self::assertArrayNotHasKey('B', $this->shape($provider->visibleTree()));

        $this->signIn();
        self::assertArrayHasKey('B', $this->shape($provider->visibleTree()));
    }

    public function testCurrentPageIdComesFromTheMainRequest(): void
    {
        self::bootKernel();
        $provider = $this->provider();
        self::assertNull($provider->currentPageId());

        $request = Request::create('/somewhere');
        $request->attributes->set(PageNavigationTreeProvider::CURRENT_PAGE_ATTRIBUTE, 42);
        self::getContainer()->get(RequestStack::class)->push($request);

        self::assertSame(42, $provider->currentPageId());
    }

    public function testInstanceIdsAreUniquePerRequest(): void
    {
        self::bootKernel();
        $provider = $this->provider();

        self::assertSame('page-nav-1', $provider->nextInstanceId());
        self::assertSame('page-nav-2', $provider->nextInstanceId());

        $provider->reset();
        self::assertSame('page-nav-1', $provider->nextInstanceId());
    }

    /**
     * A (sort 0) > [A1 (sort 1), A2 (sort 0) > A2a], B (sort 1, signed-in only) > B1 (public), C (draft).
     */
    private function createTree(): void
    {
        $a = PageFactory::createOne(['title' => 'A', 'slug' => 'tree-a', 'sortOrder' => 0, 'status' => PageTranslation::STATUS_PUBLISHED]);
        PageFactory::createOne(['title' => 'A1', 'slug' => 'tree-a1', 'parent' => $a, 'sortOrder' => 1, 'status' => PageTranslation::STATUS_PUBLISHED]);
        $a2 = PageFactory::createOne(['title' => 'A2', 'slug' => 'tree-a2', 'parent' => $a, 'sortOrder' => 0, 'status' => PageTranslation::STATUS_PUBLISHED]);
        PageFactory::createOne(['title' => 'A2a', 'slug' => 'tree-a2a', 'parent' => $a2, 'status' => PageTranslation::STATUS_PUBLISHED]);
        $b = PageFactory::createOne([
            'title' => 'B',
            'slug' => 'tree-b',
            'sortOrder' => 1,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);
        PageFactory::createOne(['title' => 'B1', 'slug' => 'tree-b1', 'parent' => $b, 'status' => PageTranslation::STATUS_PUBLISHED]);
        PageFactory::createOne(['title' => 'C', 'slug' => 'tree-c', 'sortOrder' => 2, 'status' => PageTranslation::STATUS_DRAFT]);
    }

    private function signIn(): void
    {
        $user = UserFactory::createOne(['username' => 'tree-user-'.bin2hex(random_bytes(3))]);
        self::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function provider(): PageNavigationTreeProvider
    {
        return self::getContainer()->get(PageNavigationTreeProvider::class);
    }

    /**
     * @param list<PageNavigationNode> $nodes
     *
     * @return array<string, mixed>
     */
    private function shape(array $nodes): array
    {
        $shape = [];
        foreach ($nodes as $node) {
            $shape[$node->title] = $this->shape($node->children);
        }

        return $shape;
    }
}
