<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Doctrine;

use App\Allocation\Domain\Entity\State;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class BlamableListenerTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPrePersistSetsCreatedByToCurrentUser(): void
    {
        $user = UserFactory::createOne(['username' => 'blamable-created-by']);
        $this->loginAs($user);

        $state = new State();
        $state->setName('Foo');

        $this->em->persist($state);
        $this->em->flush();

        self::assertSame($user->getUserIdentifier(), $state->getCreatedBy()->getUserIdentifier());
        self::assertNull($state->getUpdatedBy());
    }

    public function testPreUpdateSetsUpdatedByOnUpdate(): void
    {
        $user = UserFactory::createOne(['username' => 'blamable-updated-by']);
        $this->loginAs($user);

        $state = new State();
        $state->setName('Before');
        $this->em->persist($state);
        $this->em->flush();

        $state->setName('After');
        $this->em->flush();

        self::assertSame($user->getUserIdentifier(), $state->getUpdatedBy()->getUserIdentifier());
    }

    public function testPrePersistDoesNotOverrideExistingCreatedBy(): void
    {
        $user = UserFactory::createOne(['username' => 'blamable-acting-user']);
        $presetUser = UserFactory::createOne(['username' => 'blamable-preset-user']);
        $this->loginAs($user);

        $presetUser = $this->em->getRepository(User::class)->find($presetUser->getId());

        $state = new State();
        $state->setName('Test');
        $state->setCreatedBy($presetUser);
        $this->em->persist($state);
        $this->em->flush();

        self::assertSame($presetUser->getUserIdentifier(), $state->getCreatedBy()->getUserIdentifier());
    }

    private function loginAs(User $user): void
    {
        $user = $this->em->getRepository(User::class)->find($user->getId());

        $tokenStorage = self::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
