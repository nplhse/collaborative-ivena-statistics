<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

class SecurityControllerTest extends WebTestCase
{
    use HasBrowser;

    public function testYouCanLoginAndLogout(): void
    {
        // Act& Assert
        $this->browser()
            ->visit('/login')
            ->assertSeeIn('title', 'Login')
            ->assertSeeIn('h2', 'Login')
            ->fillField('Username', 'foo')
            ->fillField('Password', 'bar')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSeeIn('#user_name', 'foo')
            ->assertNotSee('Login')
            ->visit('/logout')
            ->assertNotSee('foo')
        ;
    }
}
