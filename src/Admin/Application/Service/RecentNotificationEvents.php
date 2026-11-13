<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\Application\DTO\RecentNotificationEventDto;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Shared\Infrastructure\Audit\Repository\AuditEntryRepository;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;

/**
 * Builds the events of the admin dashboard card "Recent notifications" (#1137) from the audit log. Records the
 * events refer to are loaded with one query per type, not per event.
 *
 * @psalm-type AuditEventRow = array{id: int, occurredAt: \DateTimeImmutable, requestId: string, intent: string, entityClass: string, entityId: ?string, intentMetadata: array<string, mixed>}
 */
final readonly class RecentNotificationEvents
{
    /**
     * Events an admin may need to act on; the one place that decides what the card shows (#1165 evaluates more).
     * Each intent needs a label and context in the card template and translations.
     */
    public const array INTENTS = [
        AuditEntry::INTENT_IMPORT_RUN_FAILED,
        AuditEntry::INTENT_USER_REGISTERED,
        AuditEntry::INTENT_HOSPITAL_REMINDER_SENT,
    ];

    public function __construct(
        private AuditEntryRepository $auditEntryRepository,
        private ImportRepository $importRepository,
        private UserRepository $userRepository,
        private HospitalRepository $hospitalRepository,
    ) {
    }

    /**
     * @return list<RecentNotificationEventDto>
     */
    public function latest(int $limit = 10): array
    {
        $rows = $this->auditEntryRepository->findRecentByIntents(self::INTENTS, $limit);

        $importIds = [];
        $userIds = [];
        $hospitalIds = [];
        foreach ($rows as $row) {
            switch ($row['intent']) {
                case AuditEntry::INTENT_IMPORT_RUN_FAILED:
                    $importIds[] = $this->importId($row);
                    break;
                case AuditEntry::INTENT_USER_REGISTERED:
                    $userIds[] = $this->userId($row);
                    break;
                case AuditEntry::INTENT_HOSPITAL_REMINDER_SENT:
                    $hospitalIds[] = $this->intOrNull($row['intentMetadata']['hospital_id'] ?? null);
                    break;
            }
        }

        $imports = $this->importsById($importIds);
        $usernames = $this->usernamesById($userIds);
        $hospitalNames = $this->hospitalNamesById($hospitalIds);

        $events = [];
        foreach ($rows as $row) {
            $events[] = match ($row['intent']) {
                AuditEntry::INTENT_IMPORT_RUN_FAILED => $this->failedImport($row, $imports),
                AuditEntry::INTENT_USER_REGISTERED => $this->registration($row, $usernames),
                default => $this->reminder($row, $hospitalNames),
            };
        }

        return $events;
    }

    /**
     * @param AuditEventRow      $row
     * @param array<int, Import> $imports
     */
    private function failedImport(array $row, array $imports): RecentNotificationEventDto
    {
        $importId = $this->importId($row);
        $import = null === $importId ? null : ($imports[$importId] ?? null);
        $reason = $row['intentMetadata']['reason'] ?? null;

        return new RecentNotificationEventDto(
            intent: $row['intent'],
            occurredAt: $row['occurredAt'],
            requestId: $row['requestId'],
            subject: $import?->getName(),
            subjectId: null === $importId ? null : (string) $importId,
            hospital: $import?->getHospital()?->getName(),
            reasonCode: \is_string($reason) ? ImportRunReasonCode::tryFrom($reason)?->value : null,
        );
    }

    /**
     * @param AuditEventRow      $row
     * @param array<int, string> $usernames
     */
    private function registration(array $row, array $usernames): RecentNotificationEventDto
    {
        $userId = $this->userId($row);

        return new RecentNotificationEventDto(
            intent: $row['intent'],
            occurredAt: $row['occurredAt'],
            requestId: $row['requestId'],
            subject: null === $userId ? null : ($usernames[$userId] ?? null),
            subjectId: null === $userId ? null : (string) $userId,
        );
    }

    /**
     * @param AuditEventRow      $row
     * @param array<int, string> $hospitalNames
     */
    private function reminder(array $row, array $hospitalNames): RecentNotificationEventDto
    {
        $hospitalId = $this->intOrNull($row['intentMetadata']['hospital_id'] ?? null);
        $period = $row['intentMetadata']['reporting_period'] ?? null;
        $reportingPeriod = \is_string($period) ? \DateTimeImmutable::createFromFormat('!Y-m', $period) : false;

        return new RecentNotificationEventDto(
            intent: $row['intent'],
            occurredAt: $row['occurredAt'],
            requestId: $row['requestId'],
            subject: null === $hospitalId ? null : ($hospitalNames[$hospitalId] ?? null),
            subjectId: null === $hospitalId ? null : (string) $hospitalId,
            reportingPeriod: false === $reportingPeriod ? null : $reportingPeriod,
        );
    }

    /**
     * Runs of the lifecycle and the older message handler paths both store `import_id`; the audited entity of the
     * failure is the import itself.
     *
     * @param AuditEventRow $row
     */
    private function importId(array $row): ?int
    {
        return $this->intOrNull($row['intentMetadata']['import_id'] ?? null)
            ?? (Import::class === $row['entityClass'] ? $this->intOrNull($row['entityId']) : null);
    }

    /**
     * A registration writes the new user; the intent carries no metadata.
     *
     * @param AuditEventRow $row
     */
    private function userId(array $row): ?int
    {
        return User::class === $row['entityClass'] ? $this->intOrNull($row['entityId']) : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param list<?int> $ids
     *
     * @return array<int, Import>
     */
    private function importsById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => null !== $id)));
        if ([] === $ids) {
            return [];
        }

        /** @var list<Import> $result */
        $result = $this->importRepository->createQueryBuilder('i')
            ->addSelect('h')
            ->leftJoin('i.hospital', 'h')
            ->andWhere('i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $imports = [];
        foreach ($result as $import) {
            $imports[(int) $import->getId()] = $import;
        }

        return $imports;
    }

    /**
     * @param list<?int> $ids
     *
     * @return array<int, string>
     */
    private function usernamesById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => null !== $id)));
        if ([] === $ids) {
            return [];
        }

        $usernames = [];
        foreach ($this->userRepository->findBy(['id' => $ids]) as $user) {
            $usernames[(int) $user->getId()] = (string) $user->getUsername();
        }

        return $usernames;
    }

    /**
     * @param list<?int> $ids
     *
     * @return array<int, string>
     */
    private function hospitalNamesById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => null !== $id)));
        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach ($this->hospitalRepository->findBy(['id' => $ids]) as $hospital) {
            $names[(int) $hospital->getId()] = (string) $hospital->getName();
        }

        return $names;
    }
}
