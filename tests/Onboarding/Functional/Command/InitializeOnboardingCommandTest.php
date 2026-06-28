<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Functional\Command;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Onboarding\Infrastructure\Repository\UserOnboardingStepRepository;
use App\Onboarding\UI\Console\Command\InitializeOnboardingCommand;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\Console\Tester\CommandTester;

final class InitializeOnboardingCommandTest extends DatabaseKernelTestCase
{
    private UserOnboardingStepRepository $stepRepository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->stepRepository = self::getContainer()->get(UserOnboardingStepRepository::class);
    }

    public function testInitializesClinicAccessStepForOwner(): void
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

        $tester = $this->executeInitCommand();
        $tester->assertCommandIsSuccessful();

        self::assertNotNull($this->stepRepository->findForUserAndStep($user, OnboardingStepKey::RequestClinicAccess));
    }

    public function testInitializesImportStepForRecentImport(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);
        ImportFactory::createOne([
            'createdBy' => $user,
            'hospital' => $hospital,
            'createdAt' => new \DateTimeImmutable('-1 month'),
        ]);

        $tester = $this->executeInitCommand();
        $tester->assertCommandIsSuccessful();

        self::assertNotNull($this->stepRepository->findForUserAndStep($user, OnboardingStepKey::StartFirstImport));
    }

    public function testSecondRunDoesNotDuplicateEntries(): void
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

        $this->executeInitCommand()->assertCommandIsSuccessful();
        $this->executeInitCommand()->assertCommandIsSuccessful();

        self::assertCount(1, $this->stepRepository->findCompletedByUser($user));
    }

    public function testDryRunDoesNotPersist(): void
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

        $tester = $this->executeInitCommand(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame([], $this->stepRepository->findCompletedByUser($user));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeInitCommand(array $input = []): CommandTester
    {
        /** @var InitializeOnboardingCommand $command */
        $command = self::getContainer()->get(InitializeOnboardingCommand::class);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
