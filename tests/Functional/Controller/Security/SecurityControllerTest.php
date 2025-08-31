<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Security;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class SecurityControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testYouCanLoginAndLogout(): void
    {
        // Arrange
        UserFactory::new(['username' => 'foo'])->create();

        // Act& Assert
        $this->browser()
            ->visit('/login')
            ->assertSeeIn('title', 'Login')
            ->assertSeeIn('h2', 'Login')
            ->fillField('Username', 'foo')
            ->fillField('Password', 'password')
            ->click('Sign in')
            ->assertSuccessful()
            ->assertSeeIn('#user_name', 'foo')
            ->assertNotSee('Login')
            ->visit('/logout')
            ->assertNotSee('foo')
        ;
    }
}
