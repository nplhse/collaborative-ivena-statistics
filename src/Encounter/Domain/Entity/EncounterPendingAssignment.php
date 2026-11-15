<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Contract\Assignment\AssignmentCandidate;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Import\Domain\Entity\Import;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A record of an attaching schema that is kept without encounter (ADR 037): no candidate yet, or an
 * ambiguous or contradictory assignment conflict for review. Keeps the request (allocation, case
 * pseudonym, time) so that the record can be tried again (#1307), and the candidates with their reason.
 *
 * Belongs to the import of its record and goes with it (cascade on import deletion; the encounter
 * import remover also deletes it explicitly).
 *
 * Written through the ORM by EncounterAssignments and read with SQL (AssignmentConflictQuery), so the class
 * has no accessors until the decisions and retries (#1307) need them.
 *
 * @psalm-suppress UnusedProperty
 */
#[ORM\Entity]
#[ORM\Table(name: 'encounter_pending_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_pending_record', fields: ['schema', 'recordId'])]
#[ORM\Index(name: 'idx_encounter_pending_hospital_kind', fields: ['hospital', 'kind'])]
class EncounterPendingAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<array{encounter_id: int, reason: string}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $candidates;

    /**
     * @param list<AssignmentCandidate> $candidates
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Hospital $hospital,
        #[ORM\Column(type: Types::INTEGER)]
        private int $keyGeneration,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Import $import,
        #[ORM\Column(name: 'exchange_schema', length: 32)]
        private string $schema,
        #[ORM\Column(length: 64)]
        private string $recordId,
        #[ORM\Column(length: 16, enumType: AssignmentKind::class)]
        private AssignmentKind $kind,
        #[ORM\Column(nullable: true)]
        private ?int $allocationId,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $caseKey,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $requestedAt,
        array $candidates,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        if (AssignmentKind::Assigned === $kind) {
            throw new \InvalidArgumentException('An assigned record is not pending.');
        }

        $this->candidates = array_map(
            static fn (AssignmentCandidate $candidate): array => ['encounter_id' => $candidate->encounterId, 'reason' => $candidate->reason->value],
            $candidates,
        );
    }
}
