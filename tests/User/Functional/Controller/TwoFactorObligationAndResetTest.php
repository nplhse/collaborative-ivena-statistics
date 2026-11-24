<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Second step of #1357 (ADR 040): the configurable obligation for administrators, the reset by an administrator and the
 * recommendation for hospital owners and import grantees.
 */
#[ResetDatabase]
final class TwoFactorObligationAndResetTest extends WebTestCase
{
    use Factories;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testWithoutTheObligationAdministratorsUseTheAppAsUsual(): void
    {
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());

        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseIsSuccessful();
    }

    public function testObligationSendsAdministratorsWithoutTwoFactorToTheSetup(): void
    {
        $this->requireTwoFactorForAdmins();
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());

        $this->client->request(Request::METHOD_GET, '/admin');
        self::assertResponseRedirects('/settings/two-factor');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="two-factor-required"]');
        self::assertSelectorTextContains('.alert', 'Administrators must set up two-factor authentication before they can continue.');
    }

    public function testObligationKeepsPublicMediaFilesPublic(): void
    {
        $this->requireTwoFactorForAdmins();
        $media = MediaFactory::createOne(['originalFilename' => 'logo.png']);

        $this->client->request(Request::METHOD_GET, new MediaPublicUrlResolver()->resolve($media));

        // Reading the token for every request would start a session and turn the response private.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('public', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testObligationLeavesMediaFilesOfSignedInAdministratorsWithoutTwoFactorAlone(): void
    {
        $this->requireTwoFactorForAdmins();
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());
        $media = MediaFactory::createOne(['originalFilename' => 'logo.png']);

        $this->client->request(Request::METHOD_GET, new MediaPublicUrlResolver()->resolve($media));

        // The media route is stateless (#1570): no redirect to the setup, no session, public caching.
        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testObligationRefusesOtherRequestsThanPageLoads(): void
    {
        $this->requireTwoFactorForAdmins();
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());

        $this->client->xmlHttpRequest(Request::METHOD_GET, '/settings/account');
        self::assertResponseStatusCodeSame(403);

        $this->client->request(Request::METHOD_POST, '/settings/login-link');
        self::assertResponseStatusCodeSame(403);
    }

    public function testObligationLeavesAdministratorsWithTwoFactorAndOtherUsersAlone(): void
    {
        $this->requireTwoFactorForAdmins();
        $admin = $this->withTwoFactor(UserFactory::new()->asAdmin()->create());

        $this->client->loginUser($admin);
        $this->client->request(Request::METHOD_GET, '/settings/two-factor');
        self::assertResponseIsSuccessful();
        // Administrators keep it while the obligation is on.
        self::assertSelectorNotExists('[data-test="two-factor-disable-section"]');
        $this->client->request(Request::METHOD_POST, '/settings/two-factor/disable', ['_csrf_token' => 'irrelevant', 'code' => '000000']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser(UserFactory::new(['username' => 'plain-user'])->create());
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseIsSuccessful();
    }

    public function testAdministratorResetsTwoFactorOfAnotherAccount(): void
    {
        $target = $this->withTwoFactor(UserFactory::new(['username' => 'lost-phone', 'email' => 'lost-phone@example.test'])->create());
        $admin = UserFactory::new()->asAdmin()->create();
        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/admin/user/%d', $target->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Two-factor authentication');
        $form = $crawler->filter('form[action*="/reset-two-factor"]');
        self::assertCount(1, $form);

        $this->client->request(Request::METHOD_POST, (string) $form->attr('action'));
        self::assertResponseRedirects();

        self::assertFalse($this->reload($target)->isTotpAuthenticationEnabled());
        self::assertEmailCount(1);
        self::assertSame(
            'security.two_factor_reset',
            self::getContainer()->get(Connection::class)->fetchOne('SELECT type FROM notification WHERE recipient_id = ?', [$target->getId()]),
        );
        self::assertSame(
            1,
            (int) self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM audit_log WHERE intent = 'user.two_factor.reset_by_admin'"),
        );
    }

    public function testResetNeedsAValidToken(): void
    {
        $target = $this->withTwoFactor(UserFactory::new(['username' => 'lost-phone'])->create());
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());

        $this->client->request(Request::METHOD_POST, \sprintf('/admin/user/%d/reset-two-factor?token=invalid', $target->getId()));

        self::assertResponseRedirects();
        self::assertTrue($this->reload($target)->isTotpAuthenticationEnabled());
        self::assertEmailCount(0);
    }

    public function testResetIsNotOfferedForAccountsWithoutTwoFactorOrTheOwnAccount(): void
    {
        $plain = UserFactory::new(['username' => 'plain-user'])->create();
        $admin = $this->withTwoFactor(UserFactory::new()->asAdmin()->create());
        $this->client->loginUser($admin);

        $this->client->request(Request::METHOD_GET, \sprintf('/admin/user/%d', $plain->getId()));
        self::assertSelectorNotExists('form[action*="/reset-two-factor"]');

        $this->client->request(Request::METHOD_GET, \sprintf('/admin/user/%d', $admin->getId()));
        self::assertSelectorNotExists('form[action*="/reset-two-factor"]');
    }

    public function testTwoFactorIsRecommendedToAdministratorsOnly(): void
    {
        // #1508: for now only administrators; hospital owners and import grantees are most of the members.
        $admin = UserFactory::new(['username' => 'admin-user'])->asAdmin()->create();
        $owner = UserFactory::new(['username' => 'owner'])->create();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        $importer = UserFactory::new(['username' => 'importer'])->create();
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $importer,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View, HospitalPermission::Import]),
        ]);

        $this->client->loginUser($admin);
        $this->client->request(Request::METHOD_GET, '/settings');
        self::assertSelectorTextContains('[data-testid="settings-two-factor-recommended"]', 'Recommended');
        $this->client->request(Request::METHOD_GET, '/settings/security');
        self::assertSelectorExists('[data-test="security-two-factor-recommended"]');

        foreach ([$owner, $importer] as $user) {
            $this->client->loginUser($user);
            $this->client->request(Request::METHOD_GET, '/settings');
            self::assertSelectorNotExists('[data-testid="settings-two-factor-recommended"]');
            $this->client->request(Request::METHOD_GET, '/settings/security');
            self::assertSelectorNotExists('[data-test="security-two-factor-recommended"]');
        }

        $this->client->loginUser($this->withTwoFactor($admin));
        $this->client->request(Request::METHOD_GET, '/settings');
        self::assertSelectorNotExists('[data-testid="settings-two-factor-recommended"]');
    }

    /** The obligation is off in the test environment; this client gets a policy with it switched on. */
    private function requireTwoFactorForAdmins(): void
    {
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(TwoFactorAvailability::class, new TwoFactorAvailability($container->get(TwoFactorSecretCipher::class), false, true));
    }

    private function withTwoFactor(User $user): User
    {
        // After a request the kernel may have been rebooted; work on the entity of the current entity manager.
        $user = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->enableTotpAuthentication(
            self::getContainer()->get(TwoFactorSecretCipher::class)->encrypt(self::SECRET),
            self::SECRET,
            [],
            new \DateTimeImmutable('2026-11-22 12:00:00'),
        );
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $stored);

        return $stored;
    }
}
