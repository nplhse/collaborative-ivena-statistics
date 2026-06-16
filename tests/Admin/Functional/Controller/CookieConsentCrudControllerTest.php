<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CookieConsentCrudControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;

    public function testAdminCanOpenCookieConsentIndex(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'cookie-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/cookie-consent')
            ->assertSuccessful()
            ->assertSee('Cookie consents')
        ;
    }

    public function testNonAdminUserGetsForbiddenOnCookieConsentIndex(): void
    {
        $user = UserFactory::createOne([
            'username' => 'cookie-regular-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/admin/cookie-consent')
            ->assertStatus(403)
        ;
    }
}
