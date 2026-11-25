<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequest;
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
            $request->updateCurrentVersion($draft->toContent(), $this->clock->now());
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
        $this->apply($request, 'submit');
        $request->recordSubmission($this->clock->now());
        $this->flush('research.data_request.submitted', $request);
    }

    /**
     * Ends the request on the applicant's wish. Later steps (#1635) also end a running vote and revoke open releases.
     *
     * @throws DataRequestRefused
     */
    public function withdraw(User $applicant, DataRequest $request): void
    {
        $this->assertOwn($applicant, $request);
        $this->apply($request, 'withdraw');
        $request->recordClosing($this->clock->now());
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
