<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Allocation\Application\Allocations\AllocationListHospitalScopeResolver;
use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Infrastructure\Repository\HospitalAssignmentRequestRepository;
use App\Import\Application\Service\ImportUploadTargets;
use App\Import\Domain\Enum\ImportType;
use App\Onboarding\Application\Dto\ClinicAccessState;
use App\Onboarding\Application\Dto\OnboardingStepView;
use App\Onboarding\Domain\Enum\ClinicAccessStatus;
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
        private ImportUploadTargets $uploadTargets,
        private UrlGeneratorInterface $urlGenerator,
        private HospitalAssignmentRequestRepository $assignmentRequests,
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
        $hasImportPermission = $this->hasImportPermission($user);
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
            $isCompleted = $isAutoCompleted || $this->isPersistedCompleted($stepKey, $persistedCompleted);

            if (OnboardingStepKey::RequestClinicAccess === $stepKey && $isActionable) {
                $steps[] = $this->buildRequestClinicAccessStep($this->resolveRequestState($user));

                continue;
            }

            $steps[] = new OnboardingStepView(
                key: $stepKey,
                position: $stepKey->position(),
                title: new TranslatableMessage($this->titleKey($stepKey), domain: 'onboarding'),
                description: new TranslatableMessage($this->descriptionKey($stepKey), domain: 'onboarding'),
                isCompleted: $isCompleted,
                isAutoCompleted: $isAutoCompleted,
                isActionable: $isActionable,
                actionUrl: $isActionable ? $this->resolveActionUrl($stepKey, $ownsHospitals) : null,
                actionType: 'link',
            );
        }

        return $steps;
    }

    /**
     * Clinic access of a participant: owner or access grant with VIEW, otherwise the state of the current hospital
     * assignment request (#1483).
     */
    public function resolveClinicAccessState(User $user): ClinicAccessState
    {
        if ($this->hasClinicAccess($user)) {
            return new ClinicAccessState(ClinicAccessStatus::Granted);
        }

        return $this->resolveRequestState($user);
    }

    public function hasClinicAccess(User $user): bool
    {
        return [] !== $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission(
            $user,
            HospitalPermission::View,
        );
    }

    public function hasExportPermission(User $user): bool
    {
        return [] !== $this->hospitalPermissionAccess->resolveHospitalIdsWithPermission(
            $user,
            HospitalPermission::Export,
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
        // "Start your first import" leads to an allocation import (#1351).
        return $this->uploadTargets->canUpload($user, ImportType::ALLOCATION);
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

            if (!$this->isAutoCompleted($priorStepKey, $hasClinicAccess) && !$this->isPersistedCompleted($priorStepKey, $persistedCompleted)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Requesting clinic access completes only through access (#1483). A stored completion from before is ignored,
     * otherwise a participant without access would see only locked steps.
     *
     * @param array<string, bool> $persistedCompleted keyed by step value
     */
    private function isPersistedCompleted(OnboardingStepKey $stepKey, array $persistedCompleted): bool
    {
        if (OnboardingStepKey::RequestClinicAccess === $stepKey) {
            return false;
        }

        return $persistedCompleted[$stepKey->value] ?? false;
    }

    private function resolveRequestState(User $user): ClinicAccessState
    {
        $request = $this->assignmentRequests->findCurrentForRequester($user);
        if (!$request instanceof HospitalAssignmentRequest) {
            return new ClinicAccessState(ClinicAccessStatus::NoRequest);
        }

        $status = match ($request->getStatus()) {
            HospitalAssignmentRequestStatus::Pending => ClinicAccessStatus::Pending,
            HospitalAssignmentRequestStatus::Rejected => ClinicAccessStatus::Rejected,
            // Withdrawn, or approved and the access was removed later: the participant asks again.
            HospitalAssignmentRequestStatus::Withdrawn, HospitalAssignmentRequestStatus::Approved => ClinicAccessStatus::NoRequest,
        };

        if (ClinicAccessStatus::NoRequest === $status) {
            return new ClinicAccessState($status);
        }

        return new ClinicAccessState(
            $status,
            $request->getHospital()?->getName() ?? $request->getRequestedHospitalName(),
            $this->urlGenerator->generate('app_hospital_assignment_request_index', [
                '_fragment' => 'request-'.$request->getPublicIdString(),
            ]),
        );
    }

    private function buildRequestClinicAccessStep(ClinicAccessState $clinicAccess): OnboardingStepView
    {
        $stepKey = OnboardingStepKey::RequestClinicAccess;
        $formUrl = $this->urlGenerator->generate('app_hospital_assignment_request_new');
        $hospitalParameters = ['hospital' => $clinicAccess->hospitalLabel ?? ''];

        [$description, $actionUrl, $actionLabel, $secondaryActionUrl, $secondaryActionLabel] = match ($clinicAccess->status) {
            ClinicAccessStatus::Pending => [
                new TranslatableMessage('onboarding.steps.request_clinic_access.pending.description', $hospitalParameters, 'onboarding'),
                $clinicAccess->requestUrl,
                new TranslatableMessage('onboarding.steps.request_clinic_access.pending.action', domain: 'onboarding'),
                null,
                null,
            ],
            ClinicAccessStatus::Rejected => [
                new TranslatableMessage('onboarding.steps.request_clinic_access.rejected.description', $hospitalParameters, 'onboarding'),
                $clinicAccess->requestUrl,
                new TranslatableMessage('onboarding.steps.request_clinic_access.rejected.action', domain: 'onboarding'),
                $formUrl,
                new TranslatableMessage('onboarding.steps.request_clinic_access.rejected.retry', domain: 'onboarding'),
            ],
            ClinicAccessStatus::NoRequest, ClinicAccessStatus::Granted => [
                new TranslatableMessage($this->descriptionKey($stepKey), domain: 'onboarding'),
                $formUrl,
                null,
                null,
                null,
            ],
        };

        return new OnboardingStepView(
            key: $stepKey,
            position: $stepKey->position(),
            title: new TranslatableMessage($this->titleKey($stepKey), domain: 'onboarding'),
            description: $description,
            isCompleted: false,
            isAutoCompleted: false,
            isActionable: true,
            actionUrl: $actionUrl,
            actionType: 'link',
            actionLabel: $actionLabel,
            secondaryActionUrl: $secondaryActionUrl,
            secondaryActionLabel: $secondaryActionLabel,
            canMarkComplete: false,
            clinicAccess: $clinicAccess,
        );
    }

    private function isAutoCompleted(OnboardingStepKey $stepKey, bool $hasClinicAccess): bool
    {
        return OnboardingStepKey::RequestClinicAccess === $stepKey && $hasClinicAccess;
    }

    private function resolveActionUrl(OnboardingStepKey $stepKey, bool $ownsHospitals): string
    {
        return match ($stepKey) {
            // Built with its request state in buildRequestClinicAccessStep() (#975, #1483).
            OnboardingStepKey::RequestClinicAccess => $this->urlGenerator->generate('app_hospital_assignment_request_new'),
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
