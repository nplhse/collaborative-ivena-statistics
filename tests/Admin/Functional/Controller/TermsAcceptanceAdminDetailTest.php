<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/** Admins see per user and per hospital which version of the terms was accepted (#1564) and the upload authority (#1565). */
#[ResetDatabase]
final class TermsAcceptanceAdminDetailTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set(TermsVersion::class, new TermsVersion('2026-12'));
        $admin = UserFactory::new()->asAdmin()->create(['username' => 'terms-admin']);
        // The administrator accepted the current version; otherwise the login prompt would come first.
        $this->accept($admin, '2026-12', '2026-12-02 08:00:00');
        $this->client->loginUser($admin);
    }

    public function testUserDetailShowsTheLatestAcceptedVersion(): void
    {
        $user = UserFactory::createOne(['username' => 'accepted-user']);
        $this->accept($user, null, '2026-11-20 10:00:00');
        $this->accept($user, '2026-12', '2026-12-02 09:30:00');

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/user/'.$user->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Version 2026-12 on 02.12.2026 09:30', $crawler->filter('body')->text());
        self::assertStringNotContainsString('not the current version', $crawler->filter('body')->text());
    }

    public function testUserDetailMarksAnOlderAcceptanceAndAMissingOne(): void
    {
        $older = UserFactory::createOne(['username' => 'older-user']);
        $this->accept($older, null, '2026-11-20 10:00:00');
        $never = UserFactory::createOne(['username' => 'never-user']);

        $olderPage = $this->client->request(Request::METHOD_GET, '/admin/user/'.$older->getId())->filter('body')->text();
        self::assertStringContainsString('Without version on 20.11.2026 10:00', $olderPage);
        self::assertStringContainsString('not the current version', $olderPage);

        $neverPage = $this->client->request(Request::METHOD_GET, '/admin/user/'.$never->getId())->filter('body')->text();
        self::assertStringContainsString('No acceptance stored', $neverPage);
    }

    public function testHospitalDetailCountsAcceptancesOfTheCurrentVersionAmongPeopleWithAccess(): void
    {
        $hospital = HospitalFactory::createOne();
        $current = UserFactory::createOne(['username' => 'current-grantee']);
        $this->accept($current, '2026-12', '2026-12-02 09:30:00');
        $older = UserFactory::createOne(['username' => 'older-grantee']);
        $this->accept($older, null, '2026-11-20 10:00:00');
        $never = UserFactory::createOne(['username' => 'never-grantee']);
        foreach ([$current, $older, $never] as $user) {
            HospitalAccessGrantFactory::createOne(['user' => $user, 'hospital' => $hospital]);
        }

        $text = $this->client->request(Request::METHOD_GET, '/admin/hospital/'.$hospital->getId())->filter('body')->text();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Accepted the current version 2026-12: 1 of 3', $text);
        self::assertStringContainsString('No acceptance stored', $text);
    }

    public function testHospitalDetailShowsTheUploadAuthorityOfTheOwnerAndOfPeopleWhoMayImport(): void
    {
        $owner = UserFactory::createOne(['username' => 'authority-owner']);
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        $importer = UserFactory::createOne(['username' => 'authority-importer']);
        $viewer = UserFactory::createOne(['username' => 'authority-viewer']);
        HospitalAccessGrantFactory::createOne(['user' => $importer, 'hospital' => $hospital, 'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View, HospitalPermission::Import])]);
        HospitalAccessGrantFactory::createOne(['user' => $viewer, 'hospital' => $hospital, 'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View])]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TermsAcceptance(
            $entityManager->getReference(User::class, $importer->getId()),
            TermsAcceptanceKind::UploadAuthority,
            '2026-12',
            'en',
            null,
            new \DateTimeImmutable('2026-12-03 11:00:00'),
            $entityManager->getReference(Hospital::class, $hospital->getId()),
        ));
        $entityManager->flush();

        $lines = $this->client->request(Request::METHOD_GET, '/admin/hospital/'.$hospital->getId())->filter('body')->text();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Owner: authority-owner · Upload authority: not confirmed', $lines);
        self::assertStringContainsString('Upload authority: Version 2026-12 on 03.12.2026 11:00', $lines);
        // Without the import permission the line names no upload authority.
        self::assertSame(2, substr_count($lines, 'Upload authority:'));
    }

    private function accept(User $user, ?string $version, string $acceptedAt): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TermsAcceptance(
            $entityManager->getReference(User::class, $user->getId()),
            TermsAcceptanceKind::Terms,
            $version,
            'en',
            null,
            new \DateTimeImmutable($acceptedAt),
        ));
        $entityManager->flush();
    }
}
