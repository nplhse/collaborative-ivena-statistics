<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\Tests\Support\RateLimit\DeniesRateLimiter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\ResetPasswordRequestRepository;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ResetPasswordControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use DeniesRateLimiter;
    use Factories;
    use HasBrowser;

    public function testResetRequestIsBlockedForUnverifiedEmail(): void
    {
        UserFactory::new([
            'email' => 'unverified@example.test',
            'isVerified' => false,
            'username' => 'unverified',
        ])->create();

        $this->browser()
            ->visit('/reset-password')
            ->fillField('Email', 'unverified@example.test')
            ->click('Send reset email')
            ->assertSuccessful()
            ->assertSee('Check your email')
        ;

        $user = $this->getUserRepository()->findOneBy(['email' => 'unverified@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(0, $this->getResetPasswordRequestRepository()->count(['user' => $user]));
    }

    public function testResetRequestIsCreatedForVerifiedEmail(): void
    {
        UserFactory::new([
            'email' => 'verified@example.test',
            'isVerified' => true,
            'username' => 'verified',
        ])->create();

        $this->browser()
            ->visit('/reset-password')
            ->fillField('Email', 'verified@example.test')
            ->click('Send reset email')
            ->assertSuccessful()
            ->assertSee('Check your email')
        ;

        $user = $this->getUserRepository()->findOneBy(['email' => 'verified@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(1, $this->getResetPasswordRequestRepository()->count(['user' => $user]));
    }

    public function testResetRequestIsRateLimitedSilently(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        UserFactory::new([
            'email' => 'rate-limited-reset@example.test',
            'isVerified' => true,
            'username' => 'rate-limited-reset',
        ])->create();

        $this->denyRateLimiter(
            'limiter.reset_password_request',
            $this->ipRateLimitKey('reset_password_request'),
        );

        $crawler = $client->request(Request::METHOD_GET, '/reset-password');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Send reset email')->form([
            'reset_password_request_form[email]' => 'rate-limited-reset@example.test',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');

        $user = $this->getUserRepository()->findOneBy(['email' => 'rate-limited-reset@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(0, $this->getResetPasswordRequestRepository()->count(['user' => $user]));
    }

    public function testResetRequestIsBlockedForDisabledEmail(): void
    {
        UserFactory::new([
            'email' => 'disabled@example.test',
            'isEnabled' => false,
            'isVerified' => true,
            'username' => 'disabled',
        ])->create();

        $this->browser()
            ->visit('/reset-password')
            ->fillField('Email', 'disabled@example.test')
            ->click('Send reset email')
            ->assertSuccessful()
            ->assertSee('Check your email')
        ;

        $user = $this->getUserRepository()->findOneBy(['email' => 'disabled@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(0, $this->getResetPasswordRequestRepository()->count(['user' => $user]));
    }

    /**
     * The main flow after the mail: the link opens the form, the new password is saved and works for the sign-in.
     * Which fields show the strength meter: PasswordFormTypeTest and PasswordWidgetRenderingTest.
     */
    public function testTheResetLinkSetsANewPasswordThatWorksForTheSignIn(): void
    {
        $user = UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test', 'isVerified' => true]);
        $resetToken = self::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user);

        $this->browser()
            ->visit('/reset-password/reset/'.$resetToken->getToken())
            ->assertSee('Set a new password')
            ->fillField('New password', 'Gr33n-Valley-Lantern-42!')
            ->fillField('Repeat new password', 'Gr33n-Valley-Lantern-42!')
            ->click('Save new password')

            ->assertOn('/login')
        ;
        $this->loginWithConsent($this->browser(), 'alice', 'Gr33n-Valley-Lantern-42!')
            ->assertSeeIn('#user_name', 'alice')
        ;
    }

    private function getUserRepository(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function getResetPasswordRequestRepository(): ResetPasswordRequestRepository
    {
        return self::getContainer()->get(ResetPasswordRequestRepository::class);
    }
}
