<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Integration\Application;

use App\Onboarding\Application\OnboardingProgressService;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Onboarding\Infrastructure\Repository\UserOnboardingStepRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class OnboardingProgressServiceTest extends DatabaseKernelTestCase
{
    public function testRequestStepCannotBeMarkedCompleteWithoutClinicAccess(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        try {
            self::getContainer()->get(OnboardingProgressService::class)->markCompleted($user, OnboardingStepKey::RequestClinicAccess);
            self::fail('Marking the request step complete must be refused.');
        } catch (AccessDeniedHttpException) {
        }

        self::assertNull(self::getContainer()->get(UserOnboardingStepRepository::class)->findForUserAndStep($user, OnboardingStepKey::RequestClinicAccess));
    }
}
