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

    public function testGetOrCreatePersistsRowForAnonymousSubject(): void
    {
        self::bootKernel();

        $service = self::getContainer()->get(CookieConsentService::class);
        $repository = self::getContainer()->get(CookieConsentRepository::class);

        $request = Request::create('/');

        $consent = $service->getOrCreateForRequest($request, null);

        $reloaded = $repository->findOneBySubjectId($consent->getSubjectId());

        self::assertNotNull($reloaded);
        self::assertSame($consent->getSubjectId(), $reloaded->getSubjectId());
        self::assertNull($reloaded->getDecidedAt());
    }

    public function testGetOrCreateReusesSubjectCookieWhenPresent(): void
    {
        self::bootKernel();

        $service = self::getContainer()->get(CookieConsentService::class);

        $request = Request::create('/');
        $request->cookies->set(CookieConsentService::SUBJECT_COOKIE_NAME, 'fixed-subject-id-1234567890123456');

        $first = $service->getOrCreateForRequest($request, null);
        self::assertSame('fixed-subject-id-1234567890123456', $first->getSubjectId());

        $second = $service->getOrCreateForRequest($request, null);
        self::assertSame($first->getId(), $second->getId());
    }

    public function testGetOrCreateAssociatesLoggedInUser(): void
    {
        self::bootKernel();

        $userProxy = UserFactory::createOne([
            'username' => 'consent-user-'.bin2hex(random_bytes(4)),
        ]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->find(User::class, $userProxy->getId());
        self::assertInstanceOf(User::class, $user);

        $service = self::getContainer()->get(CookieConsentService::class);

        $request = Request::create('/');

        $consent = $service->getOrCreateForRequest($request, $user);

        self::assertNotNull($consent->getUser());
        self::assertSame($user->getId(), $consent->getUser()->getId());
    }

    public function testApplyPreferencePersistsMonitoringFlag(): void
    {
        self::bootKernel();

        $service = self::getContainer()->get(CookieConsentService::class);
        $repository = self::getContainer()->get(CookieConsentRepository::class);

        $request = Request::create('/');
        $consent = $service->getOrCreateForRequest($request, null);

        $service->applyPreference($consent, true);

        $reloaded = $repository->findOneBySubjectId($consent->getSubjectId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->getPreferences()['monitoring']);
        self::assertInstanceOf(\DateTimeImmutable::class, $reloaded->getDecidedAt());
    }
}
