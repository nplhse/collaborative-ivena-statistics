<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\UsageEvents;

use App\Analytics\Application\UsageEvents\UsageEventContextResolver;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class UsageEventContextResolverTest extends KernelTestCase
{
    use Factories;

    public function testResolveFromRequestDeniedWithoutCurrentRequest(): void
    {
        self::bootKernel();

        $resolver = self::getContainer()->get(UsageEventContextResolver::class);
        $context = $resolver->resolveFromRequest();

        self::assertFalse($context->allowed);
        self::assertNull($context->analyticsUserKey);
    }

    public function testResolveFromRequestDeniedWithoutAnalyticsConsent(): void
    {
        self::bootKernel();

        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push(Request::create('/'));

        $resolver = self::getContainer()->get(UsageEventContextResolver::class);
        $context = $resolver->resolveFromRequest();

        self::assertFalse($context->allowed);
    }

    public function testResolveFromRequestAllowsAuthenticatedUserWithConsent(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $consentService = self::getContainer()->get(CookieConsentService::class);
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);

        $userProxy = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'ctx-resolver-'.bin2hex(random_bytes(4)),
            ])
        ;
        $user = $entityManager->find(User::class, $userProxy->getId());
        self::assertInstanceOf(User::class, $user);

        $request = Request::create('/');
        $consent = $consentService->resolveForRequest($request, $user);
        $consentService->applyPreference($consent, true, $user);
        $request->cookies->set(CookieConsentService::SUBJECT_COOKIE_NAME, $consent->getSubjectId());

        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push($request);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $resolver = self::getContainer()->get(UsageEventContextResolver::class);
        $context = $resolver->resolveFromRequest();

        self::assertTrue($context->allowed);
        self::assertNotNull($context->analyticsUserKey);
        self::assertNotNull($context->visitorKey);
        self::assertNotNull($context->sessionKey);
        self::assertSame(UserRole::ADMIN, $context->userRole);
    }

    public function testResolveForUserDeniedWithoutConsentOrId(): void
    {
        self::bootKernel();

        $resolver = self::getContainer()->get(UsageEventContextResolver::class);

        $withoutId = new User();
        $withoutId->setUsername('no-id-'.bin2hex(random_bytes(3)));
        $withoutId->setEmail('no-id-'.bin2hex(random_bytes(3)).'@example.test');
        $withoutId->setRoles([UserRole::USER]);
        self::assertFalse($resolver->resolveForUser($withoutId)->allowed);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $userProxy = UserFactory::createOne([
            'username' => 'ctx-no-consent-'.bin2hex(random_bytes(4)),
        ]);
        $user = $entityManager->find(User::class, $userProxy->getId());
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($resolver->resolveForUser($user)->allowed);
    }

    public function testResolveForUserAllowsWithStoredConsent(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $consentService = self::getContainer()->get(CookieConsentService::class);
        $resolver = self::getContainer()->get(UsageEventContextResolver::class);

        $userProxy = UserFactory::createOne([
            'username' => 'ctx-with-consent-'.bin2hex(random_bytes(4)),
            'roles' => [UserRole::USER, UserRole::PARTICIPANT],
        ]);
        $user = $entityManager->find(User::class, $userProxy->getId());
        self::assertInstanceOf(User::class, $user);

        $request = Request::create('/');
        $consent = $consentService->resolveForRequest($request, $user);
        $consentService->applyPreference($consent, true, $user);

        $context = $resolver->resolveForUser($user);
        self::assertTrue($context->allowed);
        self::assertNotNull($context->analyticsUserKey);
        self::assertNull($context->visitorKey);
        self::assertNull($context->sessionKey);
        self::assertSame(UserRole::PARTICIPANT, $context->userRole);
    }
}
