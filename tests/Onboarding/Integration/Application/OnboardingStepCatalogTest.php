<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Integration\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Onboarding\Application\OnboardingStepCatalog;
use App\Onboarding\Domain\Enum\ClinicAccessStatus;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class OnboardingStepCatalogTest extends DatabaseKernelTestCase
{
    private OnboardingStepCatalog $catalog;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->catalog = self::getContainer()->get(OnboardingStepCatalog::class);
    }

    public function testParticipantWithoutClinicSeesAllStepsWithMiddleOnesLocked(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $steps = $this->catalog->buildStepsForUser($user, []);

        self::assertCount(5, $steps);
        self::assertTrue($this->findStep($steps, OnboardingStepKey::RequestClinicAccess)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::VerifyOwnClinic)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::StartFirstImport)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::ViewExploreData)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::ViewOverviewStatistics)->isActionable);
    }

    public function testOwnerSeesVerifyAndImportStepsWithoutAccessRequest(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);

        $steps = $this->catalog->buildStepsForUser($user, []);

        self::assertTrue($this->findStep($steps, OnboardingStepKey::RequestClinicAccess)->isCompleted);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::RequestClinicAccess)->isActionable);
        self::assertTrue($this->findStep($steps, OnboardingStepKey::VerifyOwnClinic)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::StartFirstImport)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::ViewExploreData)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::ViewOverviewStatistics)->isActionable);
    }

    public function testImportStepUnlocksAfterVerifyClinicCompleted(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);

        $persisted = [
            OnboardingStepKey::VerifyOwnClinic->value => true,
        ];

        $steps = $this->catalog->buildStepsForUser($user, $persisted);

        self::assertTrue($this->findStep($steps, OnboardingStepKey::StartFirstImport)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::ViewExploreData)->isActionable);
    }

    public function testStatisticsStepUnlocksAfterExploreCompleted(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);

        $persisted = [
            OnboardingStepKey::VerifyOwnClinic->value => true,
            OnboardingStepKey::StartFirstImport->value => true,
            OnboardingStepKey::ViewExploreData->value => true,
        ];

        $steps = $this->catalog->buildStepsForUser($user, $persisted);

        self::assertTrue($this->findStep($steps, OnboardingStepKey::ViewOverviewStatistics)->isActionable);
    }

    public function testGranteeWithViewOnlySeesImportStepAsLocked(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $owner]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $owner, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $owner,
            'dispatchArea' => $dispatchArea,
            'owner' => $owner,
            'state' => $state,
        ]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        $steps = $this->catalog->buildStepsForUser($grantee, []);

        self::assertTrue($this->findStep($steps, OnboardingStepKey::VerifyOwnClinic)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::StartFirstImport)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::ViewExploreData)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::ViewOverviewStatistics)->isActionable);
    }

    public function testRequestClinicAccessIsAutoCompletedWhenClinicAccessExists(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);

        self::assertTrue($this->catalog->isAutoCompletedForUser($user, OnboardingStepKey::RequestClinicAccess));
    }

    public function testExploreStepUnlocksAfterPriorStepsAreCompleted(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);

        $persisted = [
            OnboardingStepKey::VerifyOwnClinic->value => true,
            OnboardingStepKey::StartFirstImport->value => true,
        ];

        $steps = $this->catalog->buildStepsForUser($user, $persisted);
        $exploreStep = $this->findStep($steps, OnboardingStepKey::ViewExploreData);

        self::assertTrue($exploreStep->isActionable);
        self::assertSame('/explore/allocation?hospitalFilter=my_hospitals', $exploreStep->actionUrl);
    }

    public function testParticipantWithoutRequestIsAskedToSendOne(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $step = $this->findStep($this->catalog->buildStepsForUser($user, []), OnboardingStepKey::RequestClinicAccess);

        self::assertSame(ClinicAccessStatus::NoRequest, $this->catalog->resolveClinicAccessState($user)->status);
        self::assertSame('/hospitals/requests/new', $step->actionUrl);
        self::assertNull($step->actionLabel);
        self::assertNull($step->secondaryActionUrl);
        self::assertFalse($step->canMarkComplete);
    }

    public function testPendingRequestLinksToTheRequest(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $request = $this->storeRequest($user, $this->otherHospital('St. Example'), '2026-11-20');

        $state = $this->catalog->resolveClinicAccessState($user);
        $step = $this->findStep($this->catalog->buildStepsForUser($user, []), OnboardingStepKey::RequestClinicAccess);

        self::assertSame(ClinicAccessStatus::Pending, $state->status);
        self::assertSame('St. Example', $state->hospitalLabel);
        self::assertSame('/hospitals/requests#request-'.$request->getPublicIdString(), $state->requestUrl);
        self::assertTrue($step->isActionable);
        self::assertFalse($step->isCompleted);
        self::assertFalse($step->canMarkComplete);
        self::assertSame($state->requestUrl, $step->actionUrl);
        self::assertSame('onboarding.steps.request_clinic_access.pending.description', $step->description->getMessage());
        self::assertSame(['hospital' => 'St. Example'], $step->description->getParameters());
        self::assertNull($step->secondaryActionUrl);
    }

    public function testRejectedRequestLinksToTheRequestAndToANewOne(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $request = $this->storeRequest($user, $this->otherHospital('St. Example'), '2026-11-20');
        $request->reject(UserFactory::new()->asAdmin()->create(), 'Wrong hospital', new \DateTimeImmutable('2026-11-21'));
        $this->entityManager()->flush();

        $state = $this->catalog->resolveClinicAccessState($user);
        $step = $this->findStep($this->catalog->buildStepsForUser($user, []), OnboardingStepKey::RequestClinicAccess);

        self::assertSame(ClinicAccessStatus::Rejected, $state->status);
        self::assertSame('/hospitals/requests#request-'.$request->getPublicIdString(), $step->actionUrl);
        self::assertSame('/hospitals/requests/new', $step->secondaryActionUrl);
        self::assertSame('onboarding.steps.request_clinic_access.rejected.description', $step->description->getMessage());
    }

    public function testPendingRequestWinsOverANewerRejectedOne(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $pending = $this->storeRequest($user, $this->otherHospital('Pending Hospital'), '2026-11-10');
        $rejected = $this->storeRequest($user, $this->otherHospital('Rejected Hospital'), '2026-11-20');
        $rejected->reject(UserFactory::new()->asAdmin()->create(), null, new \DateTimeImmutable('2026-11-21'));
        $this->entityManager()->flush();

        $state = $this->catalog->resolveClinicAccessState($user);

        self::assertSame(ClinicAccessStatus::Pending, $state->status);
        self::assertSame('Pending Hospital', $state->hospitalLabel);
        self::assertStringEndsWith('#request-'.$pending->getPublicIdString(), (string) $state->requestUrl);
    }

    public function testWithdrawnRequestCountsAsNoRequest(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $request = $this->storeRequest($user, $this->otherHospital('Withdrawn Hospital'), '2026-11-20');
        $request->withdraw(new \DateTimeImmutable('2026-11-21'));
        $this->entityManager()->flush();

        self::assertSame(ClinicAccessStatus::NoRequest, $this->catalog->resolveClinicAccessState($user)->status);
    }

    public function testRequestForAHospitalNotInTheListShowsTheEnteredName(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->storeRequest($user, null, '2026-11-20', 'Unlisted Clinic');

        self::assertSame('Unlisted Clinic', $this->catalog->resolveClinicAccessState($user)->hospitalLabel);
    }

    public function testStoredCompletionOfTheRequestStepIsIgnoredWithoutAccess(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $steps = $this->catalog->buildStepsForUser($user, [
            OnboardingStepKey::RequestClinicAccess->value => true,
            OnboardingStepKey::VerifyOwnClinic->value => true,
        ]);

        self::assertFalse($this->findStep($steps, OnboardingStepKey::RequestClinicAccess)->isCompleted);
        self::assertTrue($this->findStep($steps, OnboardingStepKey::RequestClinicAccess)->isActionable);
        self::assertFalse($this->findStep($steps, OnboardingStepKey::StartFirstImport)->isActionable);
    }

    public function testAccessGrantWithoutOwnershipCountsAsClinicAccess(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => HospitalFactory::createOne(['owner' => $owner]),
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        self::assertSame(ClinicAccessStatus::Granted, $this->catalog->resolveClinicAccessState($grantee)->status);
        self::assertTrue($this->findStep($this->catalog->buildStepsForUser($grantee, []), OnboardingStepKey::RequestClinicAccess)->isCompleted);
    }

    /**
     * A hospital owned by someone else; the factory would otherwise pick any existing user as owner.
     */
    private function otherHospital(string $name): Hospital
    {
        return HospitalFactory::createOne(['name' => $name, 'owner' => UserFactory::createOne()]);
    }

    private function storeRequest(User $user, ?Hospital $hospital, string $createdAt, ?string $hospitalName = null): HospitalAssignmentRequest
    {
        $request = new HospitalAssignmentRequest($user, $hospital, $hospitalName, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable($createdAt));
        $this->entityManager()->persist($request);
        $this->entityManager()->flush();

        return $request;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param list<\App\Onboarding\Application\Dto\OnboardingStepView> $steps
     */
    private function findStep(array $steps, OnboardingStepKey $key): \App\Onboarding\Application\Dto\OnboardingStepView
    {
        foreach ($steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        self::fail(sprintf('Step %s not found.', $key->value));
    }
}
