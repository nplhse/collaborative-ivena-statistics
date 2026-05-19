<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageCrudControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testAdminCanOpenPageIndexAndNewForm(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'page-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/page')
            ->assertSuccessful()
            ->assertSee('Pages')
            ->visit('/admin/page/new')
            ->assertSuccessful()
            ->assertSee('Create Page')
        ;
    }

    public function testNonAdminUserGetsForbiddenOnPageIndex(): void
    {
        $user = UserFactory::createOne([
            'username' => 'page-regular-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/admin/page')
            ->assertStatus(403)
        ;
    }
}
