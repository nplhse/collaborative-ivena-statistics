<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\DataPrep;

use App\Encounter\Application\Contract\DepartmentCatalogInterface;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\UI\DataPrep\LocalMapFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Local map page of the data preparation tool (#1130): format, allowed values, example, template
 * download and the department names the import resolves. The hospital's own codes are entered in the
 * tool, never here (ADR 026).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowDataPrepLocalMapController extends AbstractController
{
    public function __construct(
        private readonly DepartmentCatalogInterface $departmentCatalog,
        private readonly LocalMapFiles $localMapFiles,
    ) {
    }

    #[Route('/encounter/data-prep/local-map', name: 'app_encounter_data_prep_local_map_help', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Encounter/data_prep/local_map.html.twig', [
            'department_names' => $this->departmentCatalog->uniqueNames(),
            'care_levels' => CareLevel::cases(),
            'dispositions' => Disposition::cases(),
            'care_level_dispositions' => [Disposition::Admitted, Disposition::TransferredExternal],
            'local_map_example' => $this->localMapFiles->content(LocalMapFiles::EXAMPLE),
        ]);
    }
}
