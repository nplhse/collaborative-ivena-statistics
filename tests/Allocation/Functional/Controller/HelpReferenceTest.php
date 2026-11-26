<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller;

use App\Content\UI\Twig\Components\HelpReference;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Tests\Support\Content\HelpPages;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Glossary and data catalog in the help (ADR 042): access, redirects of the released URLs and entry points.
 */
#[ResetDatabase]
final class HelpReferenceTest extends WebTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{string}>
     */
    public static function referenceUrls(): iterable
    {
        yield 'glossary' => ['/help/glossary'];
        yield 'glossary page' => ['/help/glossary/urgency'];
        yield 'MTS glossary' => ['/help/glossary/mts'];
        yield 'data catalog' => ['/help/data-catalog'];
        yield 'catalog object' => ['/help/data-catalog/allocation'];
    }

    #[DataProvider('referenceUrls')]
    public function testAGuestIsSentToTheLogin(string $url): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseRedirects('/login');
    }

    #[DataProvider('referenceUrls')]
    public function testAUserWithoutParticipantRoleIsDenied(string $url): void
    {
        $client = $this->clientWithRoles(['ROLE_USER']);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('referenceUrls')]
    public function testAParticipantOpensThePageWithoutTheExploreSidebar(string $url): void
    {
        $client = $this->clientWithRoles(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="sidebar-explore"]');
    }

    public function testACmsArticleWithASimilarPathStaysPublic(): void
    {
        $client = self::createClient();
        HelpPages::article(HelpPages::root(), 'Glossary basics', '/help/glossary-basics', '<p>Basics</p>');

        $client->request(Request::METHOD_GET, '/help/glossary-basics');

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function releasedGlossaryUrls(): iterable
    {
        yield 'hub' => ['/explore/glossary', '/help/glossary'];
        yield 'page' => ['/explore/glossary/urgency', '/help/glossary/urgency'];
    }

    #[DataProvider('releasedGlossaryUrls')]
    public function testReleasedGlossaryUrlsRedirectPermanently(string $old, string $new): void
    {
        $client = $this->clientWithRoles(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $client->request(Request::METHOD_GET, $old);

        self::assertResponseRedirects($new, 301);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreleasedOldUrls(): iterable
    {
        yield 'MTS glossary' => ['/explore/glossary/mts'];
        yield 'MTS diagram' => ['/explore/glossary/mts/diagram/thoraxschmerz'];
        yield 'data catalog' => ['/explore/data-catalog'];
        yield 'catalog object' => ['/explore/data-catalog/allocation'];
    }

    #[DataProvider('unreleasedOldUrls')]
    public function testUnreleasedOldUrlsHaveNoRedirect(string $url): void
    {
        $client = $this->clientWithRoles(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAParticipantSeesTheReferenceInTheHelpAndFooter(): void
    {
        $client = $this->clientWithRoles(['ROLE_USER', 'ROLE_PARTICIPANT']);
        HelpPages::root();

        $client->request(Request::METHOD_GET, '/help');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="help-reference-glossary"][href="/help/glossary"]');
        self::assertSelectorExists('[data-testid="help-reference-data-catalog"][href="/help/data-catalog"]');
        self::assertSelectorExists('[data-testid="footer-nav-glossary"][href="/help/glossary"]');
        self::assertSelectorExists('[data-testid="footer-nav-data-catalog"][href="/help/data-catalog"]');
    }

    public function testAGuestSeesNoReferenceEntries(): void
    {
        $client = self::createClient();
        HelpPages::root();

        $client->request(Request::METHOD_GET, '/help');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="help-reference"]');
        self::assertSelectorNotExists('[data-testid="footer-nav-glossary"]');
        self::assertSelectorNotExists('[data-testid="footer-nav-data-catalog"]');
    }

    public function testTheBreadcrumbStartsWithThePublishedHelp(): void
    {
        $client = $this->clientWithRoles(['ROLE_USER', 'ROLE_PARTICIPANT']);
        HelpPages::root();

        $crawler = $client->request(Request::METHOD_GET, '/help/glossary');

        self::assertResponseIsSuccessful();
        self::assertSame('Help', trim($crawler->filter('.breadcrumb a[href="/help"]')->text()));
    }

    public function testTheBreadcrumbLeavesOutAnUnpublishedHelp(): void
    {
        $client = $this->clientWithRoles(['ROLE_USER', 'ROLE_PARTICIPANT']);

        $client->request(Request::METHOD_GET, '/help/glossary');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.breadcrumb a[href="/help"]');
    }

    public function testTheReferenceCardHasNoEntriesWithoutAHelpColumn(): void
    {
        self::bootKernel();

        $component = new HelpReference(self::getContainer()->get(NavigationBuilder::class));

        self::assertSame([], $component->getItems(), 'A guest without published help pages has no help column.');
    }

    public function testExploreNoLongerLinksToTheReference(): void
    {
        $client = $this->clientWithRoles(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $client->request(Request::METHOD_GET, '/explore');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="sidebar-entry-glossary"]');
        self::assertSelectorNotExists('[data-testid="sidebar-entry-data-catalog"]');
        self::assertSelectorNotExists('[data-testid="data-catalog-tile"]');
    }

    /**
     * @param list<string> $roles
     */
    private function clientWithRoles(array $roles): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => $roles]));

        return $client;
    }
}
