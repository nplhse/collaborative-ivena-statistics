<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\UploadAuthority;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Application\Terms\TermsAcceptanceFactory;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The confirmation that a person may upload data for a hospital (#1565, key points A1/A2). Every upload needs it
 * for the hospital and the terms version in force (any version while `APP_TERMS_VERSION` is empty); administrators
 * upload as operators and need none. Stored as a `TermsAcceptance` of kind `upload_authority` (ADR 043).
 */
final readonly class UploadAuthority
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private TermsAcceptanceRepository $acceptances,
        private TermsAcceptanceFactory $acceptanceFactory,
        private TermsVersion $termsVersion,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function isRequired(User $user, Hospital $hospital): bool
    {
        return [] !== $this->hospitalIdsRequiringConfirmation($user, [(int) $hospital->getId()]);
    }

    /**
     * Of these hospitals, those the user still has to confirm before uploading.
     *
     * @param list<int> $hospitalIds
     *
     * @return list<int>
     */
    public function hospitalIdsRequiringConfirmation(User $user, array $hospitalIds): array
    {
        if ([] === $hospitalIds || \in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            return [];
        }

        $confirmed = $this->acceptances->hospitalIdsWithUploadAuthority($user, $hospitalIds, $this->termsVersion->current());

        return array_values(array_diff($hospitalIds, $confirmed));
    }

    /**
     * Persists the confirmation; the caller flushes it together with the upload. Pass time and version of an
     * earlier confirmation (an assignment request) to keep them.
     */
    public function confirm(User $user, Hospital $hospital, string $locale, ?\DateTimeImmutable $confirmedAt = null, ?string $termsVersion = null): void
    {
        $this->entityManager->persist($this->acceptanceFactory->forUploadAuthority($user, $hospital, $locale, $confirmedAt, $termsVersion));
    }
}
