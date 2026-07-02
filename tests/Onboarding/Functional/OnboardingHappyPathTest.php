<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Functional;

use App\Admin\Application\Service\GrantParticipantUrlGenerator;
use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class OnboardingHappyPathTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;
    use HasBrowser;

    public function testRegisteredParticipantSeesFirstOnboardingStepAfterLogin(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $username = sprintf('onboarding-%s', $suffix);
        $email = sprintf('onboarding-%s@example.test', $suffix);
        $password = 'super-secret-password';

        $admin = UserFactory::new()
            ->asNotificationRecipient()
            ->create([
                'username' => sprintf('onboarding-admin-%s', $suffix),
            ]);

        $this->browser()
            ->visit('/register')
            ->fillField('registration_form[username]', $username)
            ->fillField('registration_form[email]', $email)
            ->fillField('registration_form[plainPassword]', $password)
            ->checkField('registration_form[acceptTerms]')
            ->click('Register')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Check your email')
        ;

        $user = UserFactory::repository()->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isVerified());

        /** @var VerifyEmailHelperInterface $verifyEmailHelper */
        $verifyEmailHelper = self::getContainer()->get(VerifyEmailHelperInterface::class);
        $verificationUrl = $verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            (string) $user->getEmail(),
            ['id' => (string) $user->getId()],
        )->getSignedUrl();

        $this->browser()
            ->visit($verificationUrl)
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Login')
            ->assertSee('Your email address has been confirmed. You can sign in now.')
        ;

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertTrue($user->isVerified());

        /** @var GrantParticipantUrlGenerator $grantParticipantUrlGenerator */
        $grantParticipantUrlGenerator = self::getContainer()->get(GrantParticipantUrlGenerator::class);
        $grantParticipantUrl = $grantParticipantUrlGenerator->generate((int) $user->getId());

        $this->browser()
            ->actingAs($admin)
            ->visit($grantParticipantUrl)
            ->assertSuccessful()
            ->visit('/logout')
        ;

        \Zenstruck\Foundry\Persistence\refresh($user);
        self::assertContains(UserRole::PARTICIPANT, $user->getRoles());

        $this->loginWithConsent($this->browser(), $username, $password)
            ->assertSeeElement('[data-testid="dashboard-onboarding-card"]')
            ->assertSeeElement('[data-testid="onboarding-step-request_clinic_access"]')
        ;
    }
}
