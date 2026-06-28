<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Export;

use App\Allocation\Application\Export\ExportHospitalFormOptionsProvider;
use App\Allocation\Application\Export\OwnHospitalAllocationsExporter;
use App\Allocation\Application\Export\OwnHospitalAllocationsExportFilterMapper;
use App\Allocation\Infrastructure\Security\Voter\ExportVoter;
use App\Allocation\UI\Form\OwnHospitalAllocationsExportType;
use App\Shared\Application\Export\ExportOrchestrator;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
#[IsGranted(ExportVoter::EXPORT)]
final class EstimateAllocationsExportController extends AbstractController
{
    public function __construct(
        private readonly ExportOrchestrator $exportOrchestrator,
        private readonly OwnHospitalAllocationsExportFilterMapper $filterMapper,
        private readonly ExportHospitalFormOptionsProvider $exportHospitalFormOptionsProvider,
    ) {
    }

    #[Route('/hospitals/export/allocations/estimate', name: 'app_hospitals_export_allocations_estimate', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(
            OwnHospitalAllocationsExportType::class,
            null,
            $this->exportHospitalFormOptionsProvider->formOptionsFor($user, $request->getLocale()),
        );
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('@Allocation/export/_export_estimate_result.html.twig', [
                'form' => $form,
                'estimate' => null,
                'error' => true,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $filter = $this->filterMapper->fromFormData($form->getData());
        $estimate = $this->exportOrchestrator->estimate(
            $user,
            OwnHospitalAllocationsExporter::KEY,
            $filter,
        );

        return $this->render('@Allocation/export/_export_estimate_result.html.twig', [
            'estimate' => $estimate,
            'error' => false,
        ]);
    }
}
