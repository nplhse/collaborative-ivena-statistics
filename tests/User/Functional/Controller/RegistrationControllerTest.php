<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RegistrationControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;
    use ResetDatabase;

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
            ->click('Register')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Check your email')
            ->assertSee('Your registration was almost successful.')
            ->assertNotSee($username)
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
