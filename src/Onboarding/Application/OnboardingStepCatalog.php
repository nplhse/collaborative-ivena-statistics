<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Allocation\Application\Allocations\AllocationListHospitalScopeResolver;
use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Import\Application\Service\ImportListAccess;
use App\Onboarding\Application\Dto\OnboardingStepView;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class OnboardingStepCatalog
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private HospitalPermissionAccess $hospitalPermissionAccess,
        private ImportListAccess $importListAccess,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, bool> $persistedCompleted keyed by step value
     *
     * @return list<OnboardingStepView>
     */
    public function buildStepsForUser(User $user, array $persistedCompleted): array
    {
        if (!$this->isParticipant($user)) {
            return [];
        }

        $hasClinicAccess = $this->hasClinicAccess($user);
        $hasImportPermission = [] !== $this->importListAccess->resolveAccessibleHospitalIds($user);
        $hasStatisticsPermission = $this->hasStatisticsPermission($user);
        $ownsHospitals = $user->getHospitals()->count() > 0;

        $steps = [];

        foreach (OnboardingStepKey::orderedCases() as $stepKey) {
            $isActionable = $this->isStepAvailable(
                $stepKey,
                $hasClinicAccess,
                $hasImportPermission,
                $hasStatisticsPermission,
                $persistedCompleted,
            );
            $isAutoCompleted = $this->isAutoCompleted($stepKey, $hasClinicAccess);
            $isPersistedCompleted = $persistedCompleted[$stepKey->value] ?? false;
            $isCompleted = $isAutoCompleted || $isPersistedCompleted;

            $steps[] = new OnboardingStepView(
                key: $stepKey,
                position: $stepKey->position(),
                title: new TranslatableMessage($this->titleKey($stepKey), domain: 'onboarding'),
                description: new TranslatableMessage($this->descriptionKey($stepKey), domain: 'onboarding'),
                isCompleted: $isCompleted,
                isAutoCompleted: $isAutoCompleted,
                isActionable: $isActionable,
                actionUrl: $isActionable ? $this->resolveActionUrl($stepKey, $ownsHospitals) : null,
                actionType: $this->resolveActionType($stepKey),
                feedbackMessage: OnboardingStepKey::RequestClinicAccess === $stepKey
                    ? new TranslatableMessage('onboarding.steps.request_clinic_access.feedback_message', domain: 'onboarding')
                    : null,
            );
        }

        return $steps;
    }

    public function hasClinicAccess(User $user): bool
    {
        return [] !== $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission(
            $user,
            HospitalPermission::View,
        );
    }

    public function hasStatisticsPermission(User $user): bool
    {
        return [] !== $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission(
            $user,
            HospitalPermission::Statistics,
        );
    }

    public function hasImportPermission(User $user): bool
    {
        return [] !== $this->importListAccess->resolveAccessibleHospitalIds($user);
    }

    /**
     * @param array<string, bool> $persistedCompleted keyed by step value
     */
    public function isStepAvailableForUser(User $user, OnboardingStepKey $stepKey, array $persistedCompleted = []): bool
    {
        if (!$this->isParticipant($user)) {
            return false;
        }

        return $this->isStepAvailable(
            $stepKey,
            $this->hasClinicAccess($user),
            $this->hasImportPermission($user),
            $this->hasStatisticsPermission($user),
            $persistedCompleted,
        );
    }

    public function isAutoCompletedForUser(User $user, OnboardingStepKey $stepKey): bool
    {
        return $this->isAutoCompleted($stepKey, $this->hasClinicAccess($user));
    }

    private function isParticipant(User $user): bool
    {
        return \in_array(UserRole::PARTICIPANT, $user->getRoles(), true);
    }

    /**
     * @param array<string, bool> $persistedCompleted keyed by step value
     */
    private function isStepAvailable(
        OnboardingStepKey $stepKey,
        bool $hasClinicAccess,
        bool $hasImportPermission,
        bool $hasStatisticsPermission,
        array $persistedCompleted,
    ): bool {
        return match ($stepKey) {
            OnboardingStepKey::RequestClinicAccess => !$hasClinicAccess,
            default => $this->arePreviousStepsCompleted($stepKey, $persistedCompleted, $hasClinicAccess)
                && $this->meetsStepPermission(
                    $stepKey,
                    $hasClinicAccess,
                    $hasImportPermission,
                    $hasStatisticsPermission,
                ),
        };
    }

    private function meetsStepPermission(
        OnboardingStepKey $stepKey,
        bool $hasClinicAccess,
        bool $hasImportPermission,
        bool $hasStatisticsPermission,
    ): bool {
        return match ($stepKey) {
            OnboardingStepKey::VerifyOwnClinic => $hasClinicAccess,
            OnboardingStepKey::StartFirstImport => $hasImportPermission,
            OnboardingStepKey::ViewExploreData => true,
            OnboardingStepKey::ViewOverviewStatistics => $hasStatisticsPermission,
            OnboardingStepKey::RequestClinicAccess => false,
        };
    }

    /**
     * @param array<string, bool> $persistedCompleted keyed by step value
     */
    private function arePreviousStepsCompleted(
        OnboardingStepKey $stepKey,
        array $persistedCompleted,
        bool $hasClinicAccess,
    ): bool {
        foreach (OnboardingStepKey::orderedCases() as $priorStepKey) {
            if ($priorStepKey === $stepKey) {
                break;
            }

            $isPersistedCompleted = $persistedCompleted[$priorStepKey->value] ?? false;
            if (!$this->isAutoCompleted($priorStepKey, $hasClinicAccess) && !$isPersistedCompleted) {
                return false;
            }
        }

        return true;
    }

    private function isAutoCompleted(OnboardingStepKey $stepKey, bool $hasClinicAccess): bool
    {
        return OnboardingStepKey::RequestClinicAccess === $stepKey && $hasClinicAccess;
    }

    private function resolveActionType(OnboardingStepKey $stepKey): string
    {
        return match ($stepKey) {
            OnboardingStepKey::RequestClinicAccess => 'feedback',
            default => 'link',
        };
    }

    private function resolveActionUrl(OnboardingStepKey $stepKey, bool $ownsHospitals): ?string
    {
        return match ($stepKey) {
            OnboardingStepKey::RequestClinicAccess => null,
            OnboardingStepKey::VerifyOwnClinic => $ownsHospitals
                ? $this->urlGenerator->generate('app_hospitals_index')
                : $this->urlGenerator->generate('app_explore_hospital_list'),
            OnboardingStepKey::StartFirstImport => $this->urlGenerator->generate('app_import_new'),
            OnboardingStepKey::ViewExploreData => $this->urlGenerator->generate('app_explore_allocation_list', [
                'hospitalFilter' => AllocationListHospitalScopeResolver::SCOPE_MY_HOSPITALS,
            ]),
            OnboardingStepKey::ViewOverviewStatistics => $this->urlGenerator->generate('app_stats_dashboard'),
        };
    }

    private function titleKey(OnboardingStepKey $stepKey): string
    {
        return match ($stepKey) {
            OnboardingStepKey::RequestClinicAccess => 'onboarding.steps.request_clinic_access.title',
            OnboardingStepKey::VerifyOwnClinic => 'onboarding.steps.verify_own_clinic.title',
            OnboardingStepKey::StartFirstImport => 'onboarding.steps.start_first_import.title',
            OnboardingStepKey::ViewExploreData => 'onboarding.steps.view_explore_data.title',
            OnboardingStepKey::ViewOverviewStatistics => 'onboarding.steps.view_overview_statistics.title',
        };
    }

    private function descriptionKey(OnboardingStepKey $stepKey): string
    {
        return match ($stepKey) {
            OnboardingStepKey::RequestClinicAccess => 'onboarding.steps.request_clinic_access.description',
            OnboardingStepKey::VerifyOwnClinic => 'onboarding.steps.verify_own_clinic.description',
            OnboardingStepKey::StartFirstImport => 'onboarding.steps.start_first_import.description',
            OnboardingStepKey::ViewExploreData => 'onboarding.steps.view_explore_data.description',
            OnboardingStepKey::ViewOverviewStatistics => 'onboarding.steps.view_overview_statistics.description',
        };
    }
}
