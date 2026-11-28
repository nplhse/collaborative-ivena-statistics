<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Twig;

use App\User\UI\Twig\UserDisplayNameExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Signed-in viewers see the optional display name, anonymous visitors only the username (#462).
 */
#[CoversClass(UserDisplayNameExtension::class)]
final class UserDisplayNameExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, ?string, ?string, string}>
     */
    public static function names(): iterable
    {
        // signed in, display name, username => shown name
        yield 'signed in with a display name' => [true, 'Michael Müller', 'mmueller', 'Michael Müller'];
        yield 'signed in without a display name' => [true, null, 'mmueller', 'mmueller'];
        yield 'signed in with an empty display name' => [true, '', 'mmueller', 'mmueller'];
        yield 'signed in with a blank display name' => [true, '   ', 'mmueller', 'mmueller'];
        yield 'anonymous visitor' => [false, 'Michael Müller', 'mmueller', 'mmueller'];
        yield 'neither name nor username' => [true, null, null, ''];
    }

    #[DataProvider('names')]
    public function testUserDisplayNameFallsBackToTheUsername(bool $signedIn, ?string $displayName, ?string $username, string $expected): void
    {
        $extension = new UserDisplayNameExtension($this->security($signedIn));

        $shown = $extension->userDisplayName($displayName, $username);

        self::assertSame($expected, $shown);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function viewers(): iterable
    {
        yield 'signed in' => [true];
        yield 'anonymous' => [false];
    }

    #[DataProvider('viewers')]
    public function testOnlySignedInViewersSeeDisplayNames(bool $signedIn): void
    {
        $extension = new UserDisplayNameExtension($this->security($signedIn));

        $canSee = $extension->canSeeDisplayNames();

        self::assertSame($signedIn, $canSee);
    }

    /**
     * A viewer who is signed in (also through "remember me") or not.
     */
    private function security(bool $signedIn): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => 'IS_AUTHENTICATED_REMEMBERED' === $attribute && $signedIn,
        );

        return $security;
    }
}
