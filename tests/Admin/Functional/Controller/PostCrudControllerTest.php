<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PostCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanOpenBlogPostIndex(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'blog-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin->_real());
        $client->request(Request::METHOD_GET, '/admin/post');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Blog posts');
    }

    public function testNonAdminUserGetsForbiddenOnBlogPostIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'blog-regular-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user->_real());
        $client->request(Request::METHOD_GET, '/admin/post');

        self::assertResponseStatusCodeSame(403);
    }
}
