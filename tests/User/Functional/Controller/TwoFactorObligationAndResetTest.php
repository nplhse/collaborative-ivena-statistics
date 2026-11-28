<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Second step of #1357 (ADR 040) over HTTP: the obligation for administrators sends them to the setup and leaves media
 * files alone; the recommendation badge in the settings.
 *
 * Checked lower down: which requests the obligation redirects, refuses (forms, background requests) or leaves alone
 * (obligation off, no session, other accounts, allowed routes, impersonation) in `RequireTwoFactorListenerTest`; that
 * administrators cannot switch it off in `TwoFactorSettingsTest`; who gets the recommendation in
 * `TwoFactorRecommendationQueryTest`. The reset by an administrator moved to `UserTwoFactorResetTest` (Admin) and
 * `TwoFactorResetTest` (integration).
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

    /** @return iterable<string, array{string, string}> */
    public static function recommendationPages(): iterable
    {
        yield 'settings overview' => ['/settings', '[data-testid="settings-two-factor-recommended"]'];
        yield 'sign-in and security' => ['/settings/security', '[data-test="security-two-factor-recommended"]'];
    }

    #[DataProvider('recommendationPages')]
    public function testAdministratorsWithoutTwoFactorSeeTheRecommendation(string $path, string $badge): void
    {
        $this->client->loginUser(UserFactory::new(['username' => 'alice'])->asAdmin()->create());

        $this->client->request(Request::METHOD_GET, $path);

        self::assertSelectorTextContains($badge, 'Recommended');
    }

    public function testAdministratorsWithTwoFactorDoNotSeeTheRecommendation(): void
    {
        $this->client->loginUser($this->withTwoFactor(UserFactory::new(['username' => 'alice'])->asAdmin()->create()));

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
}
