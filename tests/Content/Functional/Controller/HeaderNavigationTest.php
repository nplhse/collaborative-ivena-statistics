<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HeaderNavigationTest extends WebTestCase
{
    use Factories;

    public function testGuestSeesAboutFeaturesAndFaqInHeaderNavigation(): void
    {
        $client = self::createClient();
        $this->createHeaderNavigationPages();

        $client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#navbar-menu a[href="/nav-about"]');
        self::assertSelectorExists('#navbar-menu a[href="/nav-features"]');
        self::assertSelectorExists('#navbar-menu a[href="/nav-faq"]');
    }

    public function testAuthenticatedUserSeesOnlyFaqInHeaderNavigation(): void
    {
        $client = self::createClient();
        $this->createHeaderNavigationPages();

        $user = UserFactory::createOne(['username' => 'header-nav-user', 'roles' => ['ROLE_USER']]);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/statistics/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#navbar-menu a[href="/nav-about"]');
        self::assertSelectorNotExists('#navbar-menu a[href="/nav-features"]');
        self::assertSelectorExists('#navbar-menu a[href="/nav-faq"]');
    }

    private function createHeaderNavigationPages(): void
    {
        PageFactory::createOne([
            'slug' => 'nav-about',
            'path' => '/nav-about',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'About us',
        ]);

        PageFactory::createOne([
            'slug' => 'nav-features',
            'path' => '/nav-features',
            'key' => PageKey::Features,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'Features',
        ]);

        PageFactory::createOne([
            'slug' => 'nav-faq',
            'path' => '/nav-faq',
            'key' => PageKey::Faq,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'FAQ',
        ]);
    }
}
