<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Security\Voter;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\Service\ImportHospitalOverride;
use App\Import\Application\Service\ImportUploadTargets;
use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * May the user start a new import (#1351)?
 *
 * - `IMPORT_CREATE` without subject: at least one import type for at least one hospital
 *   (the upload form and every link to it).
 * - `IMPORT_CREATE` with a `Hospital`: at least one import type for this hospital.
 *
 * - `IMPORT_OPTION_HOSPITAL_OVERRIDE` (#1352): the target hospital override, only where
 *   {@see ImportHospitalOverride} makes it available (`dev`, `demo`), for everyone who may start an import
 *   (without subject) or upload to the given hospital. Form and controller ask this attribute; the
 *   asynchronous closure handler has no user and only checks the environment.
 *
 * Computed from the per-hospital grants by {@see ImportUploadTargets}; it adds no rights of its own.
 *
 * @extends Voter<string, Hospital|null>
 */
final class ImportCreateVoter extends Voter
{
    public const string CREATE = 'IMPORT_CREATE';

    public const string OPTION_HOSPITAL_OVERRIDE = 'IMPORT_OPTION_HOSPITAL_OVERRIDE';

    private const array ATTRIBUTES = [self::CREATE, self::OPTION_HOSPITAL_OVERRIDE];

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private readonly ImportUploadTargets $uploadTargets,
        private readonly ImportHospitalOverride $hospitalOverride,
    ) {
    }

    #[\Override]
    public function supportsAttribute(string $attribute): bool
    {
        return \in_array($attribute, self::ATTRIBUTES, true);
    }

    #[\Override]
    public function supportsType(string $subjectType): bool
    {
        return 'null' === $subjectType || is_a($subjectType, Hospital::class, true);
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTES, true) && (null === $subject || $subject instanceof Hospital);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (self::OPTION_HOSPITAL_OVERRIDE === $attribute && !$this->hospitalOverride->isAvailable()) {
            return false;
        }

        if (!$subject instanceof Hospital) {
            return $this->uploadTargets->canUpload($user);
        }

        $hospitalId = $subject->getId();

        return null !== $hospitalId && $this->uploadTargets->canUpload($user, null, $hospitalId);
    }
}
