<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Domain\Enum\AssignmentDecisionAction;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * History of the manual decisions on assignment conflicts (ADR 037, #1307): who assigned a record to a
 * candidate or left it unassigned for now, when, and which conflict they saw. Rows are never changed.
 *
 * Belongs to the import of the record, like the record and its pending assignment (ADR 029): import
 * deletion cascades, and the encounter import remover deletes the rows explicitly, because reprocessing
 * keeps the import row. The chosen encounter is kept as a plain id without foreign key, so that history
 * never holds an encounter that no record references any more.
 *
 * Written and read with SQL (AssignmentDecisions, AssignmentConflictQuery), so the class has no accessors.
 *
 * @psalm-suppress UnusedClass
 * @psalm-suppress UnusedProperty
 */
#[ORM\Entity]
#[ORM\Table(name: 'encounter_assignment_decision')]
#[ORM\Index(name: 'idx_encounter_assignment_decision_record', fields: ['schema', 'recordId'])]
class EncounterAssignmentDecision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<array{encounter_id: int, reason: string}> $seenCandidates
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Hospital $hospital,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Import $import,
        #[ORM\Column(name: 'exchange_schema', length: 32)]
        private string $schema,
        #[ORM\Column(length: 64)]
        private string $recordId,
        #[ORM\Column(length: 16, enumType: AssignmentDecisionAction::class)]
        private AssignmentDecisionAction $action,
        #[ORM\Column(length: 16, enumType: AssignmentKind::class)]
        private AssignmentKind $seenKind,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $seenCandidates,
        #[ORM\Column(nullable: true)]
        private ?int $encounterId,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $decidedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $decidedAt,
    ) {
    }
}
