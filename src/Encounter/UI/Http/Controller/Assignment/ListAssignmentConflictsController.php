<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Assignment;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\AssignmentConflictReaderInterface;
use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\Application\Security\EncounterAttribute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Open assignment conflicts of a hospital (ADR 037, #1274): records of attaching schemas whose case
 * pseudonym matches several encounters or contradicts their allocation link, with the candidates and the two
 * decisions (#1307, DecideAssignmentConflictController). Below, read only, the records left unassigned
 * for now with reviewer and time.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListAssignmentConflictsController extends AbstractController
{
    private const int LIST_LIMIT = 200;

    public function __construct(
        private readonly AssignmentConflictReaderInterface $conflicts,
        private readonly EncounterDimensionRegistry $dimensions,
    ) {
    }

    #[Route('/tools/encounter-assignments/{id}', name: 'app_tools_encounter_assignments_hospital', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);

        $hospitalId = (int) $hospital->getId();
        $conflicts = $this->conflicts->conflictsOf($hospitalId, self::LIST_LIMIT);
        $deferred = $this->conflicts->deferredOf($hospitalId, self::LIST_LIMIT);
        $labels = [];
        foreach ([...$conflicts, ...$deferred] as $record) {
            $labels[$record->schema] ??= $this->schemaLabel($record->schema);
        }

        return $this->render('@Encounter/assignment/conflicts.html.twig', [
            'hospital' => $hospital,
            'conflicts' => $conflicts,
            'total' => $this->conflicts->countOf($hospitalId),
            'deferred' => $deferred,
            'deferred_total' => $this->conflicts->countDeferredOf($hospitalId),
            'schema_labels' => $labels,
        ]);
    }

    private function schemaLabel(string $schema): TranslatableInterface
    {
        return $this->dimensions->has($schema)
            ? $this->dimensions->get($schema)->label()
            : new TranslatableMessage('encounter.import.schema.unknown', ['schema' => $schema], 'encounter');
    }
}
