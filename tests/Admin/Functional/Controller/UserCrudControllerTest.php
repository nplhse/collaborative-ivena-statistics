<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UserCrudControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testAdminCanDisableAndReenableUser(): void
    {
        $target = UserFactory::createOne([
            'username' => 'target-user-'.bin2hex(random_bytes(4)),
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'user-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/user/'.$target->getId().'/edit')
            ->assertSuccessful()
            ->uncheckField('User[isEnabled]')
            ->click('Save changes')
            ->assertSuccessful()
        ;

        $target->_refresh();
        self::assertFalse($target->isEnabled());

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/user/'.$target->getId().'/edit')
            ->assertSuccessful()
            ->checkField('User[isEnabled]')
            ->click('Save changes')
            ->assertSuccessful()
        ;

        $target->_refresh();
        self::assertTrue($target->isEnabled());
    }

    public function testAdminCannotDisableOwnAccount(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'self-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $adminId = $admin->getId();
        self::assertNotNull($adminId);

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/user/'.$adminId.'/edit')
            ->assertSuccessful()
            ->uncheckField('User[isEnabled]')
            ->click('Save changes')
            ->assertStatus(500)
        ;

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloadedAdmin = self::getContainer()->get(UserRepository::class)->find($adminId);
        self::assertInstanceOf(User::class, $reloadedAdmin);
        self::assertTrue($reloadedAdmin->isEnabled());
    }

    public function testNonAdminUserGetsForbiddenOnUserIndex(): void
    {
        $user = UserFactory::createOne([
            'username' => 'user-regular-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/admin/user')
            ->assertStatus(403)
        ;
    }
}
