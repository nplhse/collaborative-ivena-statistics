<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Export;

use App\Allocation\Application\Export\ExportHospitalFormOptionsProvider;
use App\Allocation\Infrastructure\Security\Voter\ExportVoter;
use App\Allocation\UI\Form\Model\OwnHospitalAllocationsExportFormData;
use App\Allocation\UI\Form\OwnHospitalAllocationsExportType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
#[IsGranted(ExportVoter::EXPORT)]
final class ShowAllocationsExportController extends AbstractController
{
    public function __construct(
        private readonly ExportHospitalFormOptionsProvider $exportHospitalFormOptionsProvider,
    ) {
    }

    #[Route('/hospitals/export/allocations', name: 'app_hospitals_export_allocations', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $defaultData = new OwnHospitalAllocationsExportFormData();
        $now = new \DateTimeImmutable('today');
        $defaultData->dateFrom = $now->modify('first day of this month');
        $defaultData->dateTo = $now;
        $defaultData->timeFrom = $now->setTime(0, 0, 0);
        $defaultData->timeTo = $now->setTime(23, 59, 59);

        $defaultData->hospitals = $this->exportHospitalFormOptionsProvider->defaultHospitalIdsFor($user);

        $form = $this->createForm(
            OwnHospitalAllocationsExportType::class,
            $defaultData,
            $this->exportHospitalFormOptionsProvider->formOptionsFor($user, $request->getLocale()),
        );

        return $this->render('@Allocation/export/allocations.html.twig', [
            'form' => $form,
        ]);
    }
}
