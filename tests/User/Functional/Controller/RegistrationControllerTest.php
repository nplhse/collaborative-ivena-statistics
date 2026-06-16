<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RegistrationControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;
    use MailerAssertionsTrait;

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
                ->fillField('Username', $username)
                ->fillField('Email', $email)
                ->fillField('Plain password', 'super-secret-password')
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
            ->fillField('Username', $username)
            ->fillField('Email', $email)
            ->fillField('Plain password', 'super-secret-password')
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
            ->fillField('Username', $username)
            ->fillField('Email', $email)
            ->fillField('Plain password', 'super-secret-password')
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
        ])->_real();

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
            ->fillField('Username', $username)
            ->fillField('Email', $email)
            ->fillField('Plain password', 'super-secret-password')
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
            ->fillField('Username', $username)
            ->fillField('Email', $email)
            ->fillField('Plain password', 'super-secret-password')
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

        $user->_refresh();
        self::assertTrue($user->isVerified());
    }
}
