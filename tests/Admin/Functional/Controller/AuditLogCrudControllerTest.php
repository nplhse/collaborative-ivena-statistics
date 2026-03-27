<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AuditLogCrudControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testAdminCanOpenAuditLogIndex(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'audit-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/audit-log')
            ->assertSuccessful()
            ->assertSee('Audit log')
        ;
    }

    public function testNonAdminUserGetsForbiddenOnAuditLogIndex(): void
    {
        $user = UserFactory::createOne([
            'username' => 'audit-regular-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/admin/audit-log')
            ->assertStatus(403)
        ;
    }
}
