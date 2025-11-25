<?php

namespace App\Tests\User\Integration\Doctrine;

use App\Allocation\Domain\Entity\State;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class BlamableListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPrePersistSetsCreatedByToCurrentUser(): void
    {
        $user = UserFactory::createOne();
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
        $user = UserFactory::createOne();
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
        $user = UserFactory::createOne();
        $presetUser = UserFactory::createOne();
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

        $tokenStorage = static::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
