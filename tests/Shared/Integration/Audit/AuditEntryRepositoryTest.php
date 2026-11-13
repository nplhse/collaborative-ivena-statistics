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
    /** @var list<string> */
    private const array DASHBOARD_INTENTS = [
        AuditEntry::INTENT_IMPORT_RUN_FAILED,
        AuditEntry::INTENT_USER_REGISTERED,
        AuditEntry::INTENT_HOSPITAL_REMINDER_SENT,
    ];

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
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-05 12:00:00'), 'other.intent', 'create', 'cli');
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-04 12:00:00'), null, 'create', 'cli');
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-03 12:00:00'), AuditEntry::INTENT_IMPORT_RUN_FAILED, 'update', 'messenger');
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-02 12:00:00'), AuditEntry::INTENT_USER_REGISTERED, 'create', 'http');
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-01 12:00:00'), AuditEntry::INTENT_HOSPITAL_REMINDER_SENT, 'create', 'cli');

        $results = $this->repository->findRecentByIntents(self::DASHBOARD_INTENTS, 2);

        self::assertCount(2, $results);
        self::assertSame(AuditEntry::INTENT_IMPORT_RUN_FAILED, $results[0]['intent']);
        self::assertSame('update', $results[0]['action']);
        self::assertSame('messenger', $results[0]['origin']);
        self::assertSame('2025-06-03 12:00:00', $results[0]['occurredAt']->format('Y-m-d H:i:s'));
        self::assertSame(AuditEntry::INTENT_USER_REGISTERED, $results[1]['intent']);
        self::assertSame('create', $results[1]['action']);
        self::assertSame('http', $results[1]['origin']);
    }

    public function testFindRecentByIntentsFindsEventsBehindMoreThan200NewerEntries(): void
    {
        // #1089: the query used to scan only the newest 200 rows.
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-01 08:00:00'), AuditEntry::INTENT_IMPORT_RUN_FAILED, 'update', 'messenger');
        for ($i = 0; $i < 210; ++$i) {
            $this->persistAuditEntry(new \DateTimeImmutable('2025-06-02 08:00:00')->modify(sprintf('+%d minutes', $i)), 'other.intent', 'update', 'http', flush: false);
        }
        $this->entityManager->flush();

        $results = $this->repository->findRecentByIntents(self::DASHBOARD_INTENTS);

        self::assertCount(1, $results);
        self::assertSame(AuditEntry::INTENT_IMPORT_RUN_FAILED, $results[0]['intent']);
    }

    public function testFindRecentByIntentsShowsOneRowPerRequestAndIntent(): void
    {
        // One failed import run writes the import and its batch item in the same request.
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-01 08:00:00'), AuditEntry::INTENT_IMPORT_RUN_FAILED, 'update', 'messenger', requestId: 'run-1');
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-01 08:00:00'), AuditEntry::INTENT_IMPORT_RUN_FAILED, 'update', 'messenger', requestId: 'run-1');
        $this->persistAuditEntry(new \DateTimeImmutable('2025-06-01 09:00:00'), AuditEntry::INTENT_IMPORT_RUN_FAILED, 'update', 'messenger', requestId: 'run-2');

        $results = $this->repository->findRecentByIntents(self::DASHBOARD_INTENTS);

        self::assertCount(2, $results);
        self::assertSame('2025-06-01 09:00:00', $results[0]['occurredAt']->format('Y-m-d H:i:s'));
        self::assertSame('2025-06-01 08:00:00', $results[1]['occurredAt']->format('Y-m-d H:i:s'));
    }

    private function persistAuditEntry(
        \DateTimeImmutable $occurredAt,
        ?string $intent,
        string $action,
        string $origin,
        ?string $requestId = null,
        bool $flush = true,
    ): void {
        $metadata = null;
        if (null !== $intent) {
            $metadata = ['intent' => $intent];
        }

        $entry = new AuditEntry(
            $occurredAt,
            $requestId ?? 'audit-repo-test-'.bin2hex(random_bytes(4)),
            null,
            $origin,
            $action,
            State::class,
            '1',
            [],
            $metadata,
        );

        $this->entityManager->persist($entry);
        if ($flush) {
            $this->entityManager->flush();
        }
    }
}
