<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\DepartmentCatalogInterface;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\UI\DataPrep\DataPrepChangelog;
use App\Encounter\UI\DataPrep\DataPrepRelease;
use App\Encounter\UI\DataPrep\DataPrepToolRenderer;
use App\Encounter\UI\DataPrep\LocalMapFiles;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Landing page of the offline data preparation tool (#1130): current version, changelog, getting
 * started from the grants to the first upload, the key and the local map. The hospital's own codes are
 * edited in the tool, never here (ADR 025/026); the page shows no case data.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowDataPrepGuideController extends AbstractController
{
    public function __construct(
        private readonly DataPrepRelease $release,
        private readonly DataPrepChangelog $changelog,
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly DepartmentCatalogInterface $departmentCatalog,
        private readonly LocalMapFiles $localMapFiles,
        private readonly DataPrepToolRenderer $toolRenderer,
    ) {
    }

    #[Route('/encounter/data-prep', name: 'app_encounter_data_prep', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Encounter/data_prep/guide.html.twig', [
            'release' => $this->release,
            'changelog' => $this->changelog->entries(),
            'profiles' => $this->toolRenderer->profileSummaries(),
            'hospitals' => $this->hospitals(),
            'department_names' => $this->departmentCatalog->uniqueNames(),
            'care_levels' => CareLevel::cases(),
            'dispositions' => Disposition::cases(),
            'care_level_dispositions' => [Disposition::Admitted, Disposition::TransferredExternal],
            'local_map_example' => $this->localMapFiles->content(LocalMapFiles::EXAMPLE),
        ]);
    }

    /**
     * The user's hospitals for the getting-started steps: those with an encounter grant and those the
     * user owns (an owner may hold no grant yet), with the user's own grant actions, ordered by name.
     *
     * @return list<array{hospital: Hospital, actions: list<EncounterGrantAction>}>
     */
    private function hospitals(): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $rows = [];
        foreach ($this->grantRepository->findForUser($user) as $grant) {
            $hospital = $grant->getHospital();
            $rows[(int) $hospital->getId()] = ['hospital' => $hospital, 'actions' => $grant->getActions()];
        }
        foreach ($user->getHospitals() as $hospital) {
            $rows[(int) $hospital->getId()] ??= ['hospital' => $hospital, 'actions' => []];
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['hospital']->getName(), (string) $b['hospital']->getName()));

        return $rows;
    }
}
