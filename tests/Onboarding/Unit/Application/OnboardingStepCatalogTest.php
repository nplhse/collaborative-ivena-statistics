<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Unit\Application;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Onboarding\Application\OnboardingStepCatalog;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

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
