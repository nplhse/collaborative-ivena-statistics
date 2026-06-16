<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuditLogCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanOpenAuditLogIndex(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'audit-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin->_real());
        $client->request(Request::METHOD_GET, '/admin/audit-log');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Audit log');
    }

    public function testNonAdminUserGetsForbiddenOnAuditLogIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'audit-regular-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user->_real());
        $client->request(Request::METHOD_GET, '/admin/audit-log');

        self::assertResponseStatusCodeSame(403);
    }
}
