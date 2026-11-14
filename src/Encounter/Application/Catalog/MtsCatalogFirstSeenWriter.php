<?php

declare(strict_types=1);

namespace App\Encounter\Application\Catalog;

use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\MtsIndicator;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates provisional MTS catalog entries for codes seen for the first time (ADR 032, #1157).
 *
 * A code that resolves (an entry of the kind with any status, or an alias code of the kind) creates nothing. Every
 * other code becomes an entry with status `provisional`, source `first_seen`, the code as German name (the exchange
 * file carries codes, not labels) and its first-seen time. The insert ignores a conflict on the unique code, so
 * parallel imports of the same code create exactly one entry and never fail; codes are inserted in code order so two
 * imports never wait on each other crosswise.
 *
 * The rows are written with SQL, outside Doctrine's unit of work, so the writer adds the audit entries itself: one
 * `create` entry per created row with the intent `encounter.mts_catalog.first_seen_created`. It runs inside the
 * caller's transaction (the import's guarded commit), so a rolled back import creates nothing.
 */
final readonly class MtsCatalogFirstSeenWriter
{
    public const string INTENT = 'encounter.mts_catalog.first_seen_created';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private ClockInterface $clock,
        private MtsCatalog $catalog,
    ) {
    }

    /**
     * @param array<string, \DateTimeImmutable> $firstSeen     code => first-seen time; empty and invalid codes are ignored
     * @param array<string, mixed>              $auditMetadata what caused the creation, for example the import id
     *
     * @return list<string> codes of the created entries, ordered by code
     */
    public function record(MtsCatalogKind $kind, array $firstSeen, array $auditMetadata = []): array
    {
        $firstSeen = array_filter(
            $firstSeen,
            static fn (string $code): bool => 1 === preg_match('/^[a-z0-9_]{1,64}$/', $code),
            \ARRAY_FILTER_USE_KEY,
        );
        if ([] === $firstSeen) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $table = MtsCatalogKind::Diagram === $kind ? 'encounter_mts_diagram' : 'encounter_mts_indicator';

        /** @var list<string> $known */
        $known = $connection->fetchFirstColumn(
            \sprintf('SELECT code FROM %s WHERE code IN (:codes) UNION SELECT code FROM encounter_mts_alias WHERE kind = :kind AND code IN (:codes)', $table),
            ['codes' => array_map(strval(...), array_keys($firstSeen)), 'kind' => $kind->value],
            ['codes' => ArrayParameterType::STRING],
        );
        $unknown = array_diff_key($firstSeen, array_flip($known));
        ksort($unknown, \SORT_STRING);

        $now = $this->clock->now();
        $created = [];
        foreach ($unknown as $code => $seenAt) {
            $code = (string) $code;
            /** @var int|string|false $id */
            $id = $connection->fetchOne(
                \sprintf('INSERT INTO %s (code, german_name, source, status, first_seen_at, created_at) VALUES (:code, :code, :source, :status, :first_seen_at, :created_at) ON CONFLICT (code) DO NOTHING RETURNING id', $table),
                [
                    'code' => $code,
                    'source' => MtsCatalogSource::FirstSeen->value,
                    'status' => MtsCatalogStatus::Provisional->value,
                    'first_seen_at' => $seenAt,
                    'created_at' => $now,
                ],
                ['first_seen_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE],
            );
            if (false === $id) {
                continue;
            }

            $created[] = $code;
            $this->audit($kind, (string) $id, $code, $seenAt, $auditMetadata);
        }

        if ([] !== $created) {
            $this->entityManager->flush();
            $this->catalog->reset();
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $auditMetadata
     */
    private function audit(MtsCatalogKind $kind, string $id, string $code, \DateTimeImmutable $seenAt, array $auditMetadata): void
    {
        $actorId = $this->auditContext->getActorUserId();
        $metadata = ['intent' => self::INTENT, 'intent_metadata' => ['kind' => $kind->value, 'code' => $code, ...$auditMetadata]];

        $this->entityManager->persist(new AuditEntry(
            $this->clock->now(),
            $this->auditContext->ensureRequestId(static fn (): string => Uuid::v7()->toRfc4122()),
            null === $actorId ? null : $this->entityManager->getReference(User::class, $actorId),
            $this->auditContext->getOrigin() ?? AuditContext::ORIGIN_CLI,
            'create',
            MtsCatalogKind::Diagram === $kind ? MtsDiagram::class : MtsIndicator::class,
            $id,
            [
                'code' => ['old' => null, 'new' => $code],
                'germanName' => ['old' => null, 'new' => $code],
                'source' => ['old' => null, 'new' => MtsCatalogSource::FirstSeen->value],
                'status' => ['old' => null, 'new' => MtsCatalogStatus::Provisional->value],
                'firstSeenAt' => ['old' => null, 'new' => $seenAt->format(\DateTimeInterface::ATOM)],
            ],
            [...$metadata, ...$this->auditContext->requestMetadata()],
            $this->auditContext->getSessionRef(),
        ));
    }
}
