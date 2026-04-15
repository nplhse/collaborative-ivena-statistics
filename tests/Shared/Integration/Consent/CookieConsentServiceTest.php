<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Consent;

use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CookieConsentServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testResolveDoesNotPersistAnonymousSubjectUntilApply(): void
    {
        self::bootKernel();

        $service = self::getContainer()->get(CookieConsentService::class);
        $repository = self::getContainer()->get(CookieConsentRepository::class);

        $request = Request::create('/');

        $consent = $service->resolveForRequest($request, null);

        self::assertNull($consent->getId());
        self::assertNull($repository->findOneBySubjectId($consent->getSubjectId()));
    }

    public function testResolveReusesSubjectCookieWhenPresentWithoutDatabaseRow(): void
    {
        self::bootKernel();

        $service = self::getContainer()->get(CookieConsentService::class);

        $request = Request::create('/');
        $request->cookies->set(CookieConsentService::SUBJECT_COOKIE_NAME, 'fixed-subject-id-1234567890123456');

        $first = $service->resolveForRequest($request, null);
        $second = $service->resolveForRequest($request, null);

        self::assertSame('fixed-subject-id-1234567890123456', $first->getSubjectId());
        self::assertSame('fixed-subject-id-1234567890123456', $second->getSubjectId());
        self::assertNull($first->getId());
        self::assertNull($second->getId());
    }

    public function testApplyPreferenceAfterResolveAssociatesLoggedInUser(): void
    {
        self::bootKernel();

        $userProxy = UserFactory::createOne([
            'username' => 'consent-user-'.bin2hex(random_bytes(4)),
        ]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->find(User::class, $userProxy->getId());
        self::assertInstanceOf(User::class, $user);

        $service = self::getContainer()->get(CookieConsentService::class);
        $repository = self::getContainer()->get(CookieConsentRepository::class);

        $request = Request::create('/');

        $consent = $service->resolveForRequest($request, $user);
        $service->applyPreference($consent, false, $user);

        $reloaded = $repository->findOneBySubjectId($consent->getSubjectId());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getUser());
        self::assertSame($user->getId(), $reloaded->getUser()->getId());
    }

    public function testApplyPreferencePersistsMonitoringFlag(): void
    {
        self::bootKernel();

        $service = self::getContainer()->get(CookieConsentService::class);
        $repository = self::getContainer()->get(CookieConsentRepository::class);

        $request = Request::create('/');
        $consent = $service->resolveForRequest($request, null);

        $service->applyPreference($consent, true, null);

        $reloaded = $repository->findOneBySubjectId($consent->getSubjectId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->getPreferences()['monitoring']);
        self::assertInstanceOf(\DateTimeImmutable::class, $reloaded->getDecidedAt());
    }

    public function testAttachUserLinksExistingDecidedConsentWithoutChangingPreferences(): void
    {
        self::bootKernel();

        $service = self::getContainer()->get(CookieConsentService::class);
        $repository = self::getContainer()->get(CookieConsentRepository::class);

        $firstUser = UserFactory::createOne([
            'username' => 'consent-old-'.bin2hex(random_bytes(4)),
        ]);
        $secondUser = UserFactory::createOne([
            'username' => 'consent-new-'.bin2hex(random_bytes(4)),
        ]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $oldUser = $entityManager->find(User::class, $firstUser->getId());
        $newUser = $entityManager->find(User::class, $secondUser->getId());
        self::assertInstanceOf(User::class, $oldUser);
        self::assertInstanceOf(User::class, $newUser);

        $request = Request::create('/');
        $consent = $service->resolveForRequest($request, $oldUser);
        $service->applyPreference($consent, true, $oldUser);

        $service->attachUser($consent, $newUser);

        $reloaded = $repository->findOneBySubjectId($consent->getSubjectId());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getUser());
        self::assertSame($newUser->getId(), $reloaded->getUser()->getId());
        self::assertTrue($reloaded->getPreferences()['monitoring']);
    }
}
