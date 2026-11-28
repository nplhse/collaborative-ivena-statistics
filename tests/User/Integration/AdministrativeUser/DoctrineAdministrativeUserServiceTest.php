<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\AdministrativeUser;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\User\Support\AlwaysAvailableRegistrationIdentityChecker;
use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\AdministrativeUserValidationException;
use App\User\Application\AdministrativeUser\CreateUserInput;
use App\User\Application\AdministrativeUser\IdentityTakenException;
use App\User\Application\AdministrativeUser\LastAdministratorException;
use App\User\Application\AdministrativeUser\UserNotDeletableException;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\AdministrativeUser\DoctrineAdministrativeUserService;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Zenstruck\Foundry\Persistence\refresh;

final class DoctrineAdministrativeUserServiceTest extends DatabaseKernelTestCase
{
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

    public function testCreateMapsUniqueConstraintRaceToIdentityTaken(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        try {
            $this->serviceWithAlwaysAvailableIdentity()->create(new CreateUserInput(
                username: 'alice',
                email: 'other@example.test',
                plainPassword: 'super-secret-password',
                grantAdmin: false,
            ));
            self::fail('Expected IdentityTakenException when the unique constraint races.');
        } catch (IdentityTakenException) {
        }

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        self::assertSame(1, $repository->count(['username' => 'alice']));
    }

    public function testFindByUsernameTrimsTheInput(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $found = $this->service()->findByUsername('  alice  ');

        self::assertInstanceOf(User::class, $found);
        self::assertSame('alice', $found->getUsername());
    }

    public function testFindByUsernameReturnsNullForBlankInput(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $found = $this->service()->findByUsername('   ');

        self::assertNull($found);
    }

    public function testPromoteGrantsTheAdminRoleWithAnAuditEntry(): void
    {
        $user = UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $this->service()->promoteToAdmin($user);

        refresh($user);
        self::assertContains(UserRole::ADMIN, $user->getRoles());
        self::assertSame(1, $this->promotionAuditEntries());
    }

    public function testPromoteOfAnAdministratorChangesNothing(): void
    {
        $user = UserFactory::new()->asAdmin()->create([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $this->service()->promoteToAdmin($user);

        refresh($user);
        self::assertContains(UserRole::ADMIN, $user->getRoles());
        // No flush, so no audit entry for a promotion that did not happen.
        self::assertSame(0, $this->promotionAuditEntries());
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

        refresh($user);
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

    public function testDeleteThrowsWhenOtherRecordsReferenceTheUser(): void
    {
        $alice = UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test']);
        $bob = UserFactory::createOne(['username' => 'bob', 'email' => 'bob@example.test']);
        // alice created the hospital bob owns: the foreign key from hospital.created_by blocks the deletion
        HospitalFactory::createOne(['owner' => $bob, 'createdBy' => $alice, 'name' => 'Referenced Hospital']);

        try {
            $this->service()->delete($alice);
            self::fail('Expected UserNotDeletableException for a referenced user.');
        } catch (UserNotDeletableException $exception) {
            self::assertSame(UserNotDeletableException::REFERENCED, $exception->getMessage());
        }

        self::assertSame(1, self::getContainer()->get(UserRepository::class)->count(['username' => 'alice']));
    }

    private function promotionAuditEntries(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE intent = 'user.cli.promoted_admin'",
        );
    }

    private function service(): AdministrativeUserService
    {
        /** @var AdministrativeUserService $service */
        $service = self::getContainer()->get(AdministrativeUserService::class);

        return $service;
    }

    private function serviceWithAlwaysAvailableIdentity(): DoctrineAdministrativeUserService
    {
        $container = self::getContainer();

        return new DoctrineAdministrativeUserService(
            $container->get(EntityManagerInterface::class),
            $container->get(UserRepository::class),
            $container->get(UserPasswordHasherInterface::class),
            $container->get(ValidatorInterface::class),
            new AlwaysAvailableRegistrationIdentityChecker($container->get(TranslatorInterface::class)),
            $container->get(AuditContext::class),
            $container->get(EventDispatcherInterface::class),
        );
    }
}
