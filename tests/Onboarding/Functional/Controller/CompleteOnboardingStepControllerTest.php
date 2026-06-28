<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Functional\Controller;

use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\Onboarding\Infrastructure\Repository\UserOnboardingStepRepository;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
