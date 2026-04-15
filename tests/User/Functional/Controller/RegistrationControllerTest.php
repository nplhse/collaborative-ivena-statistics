<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RegistrationControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
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
            ->assertSeeIn('h2', 'Login')
            ->assertSee('Registration successful. Please verify your email address.')
            ->assertNotSee('Please confirm your cookie settings before signing in.')
        ;
    }

    public function testUserCanRegisterAndIsAutoLoggedInAfterConsentDecision(): void
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
            ->assertSeeIn('#user_name', $username)
            ->assertSee('Registration successful. Please verify your email address.')
        ;
    }
}
