<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Users without an acceptance of the configured version of the terms of use accept it before they continue (#1564,
 * ADR 043). The test environment has no version; each test sets one, like a raised version in production.
 */
#[ResetDatabase]
final class TermsAcceptanceAtLoginTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testWithoutAConfiguredVersionNobodyIsAsked(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseIsSuccessful();
    }

    public function testAUserWithoutAcceptanceAcceptsAndReturnsToTheRequestedPage(): void
    {
        $this->configureVersion('2026-12');
        $user = UserFactory::createOne(['username' => 'needs-terms']);
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/settings/account?tab=1');
        self::assertResponseRedirects('/terms/accept');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Accept the terms of use');
        self::assertSelectorTextContains('body', 'Please read version 2026-12 of 01.12.2026');

        $this->client->submitForm('Accept and continue', []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'You must accept the terms of use to continue.');
        self::assertSame(0, $this->acceptances()->count([]));

        $this->client->submitForm('Accept and continue', ['terms_acceptance[accept]' => true]);
        self::assertResponseRedirects('/settings/account?tab=1');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $acceptance = $this->acceptances()->latestFor($this->reload($user));
        self::assertInstanceOf(TermsAcceptance::class, $acceptance);
        self::assertSame('2026-12', $acceptance->getTermsVersion());
        self::assertSame('en', $acceptance->getLocale());

        $this->client->request(Request::METHOD_GET, '/terms/accept');
        self::assertResponseRedirects('/', message: 'nothing left to accept');
    }

    public function testTheAcceptancePageHasNoMissingTranslationsInGerman(): void
    {
        $this->configureVersion('2026-12');
        $this->client->loginUser(UserFactory::createOne(['locale' => 'de']));
        $this->client->enableProfiler();

        $this->client->request(Request::METHOD_GET, '/terms/accept', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Nutzungsbedingungen zustimmen');
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $this->assertNoMissingTranslations($profile);
    }

    public function testAnAcceptanceOfTheCurrentVersionLetsTheUserThrough(): void
    {
        $this->configureVersion('2026-12');
        $user = UserFactory::createOne();
        $this->accept($user, '2026-12');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseIsSuccessful();
    }

    public function testRaisingTheVersionAsksAgainButEditingTheTermsPageDoesNot(): void
    {
        $page = PageFactory::new()->withoutDefaultTranslation()->create(['key' => PageKey::Terms]);
        $translation = PageTranslationFactory::createOne(['page' => $page, 'locale' => 'en', 'slug' => 'terms', 'path' => '/terms']);
        $user = UserFactory::createOne();
        $this->accept($user, '2026-12');
        $this->client->loginUser($user);
        $this->configureVersion('2026-12');

        $translation->setContent([['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Typo fixed</p>']]]);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseIsSuccessful('an editorial edit keeps the version');

        // A deploy with a raised version: a new container, the same session.
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->configureVersion('2027-03');
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseRedirects('/terms/accept', message: 'a raised version asks again');
    }

    public function testLogoutAndTheLegalPagesStayReachable(): void
    {
        $this->configureVersion('2026-12');
        $terms = PageFactory::createOne(['key' => PageKey::Terms, 'slug' => 'terms', 'locale' => 'en', 'status' => PageTranslation::STATUS_PUBLISHED]);
        PageFactory::createOne(['key' => PageKey::Imprint, 'slug' => 'imprint', 'locale' => 'en', 'status' => PageTranslation::STATUS_PUBLISHED]);
        PageFactory::createOne(['slug' => 'about-us', 'locale' => 'en', 'status' => PageTranslation::STATUS_PUBLISHED]);
        self::assertSame(PageKey::Terms, $terms->getKey());
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_GET, '/terms');
        self::assertResponseIsSuccessful('terms');
        $this->client->request(Request::METHOD_GET, '/imprint');
        self::assertResponseIsSuccessful('imprint');
        $this->client->request(Request::METHOD_GET, '/about-us');
        self::assertResponseRedirects('/terms/accept', message: 'other pages wait for the acceptance');

        $this->client->request(Request::METHOD_GET, '/logout');
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/terms/accept', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testFormsAndBackgroundRequestsGetA403(): void
    {
        $this->configureVersion('2026-12');
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_POST, '/settings/account');
        self::assertResponseStatusCodeSame(403);

        $this->client->xmlHttpRequest(Request::METHOD_GET, '/settings/account');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdministratorImpersonatingAUserIsNotAskedForThem(): void
    {
        $this->configureVersion('2026-12');
        $admin = UserFactory::new()->asAdmin()->create();
        $this->accept($admin, '2026-12');
        $target = UserFactory::createOne(['username' => 'impersonated-user']);
        $this->client->loginUser($admin);

        $this->client->request(Request::METHOD_GET, '/settings/account?_switch_user='.$target->getUserIdentifier());
        $this->client->followRedirects();
        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->acceptances()->count(['user' => $this->reload($target)]));
    }

    public function testAnAdministratorAcceptsTheTermsBeforeSettingUpTwoFactorAuthentication(): void
    {
        $this->configureVersion('2026-12');
        $container = self::getContainer();
        $container->set(TwoFactorAvailability::class, new TwoFactorAvailability($container->get(TwoFactorSecretCipher::class), false, true));
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());

        $this->client->request(Request::METHOD_GET, '/admin');
        self::assertResponseRedirects('/terms/accept');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful('the two-factor obligation lets the terms page through');
        $this->client->submitForm('Accept and continue', ['terms_acceptance[accept]' => true]);
        self::assertResponseRedirects('/admin');

        $this->client->followRedirect();
        self::assertResponseRedirects('/settings/two-factor');
    }

    private function configureVersion(string $version): void
    {
        self::getContainer()->set(TermsVersion::class, new TermsVersion($version, '2026-12-01'));
    }

    private function accept(User $user, string $version): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TermsAcceptance($this->reload($user), TermsAcceptanceKind::Terms, $version, 'en', null));
        $entityManager->flush();
    }

    private function reload(User $user): User
    {
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function acceptances(): TermsAcceptanceRepository
    {
        return self::getContainer()->get(TermsAcceptanceRepository::class);
    }
}
