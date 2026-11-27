<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Error;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The navigation of the error pages (#1684): only the stored copy of the public links, which normal pages keep, so an
 * error page needs no database. Without a copy the header offers registration and sign-in only.
 */
#[ResetDatabase]
final class ErrorPageNavigationTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->getContainer()->get('cache.app')->clear();
    }

    public function testWithoutACopyTheErrorPageShowsOnlySignInLinks(): void
    {
        $this->client->request(Request::METHOD_GET, '/_error_preview/404');

        self::assertSelectorExists('[data-testid="error-navigation"] a[href="/login"]');
        self::assertSelectorExists('[data-testid="error-navigation"] a[href="/register"]');
        self::assertSelectorNotExists('[data-testid="nav-blog"]');
        self::assertSelectorNotExists('[data-testid="footer-nav-sitemap"]');
        self::assertSelectorExists('[data-testid="footer-meta-version"]');
    }

    public function testAfterANormalPageTheErrorPageShowsTheUsualPublicLinks(): void
    {
        $this->client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        $this->client->request(Request::METHOD_GET, '/_error_preview/404');

        self::assertSelectorExists('[data-testid="error-navigation"] [data-testid="nav-blog"]');
        self::assertSelectorExists('[data-testid="footer-nav-sitemap"]');
        self::assertSelectorExists('[data-testid="error-navigation"] a[href="/login"]');
    }

    public function testTheCopyNeverHoldsTheLinksOfASignedInUser(): void
    {
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());
        $this->client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nav-statistics"]', 'the normal page shows the admin their links');

        $this->client->request(Request::METHOD_GET, '/_error_preview/500');

        self::assertSelectorExists('[data-testid="error-navigation"] [data-testid="nav-blog"]');
        self::assertSelectorNotExists('[data-testid="nav-statistics"]');
        self::assertSelectorNotExists('[data-testid="footer-nav-glossary"]');
        self::assertSelectorNotExists('[data-testid="error-navigation"] a[href="/login"]', 'signed in: no sign-in link');
    }
}
