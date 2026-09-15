<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\AdministrativeUser;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Application\AdministrativeUser\CreateUserInput;
use App\User\Application\AdministrativeUser\IdentityTakenException;
use App\User\Infrastructure\AdministrativeUser\DoctrineAdministrativeUserService;
use App\User\Infrastructure\Registration\RegistrationIdentityGuard;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DoctrineAdministrativeUserServiceTest extends TestCase
{
    public function testCreateMapsUniqueConstraintViolationToIdentityTaken(): void
    {
        $driverException = new class('duplicate key') extends \Exception implements Driver\Exception {
            #[\Override]
            public function getSQLState(): string
            {
                return '23505';
            }
        };

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush')->willThrowException(
            new UniqueConstraintViolationException($driverException, null),
        );
        $entityManager->expects(self::once())->method('clear');

        $this->expectException(IdentityTakenException::class);

        $this->service($entityManager)->create(new CreateUserInput(
            username: 'alice',
            email: 'alice@example.test',
            plainPassword: 'super-secret-password',
            grantAdmin: false,
        ));
    }

    public function testCreateThrowsWhenPersistedUserHasNoId(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Persisted user has no id.');

        $this->service($entityManager, $eventDispatcher)->create(new CreateUserInput(
            username: 'alice',
            email: 'alice@example.test',
            plainPassword: 'super-secret-password',
            grantAdmin: false,
        ));
    }

    private function service(
        EntityManagerInterface $entityManager,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): DoctrineAdministrativeUserService {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed');

        $identityGuard = $this->createStub(RegistrationIdentityGuard::class);
        $identityGuard->method('isIdentityTaken')->willReturn(false);

        $reflection = new \ReflectionClass(DoctrineAdministrativeUserService::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $bindings = [
            'entityManager' => $entityManager,
            'passwordHasher' => $passwordHasher,
            'validator' => $validator,
            'registrationIdentityGuard' => $identityGuard,
            'auditContext' => new AuditContext(),
            'eventDispatcher' => $eventDispatcher ?? $this->createStub(EventDispatcherInterface::class),
        ];
        foreach ($bindings as $property => $value) {
            $reflection->getProperty($property)->setValue($instance, $value);
        }

        return $instance;
    }
}
