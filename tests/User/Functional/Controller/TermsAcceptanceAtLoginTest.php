<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
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
 *
 * Checked lower down: no configured version, forms and background requests (403), impersonating administrators and the
 * legal pages (imprint) against other CMS pages in `RequireTermsAcceptanceListenerTest`; an acceptance of the current
 * version and a raised version in `TermsAcceptanceStatusTest`; the form fields in `TermsAcceptanceTypeTest`; the
 * hospitals to renew in `TermsAcceptanceRepositoryTest`.
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
        self::assertSelectorTextContains('body', 'Please read version 2026-12 and accept it');

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

    public function testANewVersionRenewsTheUploadAuthorityInTheSameStep(): void
    {
        // Confirmed for Klinikum Nord and Klinikum Süd under 2026-11 and for Klinikum Ost already under 2026-12 (#1565).
        $this->configureVersion('2026-12');
        $user = UserFactory::createOne(['username' => 'uploader']);
        $north = HospitalFactory::createOne(['name' => 'Klinikum Nord']);
        $south = HospitalFactory::createOne(['name' => 'Klinikum Süd']);
        $east = HospitalFactory::createOne(['name' => 'Klinikum Ost']);
        $this->confirmUploadAuthority($user, $north, '2026-11');
        $this->confirmUploadAuthority($user, $south, '2026-11');
        $this->confirmUploadAuthority($user, $east, '2026-12');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/terms/accept');
        self::assertSelectorTextContains('label[for="terms_acceptance_uploadAuthority"]', 'I am still authorised to upload data for Klinikum Nord, Klinikum Süd.');

        $this->client->submitForm('Accept and continue', ['terms_acceptance[accept]' => true, 'terms_acceptance[uploadAuthority]' => true]);
        self::assertResponseRedirects();

        $renewed = $this->acceptances()->findBy(['user' => $user->getId(), 'kind' => TermsAcceptanceKind::UploadAuthority, 'termsVersion' => '2026-12']);
        self::assertSame(['Klinikum Nord', 'Klinikum Ost', 'Klinikum Süd'], $this->hospitalNames($renewed));
    }

    public function testTheUploadAuthorityIsOptionalWhenAcceptingANewVersion(): void
    {
        $this->configureVersion('2026-12');
        $user = UserFactory::createOne(['username' => 'uploader']);
        $this->confirmUploadAuthority($user, HospitalFactory::createOne(['name' => 'Klinikum Nord']), '2026-11');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/terms/accept');
        $this->client->submitForm('Accept and continue', ['terms_acceptance[accept]' => true]);

        self::assertResponseRedirects();
        self::assertSame('2026-12', $this->acceptances()->latestFor($this->reload($user))?->getTermsVersion());
        self::assertCount(0, $this->acceptances()->findBy(['user' => $user->getId(), 'kind' => TermsAcceptanceKind::UploadAuthority, 'termsVersion' => '2026-12']));
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

    public function testSubmittingWithoutTheAcceptanceIsRefused(): void
    {
        $this->configureVersion('2026-12');
        $this->client->loginUser(UserFactory::createOne());
        $this->client->request(Request::METHOD_GET, '/terms/accept');

        $this->client->submitForm('Accept and continue', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'You must accept the terms of use to continue.');
        self::assertSame(0, $this->acceptances()->count([]));
    }

    public function testTheTermsPageStaysReachable(): void
    {
        $this->configureVersion('2026-12');
        PageFactory::createOne(['key' => PageKey::Terms, 'slug' => 'terms', 'locale' => 'en', 'status' => PageTranslation::STATUS_PUBLISHED]);
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_GET, '/terms');

        self::assertResponseIsSuccessful();
    }

    public function testLogoutStaysReachable(): void
    {
        $this->configureVersion('2026-12');
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request(Request::METHOD_GET, '/logout');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/terms/accept', (string) $this->client->getResponse()->headers->get('Location'));
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
        self::getContainer()->set(TermsVersion::class, new TermsVersion($version));
    }

    private function confirmUploadAuthority(User $user, Hospital $hospital, string $version): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TermsAcceptance($this->reload($user), TermsAcceptanceKind::UploadAuthority, $version, 'en', null, null, $entityManager->find(Hospital::class, $hospital->getId())));
        $entityManager->flush();
    }

    /**
     * @param list<TermsAcceptance> $acceptances
     *
     * @return list<string>
     */
    private function hospitalNames(array $acceptances): array
    {
        $names = array_map(static fn (TermsAcceptance $acceptance): string => (string) $acceptance->getHospital()?->getName(), $acceptances);
        sort($names);

        return $names;
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
