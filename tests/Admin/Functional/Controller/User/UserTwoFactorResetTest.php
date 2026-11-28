<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller\User;

use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The reset of two-factor authentication in the user detail page (#1357, ADR 040). Audit entry and notification are in
 * `TwoFactorResetTest` (User, integration).
 */
#[ResetDatabase]
final class UserTwoFactorResetTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testAnAdministratorResetsTwoFactorOfAnotherAccount(): void
    {
        $alice = $this->withTwoFactor(UserFactory::new(['username' => 'alice', 'email' => 'alice@example.test'])->create());
        $this->client->loginUser(UserFactory::new()->asAdmin()->create(['username' => 'admin']));
        $form = $this->client->request(Request::METHOD_GET, \sprintf('/admin/user/%d', $alice->getId()))->filter('form[action*="/reset-two-factor"]');

        $this->client->request(Request::METHOD_POST, (string) $form->attr('action'));

        self::assertResponseRedirects();
        self::assertFalse($this->reload($alice)->isTotpAuthenticationEnabled());
    }

    public function testTheResetNeedsAValidToken(): void
    {
        $alice = $this->withTwoFactor(UserFactory::new(['username' => 'alice'])->create());
        $this->client->loginUser(UserFactory::new()->asAdmin()->create(['username' => 'admin']));

        $this->client->request(Request::METHOD_POST, \sprintf('/admin/user/%d/reset-two-factor?token=invalid', $alice->getId()));

        self::assertResponseRedirects();
        self::assertTrue($this->reload($alice)->isTotpAuthenticationEnabled());
        self::assertEmailCount(0);
    }

    public function testTheResetIsOfferedForAnotherAccountWithTwoFactor(): void
    {
        $alice = $this->withTwoFactor(UserFactory::new(['username' => 'alice'])->create());
        $this->client->loginUser(UserFactory::new()->asAdmin()->create(['username' => 'admin']));

        $this->client->request(Request::METHOD_GET, \sprintf('/admin/user/%d', $alice->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Two-factor authentication');
        self::assertSelectorCount(1, 'form[action*="/reset-two-factor"]');
    }

    public function testTheResetIsNotOfferedForAnAccountWithoutTwoFactor(): void
    {
        $bob = UserFactory::new(['username' => 'bob'])->create();
        $this->client->loginUser($this->withTwoFactor(UserFactory::new()->asAdmin()->create(['username' => 'admin'])));

        $this->client->request(Request::METHOD_GET, \sprintf('/admin/user/%d', $bob->getId()));

        self::assertSelectorNotExists('form[action*="/reset-two-factor"]');
    }

    public function testTheResetIsNotOfferedForTheOwnAccount(): void
    {
        $admin = $this->withTwoFactor(UserFactory::new()->asAdmin()->create(['username' => 'admin']));
        $this->client->loginUser($admin);

        $this->client->request(Request::METHOD_GET, \sprintf('/admin/user/%d', $admin->getId()));

        self::assertSelectorNotExists('form[action*="/reset-two-factor"]');
    }

    private function withTwoFactor(User $user): User
    {
        $user->enableTotpAuthentication(
            self::getContainer()->get(TwoFactorSecretCipher::class)->encrypt('JBSWY3DPEHPK3PXP'),
            'JBSWY3DPEHPK3PXP',
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
