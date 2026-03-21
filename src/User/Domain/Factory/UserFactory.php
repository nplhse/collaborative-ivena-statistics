<?php

namespace App\User\Domain\Factory;

use App\User\Domain\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<User>
 */
final class UserFactory extends PersistentProxyObjectFactory
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    #[\Override]
    public static function class(): string
    {
        return User::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $suffix = bin2hex(random_bytes(8));

        return [
            'credentialsExpired' => false,
            'email' => sprintf('user-%s@example.test', $suffix),
            'isVerified' => true,
            'password' => 'password',
            'roles' => ['ROLE_USER'],
            'username' => sprintf('%s-%s', self::faker()->userName(), $suffix),
        ];
    }

    /** @psalm-suppress MoreSpecificReturnType */
    #[\Override]
    protected function initialize(): static
    {
        /** @var static $factory */
        $factory = $this
            ->afterInstantiate(function (User $user): void {
                $password = $user->getPassword();

                if (null === $password || '' === $password) {
                    throw new \LogicException('Password must not be null');
                }

                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            })
        ;

        return $factory;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function asAdmin(): self
    {
        return $this->with(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
    }
}
