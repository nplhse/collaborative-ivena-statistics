<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security\LoginLink;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\LoginLink\Exception\ExpiredLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Which changes to an account end its open login links (#1355): the link is signed with `email`, `password` and
 * `loginLinkAllowed` (`login_link.signature_properties` in security.yaml), and it expires. Requesting, confirming and
 * using a link once over HTTP is in `LoginLinkTest`.
 */
final class LoginLinkSignatureTest extends DatabaseKernelTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // The handler picks the firewall from the current request.
        self::getContainer()->get(RequestStack::class)->push(Request::create('/login'));
    }

    public function testAnUnchangedAccountCanUseItsLink(): void
    {
        $alice = $this->user();
        $link = $this->createLink($alice);

        $user = $this->handler()->consumeLoginLink($link);

        self::assertSame('alice', $user->getUserIdentifier());
    }

    /** @return iterable<string, array{\Closure(User): User}> */
    public static function changes(): iterable
    {
        yield 'opting out' => [static fn (User $user): User => $user->setLoginLinkEnabled(false)];
        yield 'a new password' => [static fn (User $user): User => $user->setPassword('changed-hash')];
        yield 'a new email address' => [static fn (User $user): User => $user->setEmail('alice.new@example.test')];
        // Administrators cannot sign in with a link (User::isLoginLinkAllowed()).
        yield 'promotion to administrator' => [static fn (User $user): User => $user->setRoles([UserRole::USER, UserRole::ADMIN])];
    }

    /** @param \Closure(User): User $change */
    #[DataProvider('changes')]
    public function testAChangeToTheAccountInvalidatesOpenLinks(\Closure $change): void
    {
        $alice = $this->user();
        $link = $this->createLink($alice);
        $change($alice);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->expectException(InvalidLoginLinkException::class);

        $this->handler()->consumeLoginLink($link);
    }

    public function testAnExpiredLinkFails(): void
    {
        $link = $this->createLink($this->user(), lifetime: -60);

        $this->expectException(ExpiredLoginLinkException::class);

        $this->handler()->consumeLoginLink($link);
    }

    private function user(): User
    {
        return UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test', 'isVerified' => true, 'loginLinkEnabled' => true]);
    }

    /** The check request of a fresh link, as the browser sends it. */
    private function createLink(User $user, ?int $lifetime = null): Request
    {
        $url = $this->handler()->createLoginLink($user, null, $lifetime)->getUrl();
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return Request::create('/login/link/check', Request::METHOD_POST, $query);
    }

    private function handler(): LoginLinkHandlerInterface
    {
        return self::getContainer()->get(LoginLinkHandlerInterface::class);
    }
}
