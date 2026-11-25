<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestComment;
use App\Research\Domain\Enum\Visibility;
use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Who reads and writes which entries of a data extract request's timeline (ADR 045).
 *
 * - Administrators read and write every level.
 * - Board members who are neither the applicant nor excluded read and write every level.
 * - The applicant reads and writes private and public entries.
 * - Everyone else who may see the request reads public entries and writes nothing.
 *
 * A reply never reaches more readers than the comment it answers; a broader choice is narrowed to the parent's level.
 * The visibility is fixed once a comment is posted; comments are not edited.
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

    /** @return list<Visibility> */
    public function readableLevels(User $user, DataRequest $request): array
    {
        if ($this->isAdmin($user) || $this->isActiveBoardMember($user, $request)) {
            return Visibility::cases();
        }

        return $request->isRequestedBy($user) ? [Visibility::Private, Visibility::Public] : [Visibility::Public];
    }

    /** @return list<Visibility> */
    public function writableLevels(User $user, DataRequest $request): array
    {
        if (!$request->wasSubmitted()) {
            return [];
        }

        if ($this->isAdmin($user) || $this->isActiveBoardMember($user, $request)) {
            return Visibility::cases();
        }

        return $request->isRequestedBy($user) ? [Visibility::Private, Visibility::Public] : [];
    }

    public function canComment(User $user, DataRequest $request): bool
    {
        return [] !== $this->writableLevels($user, $request);
    }

    /**
     * @throws DataRequestRefused
     */
    public function post(User $author, DataRequest $request, string $body, Visibility $visibility, ?int $parentId): void
    {
        if (!\in_array($visibility, $this->writableLevels($author, $request), true)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $parent = null;
        if (null !== $parentId) {
            $parent = $request->findComment($parentId);
            if (!$parent instanceof DataRequestComment || !\in_array($parent->getVisibility(), $this->readableLevels($author, $request), true)) {
                throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
            }

            if ($parent->getVisibility()->isNarrowerThan($visibility)) {
                $visibility = $parent->getVisibility();
            }
        }

        try {
            $request->addComment($author, $body, $visibility, $parent, $this->clock->now());
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::COMMENT_INVALID, $exception);
        }

        $this->auditContext->beginIntent('research.data_request.commented', ['data_request_id' => $request->getId(), 'visibility' => $visibility->value]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }

    private function isActiveBoardMember(User $user, DataRequest $request): bool
    {
        return \in_array(UserRole::BOARD_MEMBER, $user->getRoles(), true) && !$request->isRequestedBy($user) && !$request->isExcluded($user);
    }

    private function isAdmin(User $user): bool
    {
        return \in_array(UserRole::ADMIN, $user->getRoles(), true);
    }
}
