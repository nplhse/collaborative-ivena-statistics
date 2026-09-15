<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\AdministrativeUser;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\AdministrativeUserValidationException;
use App\User\Application\AdministrativeUser\CreateUserInput;
use App\User\Application\AdministrativeUser\IdentityTakenException;
use App\User\Application\AdministrativeUser\LastAdministratorException;
use App\User\Application\AdministrativeUser\UserNotDeletableException;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DoctrineAdministrativeUserServiceTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function testCreatePersistsEnabledVerifiedUser(): void
    {
        $user = $this->service()->create(new CreateUserInput(
            username: 'alice',
            email: 'alice@example.test',
            plainPassword: 'super-secret-password',
            grantAdmin: false,
        ));

        self::assertTrue($user->isEnabled());
        self::assertTrue($user->isVerified());
        self::assertNotContains(UserRole::ADMIN, $user->getRoles());
    }

    public function testCreateRejectsWeakPassword(): void
    {
        $this->expectException(AdministrativeUserValidationException::class);

        $this->service()->create(new CreateUserInput(
            username: 'alice',
            email: 'alice@example.test',
            plainPassword: 'short',
            grantAdmin: false,
        ));
    }

    public function testCreateRejectsTakenIdentity(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $this->expectException(IdentityTakenException::class);

        $this->service()->create(new CreateUserInput(
            username: 'alice',
            email: 'other@example.test',
            plainPassword: 'super-secret-password',
            grantAdmin: false,
        ));
    }

    public function testFindByUsernameTrimsAndReturnsNullForBlank(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $service = $this->service();
        $found = $service->findByUsername('  alice  ');
        self::assertInstanceOf(User::class, $found);
        self::assertSame('alice', $found->getUsername());
        self::assertNull($service->findByUsername('   '));
    }

    public function testPromoteIsNoOpWhenAlreadyAdmin(): void
    {
        $user = UserFactory::new()->asAdmin()->create([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $this->service()->promoteToAdmin($user);

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertContains(UserRole::ADMIN, $user->getRoles());
    }

    public function testDemoteThrowsForLastAdministrator(): void
    {
        $user = UserFactory::new()->asAdmin()->create([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $this->expectException(LastAdministratorException::class);
        $this->service()->demoteFromAdmin($user);
    }

    public function testDemoteIsNoOpWhenUserIsNotAdmin(): void
    {
        $user = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $this->service()->demoteFromAdmin($user);

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertNotContains(UserRole::ADMIN, $user->getRoles());
    }

    public function testDeleteThrowsForLastAdministrator(): void
    {
        $user = UserFactory::new()->asAdmin()->create([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $this->expectException(UserNotDeletableException::class);
        $this->expectExceptionMessage(UserNotDeletableException::LAST_ADMINISTRATOR);
        $this->service()->delete($user);
    }

    public function testDeleteThrowsWhenUserOwnsHospitals(): void
    {
        $user = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);
        HospitalFactory::createOne([
            'owner' => $user,
            'name' => 'Owned Hospital',
        ]);

        $this->expectException(UserNotDeletableException::class);
        $this->expectExceptionMessage(UserNotDeletableException::OWNS_HOSPITALS);
        $this->service()->delete($user);
    }

    private function service(): AdministrativeUserService
    {
        /** @var AdministrativeUserService $service */
        $service = self::getContainer()->get(AdministrativeUserService::class);

        return $service;
    }
}
