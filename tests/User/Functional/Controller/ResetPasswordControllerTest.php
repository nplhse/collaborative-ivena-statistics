<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\Tests\Support\RateLimit\DeniesRateLimiter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\ResetPasswordRequestRepository;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ResetPasswordControllerTest extends WebTestCase
{
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

    public function testResetPasswordFormShowsStrengthFeedbackOnNewPasswordFieldOnly(): void
    {
        $user = UserFactory::new([
            'email' => 'reset-pwd-ui@example.test',
            'isVerified' => true,
            'username' => 'reset-pwd-ui-user',
        ])->create();

        $resetToken = self::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user);

        $this->browser()
            ->visit('/reset-password/reset/'.$resetToken->getToken())
            ->assertSuccessful()
            ->assertSee('Set a new password')
            ->assertSee('Password strength')
            ->use(static function (Crawler $crawler): void {
                self::assertGreaterThan(
                    0,
                    $crawler->filter('[data-testid^="password-strength-"][data-testid*="_first"]')->count(),
                );
                self::assertCount(
                    0,
                    $crawler->filter('[data-testid^="password-strength-"][data-testid*="_second"]'),
                );
            })
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
