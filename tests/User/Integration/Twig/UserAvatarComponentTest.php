<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Twig;

use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class UserAvatarComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    #[DataProvider('initials')]
    public function testRendersInitialsOfFirstAndLastWord(string $displayName, string $expected): void
    {
        $this->signIn();

        $rendered = $this->renderTwigComponent('UserAvatar', ['userId' => 8, 'displayName' => $displayName, 'username' => 'someone']);

        self::assertSame($expected, trim($rendered->crawler()->filter('.avatar')->text()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function initials(): iterable
    {
        yield 'two words' => ['Michael Müller', 'MM'];
        yield 'second example of the issue' => ['Andreas Meyer', 'AM'];
        yield 'lowercase umlauts' => ['ümit özdemir', 'ÜÖ'];
        yield 'hyphenated names' => ['Hans-Peter Müller-Lüdenscheidt', 'HM'];
        yield 'middle name is skipped' => ['Anna Maria Schmidt', 'AS'];
        yield 'single word' => ['Ada', 'A'];
        yield 'last word without a letter' => ['Ada 123', 'A'];
        yield 'decomposed umlaut' => ["U\u{0308}mit O\u{0308}zdemir", 'ÜÖ'];
    }

    public function testColourComesFromTheNumericUserId(): void
    {
        $this->signIn();

        // 8 % 8 = 0 → blue, 9 % 8 = 1 → azure, 15 % 8 = 7 → teal
        self::assertStringContainsString('avatar avatar-sm bg-blue-lt', $this->render(8, 'Michael Müller'));
        self::assertStringContainsString('avatar avatar-sm bg-azure-lt', $this->render(9, 'Michael Müller'));
        self::assertStringContainsString('avatar avatar-sm bg-teal-lt', $this->render(15, 'Andreas Meyer'));
    }

    #[DataProvider('unusableNames')]
    public function testFallsBackToNeutralIconWithoutUsableName(?string $displayName): void
    {
        $this->signIn();

        $rendered = $this->renderTwigComponent('UserAvatar', ['userId' => 8, 'displayName' => $displayName, 'username' => 'someone']);
        $avatar = $rendered->crawler()->filter('.avatar');

        self::assertSame('fallback', $avatar->attr('data-avatar'));
        self::assertSame('avatar avatar-sm', $avatar->attr('class'));
        self::assertCount(1, $avatar->filter('svg'));
        self::assertSame('', trim($avatar->text()));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableNames(): iterable
    {
        yield 'no name' => [null];
        yield 'whitespace only' => ['   '];
        yield 'no letters' => ['123 456'];
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

    public function testDecorativeAvatarIsHiddenFromAssistiveTechnology(): void
    {
        $this->signIn();

        $decorative = $this->renderTwigComponent('UserAvatar', ['userId' => 1, 'displayName' => 'Ada Admin'])->crawler()->filter('.avatar');
        self::assertSame('true', $decorative->attr('aria-hidden'));
        self::assertNull($decorative->attr('aria-label'));

        $labelled = $this->renderTwigComponent('UserAvatar', ['userId' => 1, 'displayName' => 'Ada Admin', 'decorative' => false])->crawler()->filter('.avatar');
        self::assertNull($labelled->attr('aria-hidden'));
        self::assertSame('img', $labelled->attr('role'));
        self::assertSame('Ada Admin', $labelled->attr('aria-label'));
        self::assertSame('Ada Admin', $labelled->attr('title'));
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

        self::assertStringContainsString('avatar-xl', (string) $avatar->attr('class'));

        $unknown = $this->renderTwigComponent('UserAvatar', ['userId' => 1, 'displayName' => 'Ada Admin', 'size' => 'huge']);
        self::assertStringContainsString('avatar avatar-sm', (string) $unknown);
    }

    public function testReadsTheUserEntity(): void
    {
        $this->signIn();

        $user = new User();
        $user->setUsername('ada');
        $user->setDisplayName('Ada Admin');

        $avatar = $this->renderTwigComponent('UserAvatar', ['user' => $user])->crawler()->filter('.avatar');

        self::assertSame('AA', trim($avatar->text()));
        // An unsaved user has no ID yet and gets the neutral colour.
        self::assertStringContainsString('bg-secondary-lt', (string) $avatar->attr('class'));
    }

    private function render(int $userId, string $displayName): string
    {
        return (string) $this->renderTwigComponent('UserAvatar', ['userId' => $userId, 'displayName' => $displayName]);
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
