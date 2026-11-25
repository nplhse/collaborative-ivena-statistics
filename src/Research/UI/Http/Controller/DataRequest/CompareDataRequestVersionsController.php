<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\VersionComparison;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestVersion;
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

        $versions = array_values(array_filter(
            $dataRequest->getVersions(),
            static fn (DataRequestVersion $version): bool => $version->isSubmitted() || $dataRequest->isRequestedBy($user),
        ));
        $byNumber = [];
        foreach ($versions as $version) {
            $byNumber[$version->getNumber()] = $version;
        }

        $to = $byNumber[$request->query->getInt('to')] ?? ([] !== $versions ? $versions[\count($versions) - 1] : null);
        $from = $byNumber[$request->query->getInt('from')] ?? ($to instanceof DataRequestVersion ? ($byNumber[$to->getNumber() - 1] ?? null) : null);

        $changes = $from instanceof DataRequestVersion && $to instanceof DataRequestVersion && $from !== $to
            ? VersionComparison::compare($from, $to, $this->isGranted(DataRequestVoter::VIEW_INTERNAL, $dataRequest))
            : null;

        return $this->render('@Research/data_request/compare.html.twig', [
            'dataRequest' => $dataRequest,
            'version' => $dataRequest->getCurrentVersion(),
            'versions' => $versions,
            'from' => $from,
            'to' => $to,
            'changes' => $changes,
        ]);
    }
}
