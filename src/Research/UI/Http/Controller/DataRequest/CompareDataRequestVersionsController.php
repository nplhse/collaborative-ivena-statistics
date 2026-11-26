<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\VersionComparison;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Research\Infrastructure\Security\Voter\DataRequestVoter;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Compares two versions of a data extract request (ADR 045), by default the latest with the one before. Only the
 * applicant sees a version that is not submitted yet; readers without the internal details compare the fields they
 * can see.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class CompareDataRequestVersionsController extends AbstractController
{
    public function __construct(
        private readonly DataRequestRepository $requests,
    ) {
    }

    #[Route('/requests/data/{publicId}/compare', name: 'app_data_requests_compare', requirements: ['publicId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(Request $request, string $publicId): Response
    {
        $user = $this->getUser();
        $dataRequest = $this->requests->findOneByPublicId($publicId);
        if (!$user instanceof User || !$dataRequest instanceof DataRequest || !$this->isGranted(DataRequestVoter::VIEW, $dataRequest)) {
            throw $this->createNotFoundException();
        }

        $comparison = VersionComparison::forReader($dataRequest, $user, $request->query->getInt('from'), $request->query->getInt('to'), $this->isGranted(DataRequestVoter::VIEW_INTERNAL, $dataRequest));

        return $this->render('@Research/data_request/compare.html.twig', [
            'dataRequest' => $dataRequest,
            'version' => $dataRequest->getCurrentVersion(),
            'comparison' => $comparison,
        ]);
    }
}
