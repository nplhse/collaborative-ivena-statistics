<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Doctrine;

use App\Allocation\Domain\Entity\State;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Shared\Infrastructure\Audit\Repository\AuditEntryRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AuditingDoctrineSubscriberTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;

    private AuditEntryRepository $auditRepo;

    private AuditContext $auditContext;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->auditRepo = $c->get(AuditEntryRepository::class);
        $this->auditContext = $c->get(AuditContext::class);
    }

    public function testPersistAuditedStateCreatesCreateAuditEntry(): void
    {
        $user = UserFactory::createOne(['username' => 'audit-state-'.bin2hex(random_bytes(6))]);
        $this->loginAs($user);

        $before = $this->countAuditEntries(State::class, 'create');

        $state = new State();
        $state->setName('AuditTestState');
        $this->em->persist($state);
        $this->em->flush();

        self::assertSame($before + 1, $this->countAuditEntries(State::class, 'create'));

        $entry = $this->findLatestAudit(State::class, 'create');
        self::assertNotNull($entry);
        self::assertSame((string) $state->getId(), $entry->getEntityId());
        self::assertNotEmpty($entry->getChanges());
        self::assertArrayHasKey('name', $entry->getChanges());
        self::assertSame('AuditTestState', $entry->getChanges()['name']['new']);
    }

    public function testBeginIntentBeforeFlushAddsMetadataToAuditEntry(): void
    {
        $user = UserFactory::createOne(['username' => 'audit-intent-'.bin2hex(random_bytes(6))]);
        $this->loginAs($user);

        $this->auditContext->beginIntent('test.intent.example', ['foo' => 'bar']);
        try {
            $state = new State();
            $state->setName('IntentState');
            $this->em->persist($state);
            $this->em->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        $entry = $this->findLatestAudit(State::class, 'create');
        self::assertNotNull($entry);
        $meta = $entry->getMetadata();
        self::assertIsArray($meta);
        self::assertSame('test.intent.example', $meta['intent']);
        self::assertSame(['foo' => 'bar'], $meta['intent_metadata']);
    }

    public function testNewUserPersistMasksPasswordInAuditCreate(): void
    {
        $before = $this->countAuditEntries(User::class, 'create');

        UserFactory::createOne(['username' => 'audit-user-pw-'.bin2hex(random_bytes(6))]);

        self::assertSame($before + 1, $this->countAuditEntries(User::class, 'create'));

        $entry = $this->findLatestAudit(User::class, 'create');
        self::assertNotNull($entry);
        self::assertArrayHasKey('password', $entry->getChanges());
        self::assertSame('********', $entry->getChanges()['password']['new']);
    }

    public function testUserPasswordChangeMasksValuesInAuditUpdate(): void
    {
        $proxy = UserFactory::createOne(['username' => 'audit-user-upd-'.bin2hex(random_bytes(6))]);
        $this->loginAs($proxy);

        $user = $this->em->getRepository(User::class)->find($proxy->getId());
        self::assertInstanceOf(User::class, $user);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'new-secret-password'));

        $before = $this->countAuditEntries(User::class, 'update');
        $this->em->flush();
        self::assertSame($before + 1, $this->countAuditEntries(User::class, 'update'));

        $entry = $this->findLatestAudit(User::class, 'update');
        self::assertNotNull($entry);
        self::assertArrayHasKey('password', $entry->getChanges());
        self::assertSame('********', $entry->getChanges()['password']['old']);
        self::assertSame('********', $entry->getChanges()['password']['new']);
    }

    public function testUpdateAuditedEntityCreatesUpdateAuditEntry(): void
    {
        $user = UserFactory::createOne(['username' => 'audit-state-upd-'.bin2hex(random_bytes(6))]);
        $this->loginAs($user);

        $state = new State();
        $state->setName('Before');
        $this->em->persist($state);
        $this->em->flush();

        $state->setName('After');
        $before = $this->countAuditEntries(State::class, 'update');
        $this->em->flush();

        self::assertSame($before + 1, $this->countAuditEntries(State::class, 'update'));
        $entry = $this->findLatestAudit(State::class, 'update');
        self::assertNotNull($entry);
        self::assertSame('Before', $entry->getChanges()['name']['old']);
        self::assertSame('After', $entry->getChanges()['name']['new']);
    }

    public function testDeleteAuditedEntityCreatesDeleteAuditEntry(): void
    {
        $user = UserFactory::createOne(['username' => 'audit-state-del-'.bin2hex(random_bytes(6))]);
        $this->loginAs($user);

        $state = new State();
        $state->setName('ToDelete');
        $this->em->persist($state);
        $this->em->flush();

        $id = (string) $state->getId();

        $before = $this->countAuditEntries(State::class, 'delete');
        $this->em->remove($state);
        $this->em->flush();

        self::assertSame($before + 1, $this->countAuditEntries(State::class, 'delete'));
        $entry = $this->findLatestAudit(State::class, 'delete');
        self::assertNotNull($entry);
        self::assertSame($id, $entry->getEntityId());
        self::assertSame([], $entry->getChanges());
    }

    private function loginAs(object $user): void
    {
        $id = $user->getId();
        self::assertNotNull($id);
        $user = $this->em->getRepository(User::class)->find($id);
        self::assertInstanceOf(User::class, $user);

        $tokenStorage = self::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function countAuditEntries(string $entityClass, string $action): int
    {
        return (int) $this->auditRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.entityClass = :class')
            ->andWhere('a.action = :action')
            ->setParameter('class', $entityClass)
            ->setParameter('action', $action)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findLatestAudit(string $entityClass, string $action): ?AuditEntry
    {
        /** @var ?AuditEntry $row */
        $row = $this->auditRepo->createQueryBuilder('a')
            ->andWhere('a.entityClass = :class')
            ->andWhere('a.action = :action')
            ->setParameter('class', $entityClass)
            ->setParameter('action', $action)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }
}
