<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Contract\EncounterOverviewReaderInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\UI\DataPrep\DataPrepRelease;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Entry page of the encounter data pilot: the user's own encounter grants, links to the descriptive
 * overview of every hospital the user may see aggregates of (#1055; administrators: every hospital with
 * stored visits) and the data preparation tool.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class EncounterOverviewController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly DataPrepRelease $dataPrepRelease,
        private readonly EncounterOverviewReaderInterface $overviewReader,
    ) {
    }

    #[Route('/encounter', name: 'app_encounter_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        $grants = $user instanceof User ? $this->grantRepository->findForUser($user) : [];

        return $this->render('@Encounter/index.html.twig', [
            'grants' => $grants,
            'overview_hospitals' => $this->overviewHospitals($grants),
            'data_prep' => $this->dataPrepRelease,
        ]);
    }

    /**
     * @param list<\App\Encounter\Domain\Entity\EncounterAccessGrant> $grants
     *
     * @return array<int, string> hospital id => name
     */
    private function overviewHospitals(array $grants): array
    {
        if ($this->isGranted(UserRole::ADMIN)) {
            return $this->overviewReader->hospitalsWithVisits();
        }

        $hospitals = [];
        foreach ($grants as $grant) {
            $hospital = $grant->getHospital();
            $hospitalId = $hospital->getId();
            if (null !== $hospitalId && $this->isGranted(EncounterAttribute::VIEW_AGGREGATES, $hospital)) {
                $hospitals[$hospitalId] = (string) $hospital->getName();
            }
        }

        asort($hospitals);

        return $hospitals;
    }
}
