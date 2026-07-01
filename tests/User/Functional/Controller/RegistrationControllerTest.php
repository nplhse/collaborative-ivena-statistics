<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RegistrationControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;
    use MailerAssertionsTrait;

    public function testRegistrationPageHasNoMissingTranslationsInGerman(): void
    {
        $client = self::createClient();
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/register', server: [
            'HTTP_Accept-Language' => 'de-DE,de;q=0.9',
        ]);

        self::assertResponseIsSuccessful();

        $this->assertNoMissingTranslations($client->getProfile());
    }

    public function testRegistrationStoresResolvedGermanLocaleFromCookie(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $username = sprintf('register-locale-de-%s', $suffix);
        $email = sprintf('register-locale-de-%s@example.test', $suffix);

        $this->browser()
            ->visit('/locale/switch/de?_target_path=/register')
            ->visit('/register')
            ->fillField('registration_form[username]', $username)
            ->fillField('registration_form[email]', $email)
            ->fillField('registration_form[plainPassword]', 'super-secret-password')
            ->checkField('registration_form[acceptTerms]')
            ->click('Registrieren')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'E-Mail prüfen')
        ;

        UserFactory::assert()->exists([
            'username' => $username,
            'locale' => 'de',
        ]);
    }

    public function testRegistrationStoresEnglishLocaleWhenBrowserLanguageIsNotGerman(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $username = sprintf('register-locale-en-%s', $suffix);
        $email = sprintf('register-locale-en-%s@example.test', $suffix);

        $client = self::createClient();
        $client->setServerParameter('HTTP_Accept-Language', 'fr-FR,fr;q=0.9');
        $client->request(Request::METHOD_GET, '/register');
        self::assertResponseIsSuccessful();

        $client->submitForm('Register', [
            'registration_form[username]' => $username,
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'super-secret-password',
            'registration_form[acceptTerms]' => true,
        ]);
        self::assertResponseRedirects('/register/check-email');

        UserFactory::assert()->exists([
            'username' => $username,
            'locale' => 'en',
        ]);
    }

    public function testRegistrationSendsAdminNotificationToNotificationRecipients(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $recipientEmail = sprintf('admin-notify-%s@example.test', $suffix);
        UserFactory::new()
            ->asNotificationRecipient()
            ->create([
                'username' => sprintf('admin-notify-%s', $suffix),
                'email' => $recipientEmail,
            ]);

        $username = sprintf('register-admin-mail-%s', $suffix);
        $email = sprintf('register-admin-mail-%s@example.test', $suffix);

        $previousFollowRedirects = $_SERVER['BROWSER_FOLLOW_REDIRECTS'] ?? null;
        $_SERVER['BROWSER_FOLLOW_REDIRECTS'] = '0';

        try {
            $this->browser()
                ->disableReboot()
                ->visit('/register')
                ->fillField('registration_form[username]', $username)
                ->fillField('registration_form[email]', $email)
                ->fillField('registration_form[plainPassword]', 'super-secret-password')
                ->checkField('registration_form[acceptTerms]')
                ->click('Register')
                ->assertStatus(302)
            ;
        } finally {
            if (null === $previousFollowRedirects) {
                unset($_SERVER['BROWSER_FOLLOW_REDIRECTS']);
            } else {
                $_SERVER['BROWSER_FOLLOW_REDIRECTS'] = $previousFollowRedirects;
            }
        }

        self::assertQueuedEmailCount(2);

        $adminNotification = null;
        foreach (self::getMailerMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }

            if (!str_contains((string) $message->getSubject(), 'New user registration')) {
                continue;
            }

            self::assertEmailAddressContains($message, 'to', $recipientEmail);
            $adminNotification = $message;
            break;
        }

        self::assertInstanceOf(TemplatedEmail::class, $adminNotification);
        self::assertEmailSubjectContains($adminNotification, 'New user registration');
    }

    public function testUserCanRegisterButIsNotAutoLoggedInWithoutConsentDecision(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $username = sprintf('register-%s', $suffix);
        $email = sprintf('register-%s@example.test', $suffix);

        $this->browser()
            ->visit('/register')
            ->fillField('registration_form[username]', $username)
            ->fillField('registration_form[email]', $email)
            ->fillField('registration_form[plainPassword]', 'super-secret-password')
            ->checkField('registration_form[acceptTerms]')
            ->click('Register')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Check your email')
            ->assertSee('Your registration was almost successful.')
            ->assertNotSee($username)
        ;
    }

    public function testRegistrationFailsWithoutAcceptingTerms(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $username = sprintf('register-no-terms-%s', $suffix);
        $email = sprintf('register-no-terms-%s@example.test', $suffix);

        $this->browser()
            ->visit('/register')
            ->fillField('registration_form[username]', $username)
            ->fillField('registration_form[email]', $email)
            ->fillField('registration_form[plainPassword]', 'super-secret-password')
            ->click('Register')
            ->assertStatus(422)
            ->assertSeeIn('h2', 'Register')
            ->assertSee('You must accept the terms and conditions to register.')
        ;
    }

    public function testRegistrationShowsTermsLinkWhenPublishedTermsPageExists(): void
    {
        $parent = PageFactory::createOne([
            'slug' => 'legal',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'parent' => $parent,
            'slug' => 'terms-of-service',
            'key' => PageKey::Terms,
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $this->browser()
            ->visit('/register')
            ->assertSuccessful()
            ->assertSeeElement('label[for="registration_form_acceptTerms"] a[href="/legal/terms-of-service"]')
            ->assertSee('terms and conditions')
        ;
    }

    public function testRegistrationWorksWithoutTermsPageLink(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $username = sprintf('register-no-terms-page-%s', $suffix);
        $email = sprintf('register-no-terms-page-%s@example.test', $suffix);

        $this->browser()
            ->visit('/register')
            ->assertSuccessful()
            ->assertNotSee('href="/legal/terms-of-service"')
            ->fillField('registration_form[username]', $username)
            ->fillField('registration_form[email]', $email)
            ->fillField('registration_form[plainPassword]', 'super-secret-password')
            ->checkField('registration_form[acceptTerms]')
            ->click('Register')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Check your email')
        ;
    }

    public function testUserCanRegisterButIsNotAutoLoggedInAfterConsentDecision(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $username = sprintf('register-consented-%s', $suffix);
        $email = sprintf('register-consented-%s@example.test', $suffix);

        $this->acceptEssentialCookies($this->browser())
            ->visit('/register')
            ->fillField('registration_form[username]', $username)
            ->fillField('registration_form[email]', $email)
            ->fillField('registration_form[plainPassword]', 'super-secret-password')
            ->checkField('registration_form[acceptTerms]')
            ->click('Register')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Check your email')
            ->assertSee('Your registration was almost successful.')
            ->assertNotSee($username)
        ;
    }

    public function testVerifyEmailRedirectsToLoginWithSuccessMessage(): void
    {
        $user = UserFactory::new([
            'isVerified' => false,
            'username' => 'verify-me',
        ])->create();

        /** @var VerifyEmailHelperInterface $helper */
        $helper = self::getContainer()->get(VerifyEmailHelperInterface::class);
        $signedUrl = $helper->generateSignature(
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

    public function testRegistrationPasswordFieldHasAccessibleVisibilityToggle(): void
    {
        $this->browser()
            ->visit('/register')
            ->assertSuccessful()
            ->assertSeeElement('input[name="registration_form[plainPassword]"][type="password"]')
            ->assertSeeElement('[data-testid="password-toggle-registration_form_plainPassword"]')
            ->use(static function (Crawler $crawler): void {
                $input = $crawler->filter('input[name="registration_form[plainPassword]"]');
                $toggle = $crawler->filter('[data-testid="password-toggle-registration_form_plainPassword"]');

                self::assertSame('registration_form[plainPassword]', $input->attr('name'));
                self::assertNull($input->attr('autocomplete'));
                self::assertSame('password', $input->attr('type'));
                self::assertSame('button', $toggle->attr('type'));
                self::assertSame('false', $toggle->attr('aria-pressed'));
                self::assertSame('registration_form_plainPassword', $toggle->attr('aria-controls'));
                self::assertNotEmpty($toggle->attr('aria-label'));
                self::assertSame('Show password', $toggle->attr('aria-label'));
            })
        ;
    }
}
