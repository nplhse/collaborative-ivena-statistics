<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\DataRequestCommentService;
use App\Research\Application\DataRequest\DataRequestTimeline;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\Visibility;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Research\Infrastructure\Security\Voter\DataRequestVoter;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One data extract request (ADR 045). Participants see the visible fields and the export scope without recipients;
 * the applicant, the Board and administrators also see the internal details. A request the viewer may not see
 * answers 404, so drafts of others stay hidden.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class ShowDataRequestController extends AbstractController
{
    public function __construct(
        private readonly DataRequestRepository $requests,
        private readonly UserRepository $users,
        private readonly DataRequestCommentService $comments,
    ) {
    }

    #[Route('/requests/data/{publicId}', name: 'app_data_requests_show', requirements: ['publicId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $publicId): Response
    {
        $dataRequest = $this->requests->findOneByPublicId($publicId);
        if (!$dataRequest instanceof DataRequest || !$this->isGranted(DataRequestVoter::VIEW, $dataRequest)) {
            throw $this->createNotFoundException();
        }

        $readable = $this->readable($dataRequest);

        return $this->render('@Research/data_request/show.html.twig', [
            'dataRequest' => $dataRequest,
            'version' => $dataRequest->getCurrentVersion(),
            'canViewInternal' => $this->isGranted(DataRequestVoter::VIEW_INTERNAL, $dataRequest),
            'timeline' => DataRequestTimeline::build($dataRequest, $readable),
            'readableLevels' => $readable,
            'writableLevels' => $this->writable($dataRequest),
            'boardMembers' => $this->isGranted(DataRequestVoter::RECORD_EXCLUSION, $dataRequest) ? $this->excludableMembers($dataRequest) : [],
        ]);
    }

    /**
     * Board members an administrator may record an exclusion for: not the applicant, not excluded yet.
     *
     * @return list<User>
     */
    private function excludableMembers(DataRequest $dataRequest): array
    {
        return array_values(array_filter(
            $this->users->findEnabledVerifiedUsersWithRoles([UserRole::BOARD_MEMBER]),
            static fn (User $member): bool => !$dataRequest->isRequestedBy($member) && !$dataRequest->isExcluded($member),
        ));
    }

    /** @return list<Visibility> */
    private function readable(DataRequest $dataRequest): array
    {
        $user = $this->getUser();

        return $user instanceof User ? $this->comments->readableLevels($user, $dataRequest) : [Visibility::Public];
    }

    /** @return list<Visibility> */
    private function writable(DataRequest $dataRequest): array
    {
        $user = $this->getUser();

        return $user instanceof User ? $this->comments->writableLevels($user, $dataRequest) : [];
    }
}
