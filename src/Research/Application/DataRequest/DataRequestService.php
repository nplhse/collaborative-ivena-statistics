<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\Visibility;
use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Steps of the applicant on a data extract request (ADR 045): saving a draft, changing it, submitting and
 * withdrawing. Every step checks that the request is the applicant's own and that the state machine allows it; the
 * voter decides what the pages offer.
 */
final readonly class DataRequestService
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private ClockInterface $clock,
        #[Target('data_request.state_machine')]
        private WorkflowInterface $stateMachine,
        private DataRequestReviewService $review,
    ) {
    }

    public function createDraft(User $applicant, DataRequestDraft $draft): DataRequest
    {
        $request = new DataRequest($applicant, $draft->toContent(), $this->clock->now());
        $this->entityManager->persist($request);
        $this->flush('research.data_request.drafted', $request);

        return $request;
    }

    /**
     * @throws DataRequestRefused
     */
    public function updateDraft(User $applicant, DataRequest $request, DataRequestDraft $draft): void
    {
        $this->assertOwn($applicant, $request);
        if (!$request->getStatus()->isEditable()) {
            throw new DataRequestRefused(DataRequestRefused::NOT_EDITABLE);
        }

        try {
            if ($request->getCurrentVersion()->isSubmitted()) {
                // Returned by the formal check or sent back for a revision: the change is the next version.
                $request->addVersion($draft->toContent(), $applicant, $this->clock->now());
            } else {
                $request->updateCurrentVersion($draft->toContent(), $this->clock->now());
            }
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::NOT_EDITABLE, $exception);
        }

        $this->flush('research.data_request.updated', $request);
    }

    /**
     * @throws DataRequestRefused
     */
    public function submit(User $applicant, DataRequest $request): void
    {
        $this->assertOwn($applicant, $request);
        if ($request->getCurrentVersion()->isSubmitted()) {
            throw new DataRequestRefused(DataRequestRefused::NEW_VERSION_NEEDED);
        }

        $transition = self::submitTransition($request->getStatus());
        if (null === $transition) {
            throw new DataRequestRefused(DataRequestRefused::TRANSITION_BLOCKED);
        }

        $request->recordSubmission($this->clock->now());
        $request->handBack(null);
        $request->recordEvent(TimelineEventType::Submitted, $applicant, Visibility::Public, $this->clock->now(), ['version' => $request->getCurrentVersion()->getNumber()]);
        if ('submit_revision' === $transition) {
            // A submitted revision goes straight into a new round, without a formal check.
            $this->review->openRoundAfterRevision($request);
        } else {
            $this->apply($request, $transition);
        }

        $this->flush('research.data_request.submitted', $request);
    }

    /** The transition that submits the current version: first submission, after a return, or a revision. */
    public static function submitTransition(DataRequestStatus $status): ?string
    {
        return match ($status) {
            DataRequestStatus::Draft => 'submit',
            DataRequestStatus::Returned => 'resubmit',
            DataRequestStatus::RevisionRequested => 'submit_revision',
            default => null,
        };
    }

    /**
     * Ends the request on the applicant's wish and a running vote with it. Later steps (#1635) also revoke open
     * releases.
     *
     * @throws DataRequestRefused
     */
    public function withdraw(User $applicant, DataRequest $request): void
    {
        $this->assertOwn($applicant, $request);
        $this->apply($request, 'withdraw');
        $this->review->cancelOpenRound($request);
        $request->recordClosing($this->clock->now());
        if ($request->wasSubmitted()) {
            $request->recordEvent(TimelineEventType::Withdrawn, $applicant, Visibility::Public, $this->clock->now());
        }
        $this->flush('research.data_request.withdrawn', $request);
    }

    /**
     * @throws DataRequestRefused
     */
    private function assertOwn(User $applicant, DataRequest $request): void
    {
        if (!$request->isRequestedBy($applicant)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_OWN);
        }
    }

    /**
     * @throws DataRequestRefused
     */
    private function apply(DataRequest $request, string $transition): void
    {
        if (!$this->stateMachine->can($request, $transition)) {
            throw new DataRequestRefused(DataRequestRefused::TRANSITION_BLOCKED);
        }

        $this->stateMachine->apply($request, $transition);
    }

    /**
     * @throws DataRequestRefused when someone else changed the request in the meantime
     */
    private function flush(string $intent, DataRequest $request): void
    {
        $this->auditContext->beginIntent($intent, ['data_request_id' => $request->getId()]);
        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException $exception) {
            throw new DataRequestRefused(DataRequestRefused::CHANGED_MEANWHILE, $exception);
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
