<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SettingsControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testAuthenticatedUserCanOpenSettingsOverview(): void
    {
        UserFactory::new([
            'email' => 'settings@example.test',
            'isVerified' => true,
            'username' => 'settings-user',
        ])->create();

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'settings-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->visit('/settings')
            ->assertSuccessful()
            ->assertSee('Account Settings')
        ;
    }

    public function testUnverifiedUserSeesResendVerificationAction(): void
    {
        UserFactory::new([
            'email' => 'unverified-settings@example.test',
            'isVerified' => false,
            'username' => 'unverified-settings-user',
        ])->create();

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'unverified-settings-user')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->visit('/settings')
            ->assertSuccessful()
            ->assertSee('Resend verification email')
        ;
    }

    public function testResendVerificationIsRateLimited(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $username = sprintf('limited-settings-user-%s', $suffix);
        $email = sprintf('limited-settings-%s@example.test', $suffix);

        UserFactory::new([
            'email' => $email,
            'isVerified' => false,
            'username' => $username,
        ])->create();

        $browser = $this->browser()
            ->visit('/login')
            ->fillField('Username', $username)
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->visit('/settings')
            ->assertSuccessful()
        ;

        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Verification email sent.');
        $browser->click('Resend verification email')->assertSee('Please wait before requesting another verification email.');
    }
}
