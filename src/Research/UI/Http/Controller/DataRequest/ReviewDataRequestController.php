<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Research\Infrastructure\Security\Voter\DataRequestVoter;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The Board's and administrators' steps on a data extract request (ADR 045): formal check (open the vote or return
 * the request), voting, exclusions for conflicts of interest, resolving a veto. Each step needs its voter attribute;
 * the service checks the rules again.
 */
#[IsGranted('ROLE_USER')]
final class ReviewDataRequestController extends AbstractController
{
    public const string CSRF_PREFIX = 'data_request_review_';

    private const array ATTRIBUTES = [
        'open' => DataRequestVoter::CHECK,
        'return' => DataRequestVoter::CHECK,
        'vote' => DataRequestVoter::VOTE,
        'resolve' => DataRequestVoter::RESOLVE,
    ];

    public function __construct(
        private readonly DataRequestRepository $requests,
        private readonly DataRequestReviewService $review,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/requests/data/{publicId}/review/{action}', name: 'app_data_requests_review', requirements: ['publicId' => Requirement::UUID, 'action' => 'open|return|vote|exclude|resolve'], methods: ['POST'])]
    public function __invoke(Request $request, string $publicId, string $action): RedirectResponse
    {
        $user = $this->getUser();
        $dataRequest = $this->requests->findOneByPublicId($publicId);
        if (!$user instanceof User || !$dataRequest instanceof DataRequest || !$this->isGranted(DataRequestVoter::VIEW, $dataRequest)) {
            throw $this->createNotFoundException();
        }

        $payload = $request->getPayload();
        $member = 'exclude' === $action ? $this->users->find($payload->getInt('member')) : null;
        if (!$this->isGranted($this->attributeFor($action, $user, $member), $dataRequest)) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_PREFIX.$action.'_'.$publicId, $payload->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $message = $payload->getString('message');
        try {
            match ($action) {
                'open' => $this->review->openRound($user, $dataRequest),
                'return' => $this->review->returnRequest($user, $dataRequest, $message),
                'vote' => $this->review->castVote($user, $dataRequest, $this->choice($payload->getString('choice')), $message),
                'exclude' => $this->review->declareExclusion($user, $dataRequest, $member instanceof User ? $member : $user, $message),
                'resolve' => $this->review->resolveStall($user, $dataRequest, $payload->getString('resolution'), $message),
                default => throw $this->createNotFoundException(),
            };
            $this->addFlash('success', new TranslatableMessage('data_request.flash.review.'.$action, domain: 'research'));
        } catch (DataRequestRefused $refused) {
            $this->addFlash('error', new TranslatableMessage($refused->reason, domain: 'research'));
        }

        return $this->redirectToRoute('app_data_requests_show', ['publicId' => $publicId]);
    }

    /**
     * @throws DataRequestRefused
     */
    private function choice(string $value): VoteChoice
    {
        return VoteChoice::tryFrom($value) ?? throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
    }

    /**
     * Declaring one's own conflict of interest needs EXCLUDE_SELF, excluding someone else RECORD_EXCLUSION.
     */
    private function attributeFor(string $action, User $user, ?User $member): string
    {
        if ('exclude' !== $action) {
            return self::ATTRIBUTES[$action] ?? DataRequestVoter::RESOLVE;
        }

        return $member instanceof User && $member->getId() !== $user->getId() ? DataRequestVoter::RECORD_EXCLUSION : DataRequestVoter::EXCLUDE_SELF;
    }
}
