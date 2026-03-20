<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RegistrationControllerTest extends WebTestCase
{
    use HasBrowser;
    use ResetDatabase;

    public function testUserCanRegister(): void
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
            ->assertSeeIn('#user_name', $username)
            ->assertSee('You are now signed in. Please verify your email address to unlock all account features.')
        ;
    }
}
