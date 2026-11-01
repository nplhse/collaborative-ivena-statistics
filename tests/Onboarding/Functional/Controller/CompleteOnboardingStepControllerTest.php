<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Onboarding\Infrastructure\Repository\UserOnboardingStepRepository;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CompleteOnboardingStepControllerTest extends WebTestCase
{
    use Factories;

    public function testParticipantCanMarkAvailableStepComplete(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        $token = $this->csrfToken($client, 'onboarding_complete_'.OnboardingStepKey::RequestClinicAccess->value);

        $client->request(Request::METHOD_POST, '/onboarding/steps/request_clinic_access/complete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/');
        $repo = $client->getContainer()->get(UserOnboardingStepRepository::class);
        self::assertNotNull($repo->findForUserAndStep($user, OnboardingStepKey::RequestClinicAccess));
    }

    public function testCompletingAStepRecordsNoAnalyticsEvent(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $container = $client->getContainer();
        $consentService = $container->get(CookieConsentService::class);
        $appUser = $container->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $appUser);
        // Analytics consent is looked up by the consent subject cookie; with it, the old code stored an event.
        $subjectId = 'onboarding-analytics-subject';
        $cookieName = $consentService->subjectCookieName();
        $consentService->applyPreference(
            $consentService->resolveForRequest(Request::create('/', cookies: [$cookieName => $subjectId]), $appUser),
            true,
            $appUser,
        );
        $client->getCookieJar()->set(new Cookie($cookieName, $subjectId));

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');
        $token = $this->csrfToken($client, 'onboarding_complete_'.OnboardingStepKey::RequestClinicAccess->value);

        $client->request(Request::METHOD_POST, '/onboarding/steps/request_clinic_access/complete', [
            '_token' => $token,
        ]);

        // The step is still stored; onboarding is no longer analysed in the backend (#950).
        self::assertResponseRedirects('/');
        $repo = $container->get(UserOnboardingStepRepository::class);
        self::assertNotNull($repo->findForUserAndStep($user, OnboardingStepKey::RequestClinicAccess));
        self::assertSame(0, (int) $container->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM analytics_product_event WHERE event_name = 'onboarding.step.completed'",
        ));
    }

    public function testCannotMarkStepThreeBeforeStepTwo(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        $token = $this->csrfToken($client, 'onboarding_complete_'.OnboardingStepKey::StartFirstImport->value);

        $client->request(Request::METHOD_POST, '/onboarding/steps/start_first_import/complete', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidCsrfIsRejected(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $client->loginUser($user);

        $client->request(Request::METHOD_POST, '/onboarding/steps/view_explore_data/complete', [
            '_token' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownStepReturnsNotFound(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $client->loginUser($user);

        $client->request(Request::METHOD_POST, '/onboarding/steps/unknown_step/complete', [
            '_token' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRecompletingAlreadyPersistedStepIsIdempotent(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        $token = $this->csrfToken($client, 'onboarding_complete_'.OnboardingStepKey::RequestClinicAccess->value);
        $client->request(Request::METHOD_POST, '/onboarding/steps/request_clinic_access/complete', [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/');

        $client->request(Request::METHOD_GET, '/');
        $token = $this->csrfToken($client, 'onboarding_complete_'.OnboardingStepKey::RequestClinicAccess->value);
        $client->request(Request::METHOD_POST, '/onboarding/steps/request_clinic_access/complete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/');
        $repo = $client->getContainer()->get(UserOnboardingStepRepository::class);
        self::assertCount(1, $repo->findCompletedByUser($user));
        self::assertNotNull($repo->findForUserAndStep($user, OnboardingStepKey::RequestClinicAccess));
    }

    public function testAutoCompletedStepIsRejectedWithoutPersisting(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'dispatchArea' => $dispatchArea,
            'owner' => $user,
            'state' => $state,
        ]);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        $token = $this->csrfToken($client, 'onboarding_complete_'.OnboardingStepKey::RequestClinicAccess->value);
        $client->request(Request::METHOD_POST, '/onboarding/steps/request_clinic_access/complete', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        $repo = $client->getContainer()->get(UserOnboardingStepRepository::class);
        self::assertNull($repo->findForUserAndStep($user, OnboardingStepKey::RequestClinicAccess));
    }

    public function testCompleteIsForbiddenWithoutParticipantRole(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $client->loginUser($user);

        $client->request(Request::METHOD_POST, '/onboarding/steps/request_clinic_access/complete', [
            '_token' => 'unused',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $requestStack = $client->getContainer()->get('request_stack');
        $request = $client->getRequest();
        $requestStack->push($request);
        try {
            $token = $client->getContainer()->get('security.csrf.token_manager')->getToken($tokenId);
        } finally {
            $requestStack->pop();
        }

        return (string) $token->getValue();
    }
}
