<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Twig;

use App\User\Domain\Entity\User;
use App\User\UI\Twig\Components\UserAvatar;
use App\User\UI\Twig\UserDisplayNameExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * What the avatar shows (#462): initials, colour, size and label. How the template renders them, for anonymous
 * viewers and for assistive technology, is in `UserAvatarComponentTest`.
 */
#[CoversClass(UserAvatar::class)]
final class UserAvatarTest extends TestCase
{
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
        // U + combining diaeresis is composed to Ü before the first letter is taken.
        yield 'decomposed umlaut' => ["U\u{0308}mit O\u{0308}zdemir", 'ÜÖ'];
    }

    #[DataProvider('initials')]
    public function testInitialsOfFirstAndLastWord(string $displayName, string $expected): void
    {
        $avatar = $this->avatar(signedIn: true);
        $avatar->displayName = $displayName;

        $initials = $avatar->getInitials();

        self::assertSame($expected, $initials);
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

    #[DataProvider('unusableNames')]
    public function testNoInitialsWithoutAUsableName(?string $displayName): void
    {
        $avatar = $this->avatar(signedIn: true);
        $avatar->displayName = $displayName;

        $initials = $avatar->getInitials();

        self::assertNull($initials);
    }

    public function testNoInitialsForAnonymousViewers(): void
    {
        $avatar = $this->avatar(signedIn: false);
        $avatar->displayName = 'Michael Müller';

        $initials = $avatar->getInitials();

        self::assertNull($initials);
    }

    public function testRevealDisplayNameShowsInitialsAndNameToAnonymousViewers(): void
    {
        $avatar = $this->avatar(signedIn: false);
        $avatar->displayName = 'Michael Müller';
        $avatar->username = 'mmueller';
        $avatar->revealDisplayName = true;

        $shown = [$avatar->getInitials(), $avatar->getLabel()];

        self::assertSame(['MM', 'Michael Müller'], $shown);
    }

    /**
     * @return iterable<string, array{bool, ?string, string}>
     */
    public static function labels(): iterable
    {
        yield 'signed in' => [true, 'Michael Müller', 'Michael Müller'];
        yield 'signed in, no display name' => [true, null, 'mmueller'];
        yield 'anonymous' => [false, 'Michael Müller', 'mmueller'];
    }

    #[DataProvider('labels')]
    public function testLabelFollowsTheDisplayNameRule(bool $signedIn, ?string $displayName, string $expected): void
    {
        $avatar = $this->avatar($signedIn);
        $avatar->displayName = $displayName;
        $avatar->username = 'mmueller';

        $label = $avatar->getLabel();

        self::assertSame($expected, $label);
    }

    /**
     * @return iterable<string, array{?int, string}>
     */
    public static function colours(): iterable
    {
        // user id % 8 picks the entry of the palette
        yield '8 % 8 = 0' => [8, 'blue'];
        yield '9 % 8 = 1' => [9, 'azure'];
        yield '15 % 8 = 7' => [15, 'teal'];
        yield 'no id' => [null, 'secondary'];
    }

    #[DataProvider('colours')]
    public function testColourComesFromTheNumericUserId(?int $userId, string $expected): void
    {
        $avatar = $this->avatar(signedIn: true);
        $avatar->userId = $userId;

        $colour = $avatar->getColour();

        self::assertSame($expected, $colour);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sizes(): iterable
    {
        yield 'known size' => ['xl', 'avatar-xl'];
        yield 'unknown size falls back to small' => ['huge', 'avatar-sm'];
    }

    #[DataProvider('sizes')]
    public function testSizeClass(string $size, string $expected): void
    {
        $avatar = $this->avatar(signedIn: true);
        $avatar->size = $size;

        $class = $avatar->getSizeClass();

        self::assertSame($expected, $class);
    }

    public function testReadsTheUserEntity(): void
    {
        $avatar = $this->avatar(signedIn: true);
        $avatar->user = new User()->setUsername('ada')->setDisplayName('Ada Admin');

        $shown = [$avatar->getInitials(), $avatar->getLabel(), $avatar->getColour()];

        // An unsaved user has no ID yet and gets the neutral colour.
        self::assertSame(['AA', 'Ada Admin', 'secondary'], $shown);
    }

    private function avatar(bool $signedIn): UserAvatar
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => 'IS_AUTHENTICATED_REMEMBERED' === $attribute && $signedIn,
        );

        return new UserAvatar(new UserDisplayNameExtension($security));
    }
}
