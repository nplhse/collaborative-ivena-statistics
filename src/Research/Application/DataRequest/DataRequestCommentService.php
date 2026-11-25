<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestComment;
use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Comments on a data extract request (ADR 045).
 *
 * - The applicant, Board members and administrators comment on a submitted request; every participant who can see the
 *   request reads the public comments.
 * - Private comments are for Board members only, without the applicant and without members excluded for a conflict of
 *   interest. A reply to a private comment is always private; a public comment may get a private reply.
 * - The visibility is fixed when a comment is posted; comments are not edited.
 */
final readonly class DataRequestCommentService
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private ClockInterface $clock,
    ) {
    }

    public function canComment(User $user, DataRequest $request): bool
    {
        return $request->wasSubmitted()
            && ($request->isRequestedBy($user) || $this->has($user, UserRole::BOARD_MEMBER) || $this->has($user, UserRole::ADMIN));
    }

    /** Writes and reads private comments. */
    public function canSeePrivate(User $user, DataRequest $request): bool
    {
        return $this->has($user, UserRole::BOARD_MEMBER) && !$request->isRequestedBy($user) && !$request->isExcluded($user);
    }

    /**
     * @throws DataRequestRefused
     */
    public function post(User $author, DataRequest $request, string $body, bool $private, ?int $parentId): void
    {
        if (!$this->canComment($author, $request)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $parent = null;
        if (null !== $parentId) {
            $parent = $request->findComment($parentId);
            if (!$parent instanceof DataRequestComment || ($parent->isPrivate() && !$this->canSeePrivate($author, $request))) {
                throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
            }

            // A reply to a private comment stays private, whatever the form sent.
            $private = $private || $parent->isPrivate();
        }

        if ($private && !$this->canSeePrivate($author, $request)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        try {
            $request->addComment($author, $body, $private, $parent, $this->clock->now());
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::COMMENT_INVALID, $exception);
        }

        $this->auditContext->beginIntent($private ? 'research.data_request.commented_privately' : 'research.data_request.commented', ['data_request_id' => $request->getId()]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }

    private function has(User $user, string $role): bool
    {
        return \in_array($role, $user->getRoles(), true);
    }
}
