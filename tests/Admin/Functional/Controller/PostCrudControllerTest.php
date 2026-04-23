<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PostCrudControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testAdminCanOpenBlogPostIndex(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'blog-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/post')
            ->assertSuccessful()
            ->assertSee('Blog posts')
        ;
    }

    public function testNonAdminUserGetsForbiddenOnBlogPostIndex(): void
    {
        $user = UserFactory::createOne([
            'username' => 'blog-regular-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/admin/post')
            ->assertStatus(403)
        ;
    }
}
