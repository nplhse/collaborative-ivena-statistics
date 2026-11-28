<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Twig;

use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * How the UserAvatar template renders (#462). Initials, colour, size and label are worked out in `UserAvatarTest`.
 */
final class UserAvatarComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTheInitialsOnTheColourOfTheUser(): void
    {
        $this->signIn();

        $avatar = $this->renderTwigComponent('UserAvatar', ['userId' => 9, 'displayName' => 'Michael Müller'])->crawler()->filter('.avatar');

        self::assertSame('initials', $avatar->attr('data-avatar'));
        self::assertSame('avatar avatar-sm bg-azure-lt user-avatar', $avatar->attr('class'));
        self::assertSame('--user-avatar-color: var(--tblr-azure)', $avatar->attr('style'));
        self::assertSame('MM', trim($avatar->text()));
    }

    public function testFallsBackToNeutralIconWithoutUsableName(): void
    {
        $this->signIn();

        $avatar = $this->renderTwigComponent('UserAvatar', ['userId' => 8, 'displayName' => '123 456', 'username' => 'someone'])->crawler()->filter('.avatar');

        self::assertSame('fallback', $avatar->attr('data-avatar'));
        self::assertSame('avatar avatar-sm', $avatar->attr('class'));
        self::assertCount(1, $avatar->filter('svg'));
        self::assertSame('', trim($avatar->text()));
    }

    public function testAnonymousViewersOnlySeeTheIcon(): void
    {
        $html = (string) $this->renderTwigComponent('UserAvatar', [
            'userId' => 8,
            'displayName' => 'Michael Müller',
            'username' => 'mmueller',
            'decorative' => false,
        ]);

        self::assertStringContainsString('data-avatar="fallback"', $html);
        self::assertStringNotContainsString('Michael', $html);
        self::assertStringNotContainsString('MM', $html);
        self::assertStringContainsString('aria-label="mmueller"', $html);
    }

    public function testRevealDisplayNameShowsInitialsAndNameToAnonymousViewers(): void
    {
        $avatar = $this->renderTwigComponent('UserAvatar', [
            'userId' => 8,
            'displayName' => 'Michael Müller',
            'username' => 'mmueller',
            'decorative' => false,
            'revealDisplayName' => true,
        ])->crawler()->filter('.avatar');

        self::assertSame('initials', $avatar->attr('data-avatar'));
        self::assertSame('MM', trim($avatar->text()));
        self::assertSame('Michael Müller', $avatar->attr('aria-label'));
    }

    public function testDecorativeAvatarIsHiddenFromAssistiveTechnology(): void
    {
        $this->signIn();

        $avatar = $this->renderTwigComponent('UserAvatar', ['userId' => 1, 'displayName' => 'Ada Admin'])->crawler()->filter('.avatar');

        self::assertSame('true', $avatar->attr('aria-hidden'));
        self::assertNull($avatar->attr('aria-label'));
    }

    public function testLabelledAvatarIsAnImageWithTheName(): void
    {
        $this->signIn();

        $avatar = $this->renderTwigComponent('UserAvatar', ['userId' => 1, 'displayName' => 'Ada Admin', 'decorative' => false])->crawler()->filter('.avatar');

        self::assertNull($avatar->attr('aria-hidden'));
        self::assertSame('img', $avatar->attr('role'));
        self::assertSame('Ada Admin', $avatar->attr('aria-label'));
        self::assertSame('Ada Admin', $avatar->attr('title'));
    }

    public function testSizeAndExtraAttributes(): void
    {
        $this->signIn();

        $avatar = $this->renderTwigComponent('UserAvatar', [
            'userId' => 1,
            'displayName' => 'Ada Admin',
            'size' => 'xl',
            'data-testid' => 'profile-avatar',
        ])->crawler()->filter('[data-testid="profile-avatar"]');

        self::assertStringContainsString('avatar avatar-xl', (string) $avatar->attr('class'));
    }

    public function testReadsTheUserEntity(): void
    {
        $this->signIn();
        $user = new User()->setUsername('ada')->setDisplayName('Ada Admin');

        $avatar = $this->renderTwigComponent('UserAvatar', ['user' => $user, 'decorative' => false])->crawler()->filter('.avatar');

        self::assertSame('AA', trim($avatar->text()));
        self::assertSame('Ada Admin', $avatar->attr('aria-label'));
    }

    private function signIn(): void
    {
        $user = new User();
        $user->setUsername('viewer');
        $user->setRoles(['ROLE_USER']);

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = self::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
