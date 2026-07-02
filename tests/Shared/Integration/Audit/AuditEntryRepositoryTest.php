<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Audit;

use App\Allocation\Domain\Entity\State;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Shared\Infrastructure\Audit\Repository\AuditEntryRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class AuditEntryRepositoryTest extends DatabaseKernelTestCase
{
    private AuditEntryRepository $repository;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(AuditEntryRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFindRecentByIntentsReturnsEmptyForNoIntents(): void
    {
        self::assertSame([], $this->repository->findRecentByIntents([]));
    }

    public function testFindRecentByIntentsFiltersByIntentAndRespectsLimit(): void
    {
        $this->persistAuditEntry(
            occurredAt: new \DateTimeImmutable('2025-06-05 12:00:00'),
            intent: 'other.intent',
            action: 'create',
            origin: 'cli',
        );
        $this->persistAuditEntry(
            occurredAt: new \DateTimeImmutable('2025-06-04 12:00:00'),
            intent: null,
            action: 'create',
            origin: 'cli',
        );
        $this->persistAuditEntry(
            occurredAt: new \DateTimeImmutable('2025-06-03 12:00:00'),
            intent: 'import.failed',
            action: 'update',
            origin: 'messenger',
        );
        $this->persistAuditEntry(
            occurredAt: new \DateTimeImmutable('2025-06-02 12:00:00'),
            intent: 'user.registration',
            action: 'create',
            origin: 'http',
        );
        $this->persistAuditEntry(
            occurredAt: new \DateTimeImmutable('2025-06-01 12:00:00'),
            intent: 'hospital.reminder_sent',
            action: 'create',
            origin: 'cli',
        );

        $results = $this->repository->findRecentByIntents([
            'import.failed',
            'user.registration',
            'hospital.reminder_sent',
        ], 2);

        self::assertCount(2, $results);
        self::assertSame('import.failed', $results[0]['intent']);
        self::assertSame('update', $results[0]['action']);
        self::assertSame('messenger', $results[0]['origin']);
        self::assertSame('user.registration', $results[1]['intent']);
        self::assertSame('create', $results[1]['action']);
        self::assertSame('http', $results[1]['origin']);
    }

    private function persistAuditEntry(
        \DateTimeImmutable $occurredAt,
        ?string $intent,
        string $action,
        string $origin,
    ): void {
        $metadata = null;
        if (null !== $intent) {
            $metadata = ['intent' => $intent];
        }

        $entry = new AuditEntry(
            $occurredAt,
            'audit-repo-test-'.bin2hex(random_bytes(4)),
            null,
            $origin,
            $action,
            State::class,
            '1',
            [],
            $metadata,
        );

        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }
}
