<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Plausibility;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Contract\PzcNameReaderInterface;
use App\Encounter\Application\Plausibility\PlausibilityLearningReport;
use App\Encounter\Application\Plausibility\PlausibilityReviewer;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Review of one plausibility snapshot before it is activated (ADR 033): per problem area the points and source of its
 * cells and the change against the active snapshot; per PZC × diagram the changed bonus. No counts per cell (Privacy).
 *
 * A learned snapshot (#1155) also shows its learning period and parameters and the replay figures of its report, only
 * for the hospitals on which the viewer holds the encounter grant `REVIEW` (EncounterVoter); administrators see every
 * hospital and the sum over all of them. A reviewer without a review grant sees no replay figures.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
#[IsGranted('ROLE_ENCOUNTER_CATALOG_REVIEWER')]
final class ShowPlausibilitySnapshotController extends AbstractController
{
    public function __construct(
        private readonly PlausibilityReviewer $reviewer,
        private readonly PlausibilityRepositoryInterface $repository,
        private readonly PzcNameReaderInterface $pzcNames,
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly MatchReviewReaderInterface $review,
    ) {
    }

    #[Route('/tools/encounter-plausibility/snapshots/{id}', name: 'app_tools_encounter_plausibility_snapshot', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(PlausibilitySnapshot $snapshot, #[CurrentUser] User $user): Response
    {
        /** @var array<string, PlausibilityProblemArea> $areaNames */
        $areaNames = [];
        foreach ($this->repository->areas() as $area) {
            $areaNames[$area->getCode()] = $area;
        }

        return $this->render('@Encounter/plausibility/snapshot.html.twig', [
            'review' => $this->reviewer->review($snapshot),
            'areas' => $areaNames,
            'pzc_names' => $this->pzcNames->names(),
            'learning' => $this->learning($snapshot, $user),
        ]);
    }

    /**
     * The report's figures the viewer may see: per reviewed hospital (by name) and, for administrators, the sum.
     *
     * @return array{report: PlausibilityLearningReport, hospitals: array<int, string>, total: ?array<string, mixed>}|null
     */
    private function learning(PlausibilitySnapshot $snapshot, User $user): ?array
    {
        $report = PlausibilityLearningReport::fromArray($snapshot->getReport());
        if (!$report instanceof PlausibilityLearningReport) {
            return null;
        }

        /** @var array<int, string> $names */
        $names = [];
        $admin = $this->isGranted('ROLE_ADMIN');
        if ($admin) {
            foreach ($this->review->hospitalsWithEncounters() as $hospital) {
                $names[$hospital['id']] = $hospital['name'];
            }
        } else {
            foreach ($this->grantRepository->findForUser($user) as $grant) {
                $hospital = $grant->getHospital();
                if ($grant->allows(EncounterGrantAction::Review) && $this->isGranted(EncounterAttribute::REVIEW, $hospital)) {
                    $names[(int) $hospital->getId()] = (string) $hospital->getName();
                }
            }
        }

        $visible = $admin ? $report : $report->forHospitals(array_keys($names));
        $hospitals = [];
        foreach (array_keys($visible->hospitals) as $hospitalId) {
            $hospitals[$hospitalId] = $names[$hospitalId] ?? '#'.$hospitalId;
        }

        asort($hospitals);

        return ['report' => $visible, 'hospitals' => $hospitals, 'total' => $admin ? $visible->total() : null];
    }
}
