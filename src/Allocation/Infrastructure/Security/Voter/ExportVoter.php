<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Security\Voter;

use App\Allocation\Application\Export\ExportAccessService;
use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, null>
 */
final class ExportVoter extends Voter
{
    public const string EXPORT = 'EXPORT';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly ExportAccessService $exportAccessService,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EXPORT === $attribute && null === $subject;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $this->exportAccessService->canExport($user);
    }
}
