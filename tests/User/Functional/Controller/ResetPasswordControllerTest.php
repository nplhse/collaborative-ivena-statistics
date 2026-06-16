<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Controller;

use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\ResetPasswordRequestRepository;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ResetPasswordControllerTest extends WebTestCase
{
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

    private function getUserRepository(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function getResetPasswordRequestRepository(): ResetPasswordRequestRepository
    {
        return self::getContainer()->get(ResetPasswordRequestRepository::class);
    }
}
