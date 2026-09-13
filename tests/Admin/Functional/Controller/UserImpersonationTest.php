<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Shared\Infrastructure\Audit\Repository\AuditEntryRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class UserImpersonationTest extends WebTestCase
{
    use Factories;
    use HasBrowser;

    public function testAdminCanImpersonateParticipant(): void
    {
        $target = UserFactory::createOne([
            'username' => 'participant-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'impersonator-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/?_switch_user='.$target->getUserIdentifier())
            ->assertSuccessful()
            ->assertSeeIn('body', 'End impersonation')
            ->assertSeeIn('body', $target->getUserIdentifier())
        ;
    }

    public function testNonAdminCannotImpersonate(): void
    {
        $target = UserFactory::createOne([
            'username' => 'target-'.bin2hex(random_bytes(4)),
        ]);
        $user = UserFactory::createOne([
            'username' => 'regular-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/?_switch_user='.$target->getUserIdentifier())
            ->assertStatus(403)
        ;
    }

    public function testAdminCannotImpersonateOtherAdmin(): void
    {
        $targetAdmin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'other-admin-'.bin2hex(random_bytes(4)),
            ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'impersonator-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/?_switch_user='.$targetAdmin->getUserIdentifier())
            ->assertStatus(403)
        ;
    }

    public function testAdminCanExitImpersonation(): void
    {
        $target = UserFactory::createOne([
            'username' => 'exit-target-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'exit-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $browser = $this->browser()
            ->actingAs($admin)
            ->visit('/?_switch_user='.$target->getUserIdentifier())
            ->assertSuccessful()
            ->assertSeeIn('body', 'End impersonation')
        ;

        $browser
            ->visit('/?_switch_user=_exit')
            ->assertSuccessful()
            ->assertNotSeeIn('body', 'End impersonation')
            ->assertSeeIn('body', $admin->getUserIdentifier())
        ;

        $adminId = $admin->getId();
        self::assertNotNull($adminId);

        $exits = self::getContainer()->get(AuditEntryRepository::class)->findBy(
            ['action' => 'impersonate_exit'],
            ['occurredAt' => 'DESC'],
            1,
        );

        self::assertCount(1, $exits);
        $entry = $exits[0];
        self::assertSame(User::class, $entry->getEntityClass());
        self::assertSame((string) $target->getId(), $entry->getEntityId());
        self::assertNotNull($entry->getActor());
        self::assertSame($adminId, $entry->getActor()->getId());
    }

    public function testExitWithoutImpersonationDoesNotFail(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'noop-exit-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/?_switch_user=_exit')
            ->assertSuccessful()
            ->assertNotSeeIn('body', 'End impersonation')
            ->assertSeeIn('body', $admin->getUserIdentifier())
        ;

        self::assertCount(0, self::getContainer()->get(AuditEntryRepository::class)->findBy(
            ['action' => 'impersonate_exit'],
        ));
    }

    public function testExitAfterSuccessfulExitDoesNotFail(): void
    {
        $target = UserFactory::createOne([
            'username' => 'double-exit-target-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'double-exit-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/?_switch_user='.$target->getUserIdentifier())
            ->assertSuccessful()
            ->visit('/?_switch_user=_exit')
            ->assertSuccessful()
            ->assertNotSeeIn('body', 'End impersonation')
            ->visit('/?_switch_user=_exit')
            ->assertSuccessful()
            ->assertNotSeeIn('body', 'End impersonation')
            ->assertSeeIn('body', $admin->getUserIdentifier())
        ;

        self::assertCount(1, self::getContainer()->get(AuditEntryRepository::class)->findBy(
            ['action' => 'impersonate_exit'],
        ));
    }

    public function testAnonymousExitImpersonationRedirectsToLogin(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/?_switch_user=_exit')
            ->assertRedirectedTo('/login')
        ;
    }

    public function testImpersonationIsRecordedInAuditLog(): void
    {
        $target = UserFactory::createOne([
            'username' => 'audit-target-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'audit-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/?_switch_user='.$target->getUserIdentifier())
            ->assertSuccessful()
        ;

        $adminId = $admin->getId();
        self::assertNotNull($adminId);

        $entries = self::getContainer()->get(AuditEntryRepository::class)->findBy(
            ['action' => 'impersonate'],
            ['occurredAt' => 'DESC'],
            1,
        );

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(User::class, $entry->getEntityClass());
        self::assertSame((string) $target->getId(), $entry->getEntityId());
        self::assertNotNull($entry->getActor());
        self::assertSame($adminId, $entry->getActor()->getId());
    }
}
