<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class SecurityControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testLoginIsBlockedWithoutConsentDecision(): void
    {
        UserFactory::new(['username' => 'foo'])->create();

        $this->browser()
            ->visit('/login')
            ->fillField('Username', 'foo')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSeeIn('.alert.alert-danger', 'Please confirm your cookie settings before signing in.')
            ->assertNotSee('foo')
        ;
    }

    public function testYouCanLoginAndLogoutAfterEssentialConsent(): void
    {
        UserFactory::new(['username' => 'foo'])->create();

        $this->loginWithConsent($this->browser(), 'foo')
            ->assertSeeIn('#user_name', 'foo')
            ->assertNotSee('Login')
            ->visit('/logout')
            ->assertNotSee('foo')
        ;
    }
}
