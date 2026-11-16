<?php

declare(strict_types=1);

namespace App\Encounter\Application\Assignment;

use App\Encounter\Contract\Assignment\AssignmentKind;

/**
 * A record a reviewer left unassigned for now (#1307), as listed read-only below the open conflicts: the
 * conflict they saw, who decided and when. The reviewer is null when their account was deleted.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the conflict page template.
 */
final readonly class DeferredAssignment
{
    /**
     * @param list<AssignmentConflictCandidate> $candidates the candidates the reviewer saw
     */
    public function __construct(
        public int $id,
        public string $schema,
        public string $recordId,
        public AssignmentKind $kind,
        public int $importId,
        public string $importName,
        public array $candidates,
        public ?string $decidedBy,
        public \DateTimeImmutable $decidedAt,
    ) {
    }
}
