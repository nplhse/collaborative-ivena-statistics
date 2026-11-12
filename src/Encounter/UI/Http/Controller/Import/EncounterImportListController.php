<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterImportReaderInterface;
use App\Encounter\Application\Contract\EncounterUploadHospitalsInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Encounter imports of the hospitals the user may upload for (ADR 027: UPLOAD or ROLE_ADMIN).
 */
#[IsGranted(UserRole::ENCOUNTER_BETA)]
final class EncounterImportListController extends AbstractController
{
    public function __construct(
        private readonly EncounterUploadHospitalsInterface $uploadHospitals,
        private readonly EncounterImportReaderInterface $encounterImports,
    ) {
    }

    #[Route('/encounter/imports', name: 'app_encounter_imports', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $hospitals = array_values(array_filter(
            $this->uploadHospitals->forUser($user, $this->isGranted(UserRole::ADMIN)),
            fn (Hospital $hospital): bool => $this->isGranted(EncounterAttribute::UPLOAD, $hospital),
        ));

        return $this->render('@Encounter/imports/index.html.twig', [
            'imports' => $this->encounterImports->findForHospitals($hospitals),
            'can_upload' => [] !== $hospitals,
        ]);
    }
}
