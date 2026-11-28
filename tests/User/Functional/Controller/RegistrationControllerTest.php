<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\Tests\Support\RateLimit\DeniesRateLimiter;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\Tests\User\Support\AlwaysAvailableRegistrationIdentityChecker;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Registration\RegistrationIdentityGuard;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The registration page over HTTP: the main flow, the controller's own branches (rate limit, suppressed domains,
 * taken addresses and the races on the unique constraints) and the e-mail confirmation.
 *
 * The rules behind the form are tested lower down (#1348): usernames in UserUsernameConstraintsTest and
 * UserUsernameTest, suppressed domains in RegistrationEmailSuppressionPolicyTest, taken identities in
 * RegistrationIdentityCheckerTest, the admin notification in UserRegisteredNotificationSubscriberTest, the stored
 * terms acceptance in RegistrationTermsAcceptanceTest and the password field in PasswordWidgetRenderingTest.
 */
#[ResetDatabase]
final class RegistrationControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use CookieConsentTestHelper;
    use DeniesRateLimiter;
    use Factories;
    use HasBrowser;
    use MailerAssertionsTrait;

    /**
     * @return iterable<string, array{bool}>
     */
    public static function cookieDecisions(): iterable
    {
        yield 'without a cookie decision' => [false];
        yield 'after a cookie decision' => [true];
    }

    /** Registering never signs the user in: the address has to be confirmed first. */
    #[DataProvider('cookieDecisions')]
    public function testUserCanRegisterButIsNotSignedIn(bool $decidedOnCookies): void
    {
        $browser = $this->browser();
        if ($decidedOnCookies) {
            $this->acceptEssentialCookies($browser);
        }

        $browser
            ->visit('/register')
            ->fillField('registration_form[username]', 'alice')
            ->fillField('registration_form[email]', 'alice@example.test')
            ->fillField('registration_form[plainPassword]', 'super-secret-password')
            ->checkField('registration_form[acceptTerms]')
            ->click('Register')

            ->assertSuccessful()
            ->assertSeeIn('h2', 'Check your email')
            ->assertSee('Your registration was almost successful.')
            ->assertNotSee('alice')
        ;
        UserFactory::assert()->exists(['username' => 'alice', 'isVerified' => false]);
    }

    public function testRegistrationPageHasNoMissingTranslationsInGerman(): void
    {
        $client = self::createClient();
        $client->enableProfiler();

        $client->request(Request::METHOD_GET, '/register', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        $this->assertNoMissingTranslations($client->getProfile());
    }

    /** The locale chosen before registering is stored with the account; how it is resolved: LocaleResolverTest. */
    public function testRegistrationStoresTheLocaleOfTheVisitor(): void
    {
        $this->browser()
            ->visit('/locale/switch/de?_target_path=/register')
            ->fillField('registration_form[username]', 'alice')
            ->fillField('registration_form[email]', 'alice@example.test')
            ->fillField('registration_form[plainPassword]', 'super-secret-password')
            ->checkField('registration_form[acceptTerms]')
            ->click('Registrieren')

            ->assertSuccessful()
            ->assertSeeIn('h2', 'E-Mail prüfen')
        ;
        UserFactory::assert()->exists(['username' => 'alice', 'locale' => 'de']);
    }

    public function testRegistrationIsRateLimited(): void
    {
        $client = $this->client();
        $this->denyRateLimiter('limiter.register', $this->ipRateLimitKey('register'));

        $this->submitRegistration($client, 'alice', 'alice@example.test');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Too many registration attempts');
        UserFactory::assert()->notExists(['email' => 'alice@example.test']);
    }

    /** The controller dispatches the registration once; who gets the mail is UserRegisteredNotificationSubscriberTest. */
    public function testRegistrationNotifiesTheAdministratorsOnce(): void
    {
        $client = $this->client();
        UserFactory::new()->asAdmin()->create(['username' => 'ada', 'email' => 'ada@example.test']);

        $this->submitRegistration($client, 'alice', 'alice@example.test');

        $notices = $this->mailsTo('ada@example.test');
        self::assertCount(1, $notices);
        self::assertSame('New user registration: alice', $notices[0]->getSubject());
    }

    /** A blocked domain gets the same answer as a real registration, but no account and no mail. */
    public function testRegistrationWithBlockedEmailDomainShowsTheCheckEmailPageWithoutCreatingAUser(): void
    {
        $client = $this->client();

        $this->submitRegistration($client, 'mallory', 'mallory@example.ru');

        self::assertResponseRedirects('/register/check-email');
        self::assertQueuedEmailCount(0);
        UserFactory::assert()->notExists(['username' => 'mallory']);
        UserFactory::assert()->notExists(['email' => 'mallory@example.ru']);
        $client->followRedirect();
        self::assertSelectorTextContains('h2', 'Check your email');
        self::assertSelectorTextNotContains('body', 'mallory');
    }

    public function testRegistrationShowsTakenUsername(): void
    {
        $client = $this->client();
        UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test']);

        $this->submitRegistration($client, 'alice', 'other@example.test');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This username is already taken.');
        UserFactory::assert()->notExists(['email' => 'other@example.test']);
        UserFactory::assert()->count(1, ['username' => 'alice']);
    }

    /** One invalid username for the wiring; the rules: UserUsernameConstraintsTest. */
    public function testRegistrationRejectsAnInvalidUsername(): void
    {
        $client = $this->client();

        $this->submitRegistration($client, 'user@host', 'alice@example.test');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Please use letters and numbers. Periods, underscores, and hyphens are fine, but spaces are not.');
        UserFactory::assert()->notExists(['email' => 'alice@example.test']);
    }

    /**
     * A taken address (here in other case) gets the same answer as a new one, so the page does not reveal which
     * addresses have an account. The existing account gets a notice that names it and links to the password reset.
     */
    public function testRegistrationWithTakenEmailAnswersLikeANewAddressAndNotifiesTheAccount(): void
    {
        $client = $this->client();
        UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test', 'locale' => 'en']);

        $this->submitRegistration($client, 'mallory', 'ALICE@example.test');

        self::assertResponseRedirects('/register/check-email');
        $notices = $this->mailsTo('alice@example.test');
        self::assertCount(1, $notices);
        self::assertSame('Registration attempt with your email address', $notices[0]->getSubject());
        $body = (string) $notices[0]->getHtmlBody();
        self::assertStringContainsString('alice', $body);
        self::assertStringContainsString('http://localhost/reset-password', $body);
        self::assertStringNotContainsString('mallory', $body);
        UserFactory::assert()->notExists(['username' => 'mallory']);
        UserFactory::assert()->count(1, ['email' => 'alice@example.test']);
    }

    public function testRegistrationAttemptNoticeIsSentAtMostOncePerAccountWithinTheLimit(): void
    {
        $client = $this->client();
        UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test']);
        $this->submitRegistration($client, 'mallory', 'alice@example.test');
        self::assertCount(1, $this->mailsTo('alice@example.test'));

        // The second attempt gets the same answer, but no further mail (registration_attempt_notice: 1 per 24 hours).
        $this->submitRegistration($client, 'trudy', 'alice@example.test');

        self::assertResponseRedirects('/register/check-email');
        self::assertCount(0, $this->mailsTo('alice@example.test'));
        UserFactory::assert()->count(1, ['email' => 'alice@example.test']);
    }

    public function testRegistrationHandlesUniqueConstraintRaceOnTheEmailLikeATakenAddress(): void
    {
        $client = $this->client();
        // Another registration stores the same address between the lookup and the flush.
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->getEventManager()->addEventListener(Events::preFlush, new class('racer', 'alice@example.test') {
            private bool $done = false;

            public function __construct(private readonly string $username, private readonly string $email)
            {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                // Only the flush that stores the new account; earlier requests flush the cookie consent.
                $inserts = $args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions();
                if ($this->done || [] === array_filter($inserts, static fn (object $entity): bool => $entity instanceof User)) {
                    return;
                }

                $this->done = true;
                $args->getObjectManager()->getConnection()->executeStatement(
                    'INSERT INTO "user" (id, username, email, roles, password, is_verified, created_at) VALUES (nextval(\'user_id_seq\'), :username, :email, :roles, :password, false, \'2026-11-27 10:00:00\')',
                    ['username' => $this->username, 'email' => $this->email, 'roles' => '[]', 'password' => 'x'],
                );
            }
        });

        $this->submitRegistration($client, 'alice', 'alice@example.test');

        self::assertResponseRedirects('/register/check-email');
        $notices = $this->mailsTo('alice@example.test');
        self::assertCount(1, $notices);
        self::assertStringContainsString('racer', (string) $notices[0]->getHtmlBody());
        UserFactory::assert()->notExists(['username' => 'alice']);
    }

    public function testRegistrationHandlesUniqueConstraintRaceOnTheUsername(): void
    {
        $client = $this->client();
        UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test']);
        // The identity check always answers "available", as if the other account was stored right after it: the
        // controller then has to handle the unique constraint violation of the flush.
        $container = self::getContainer();
        $container->set(RegistrationIdentityGuard::class, new AlwaysAvailableRegistrationIdentityChecker($container->get(TranslatorInterface::class)));

        $this->submitRegistration($client, 'alice', 'other@example.test');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This username is already taken.');
        self::assertSelectorTextNotContains('body', 'Check your email');
        UserFactory::assert()->count(1, ['username' => 'alice']);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function termsPages(): iterable
    {
        yield 'published terms page' => [true];
        yield 'no terms page' => [false];
    }

    /** The terms checkbox links to the published terms page, and to nothing while there is none. */
    #[DataProvider('termsPages')]
    public function testTheTermsCheckboxLinksToThePublishedTermsPage(bool $published): void
    {
        if ($published) {
            $legal = PageFactory::createOne(['slug' => 'legal', 'status' => PageTranslation::STATUS_PUBLISHED, 'visibility' => Page::VISIBILITY_PUBLIC]);
            PageFactory::createOne(['parent' => $legal, 'slug' => 'terms-of-service', 'key' => PageKey::Terms, 'status' => PageTranslation::STATUS_PUBLISHED, 'visibility' => Page::VISIBILITY_PUBLIC]);
        }

        $browser = $this->browser()->visit('/register');

        $browser->assertSuccessful()->assertSee('terms and conditions');
        $published
            ? $browser->assertSeeElement('label[for="registration_form_acceptTerms"] a[href="/legal/terms-of-service"]')
            : $browser->assertNotSeeElement('label[for="registration_form_acceptTerms"] a');
    }

    public function testVerifyEmailRedirectsToLoginWithSuccessMessage(): void
    {
        $user = UserFactory::createOne(['isVerified' => false, 'username' => 'alice']);
        $signedUrl = self::getContainer()->get(VerifyEmailHelperInterface::class)->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            (string) $user->getEmail(),
            ['id' => (string) $user->getId()],
        )->getSignedUrl();

        $this->browser()
            ->visit($signedUrl)

            ->assertSuccessful()
            ->assertSeeIn('h2', 'Login')
            ->assertSee('Your email address has been confirmed. You can sign in now.')
        ;
        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertTrue($user->isVerified());
    }

    /**
     * The client is created first: Foundry boots the kernel, and createClient() refuses an already booted one. It
     * keeps its kernel so the replaced services stay in place, and starts with empty rate limits, because the
     * limiter cache outlives the database reset.
     */
    private function client(): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function submitRegistration(KernelBrowser $client, string $username, string $email): void
    {
        $client->request(Request::METHOD_GET, '/register');
        $client->submitForm('Register', [
            'registration_form[username]' => $username,
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'super-secret-password',
            'registration_form[acceptTerms]' => true,
        ]);
    }

    /**
     * Mails of the last request sent to `$address`, in any letter case.
     *
     * @return list<Email>
     */
    private function mailsTo(string $address): array
    {
        return array_values(array_filter(
            self::getMailerMessages(),
            static fn (object $message): bool => $message instanceof Email
                && [] !== array_filter($message->getTo(), static fn (\Symfony\Component\Mime\Address $to): bool => 0 === strcasecmp($to->getAddress(), $address)),
        ));
    }
}
