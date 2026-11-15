<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig;

use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * users.link() (#1240): display name for signed-in viewers, username for anonymous visitors,
 * optional decorative avatar.
 */
final class UserLinkMacroTest extends KernelTestCase
{
    public function testSignedInViewersSeeTheDisplayNameWithTheUsernameAsTitle(): void
    {
        $this->signIn();

        $link = $this->render($this->user('mmueller', 'Michael Müller'))->filter('a');

        self::assertSame('Michael Müller', trim($link->text()));
        self::assertSame('@mmueller', $link->attr('title'));
        self::assertStringStartsWith('/explore/user/', (string) $link->attr('href'));
    }

    public function testUsersWithoutDisplayNameKeepTheUsernameAndNoTitle(): void
    {
        $this->signIn();

        $link = $this->render($this->user('mmueller', null))->filter('a');

        self::assertSame('mmueller', trim($link->text()));
        self::assertNull($link->attr('title'));
    }

    public function testAnonymousVisitorsOnlySeeTheUsername(): void
    {
        $html = $this->render($this->user('mmueller', 'Michael Müller'), "{avatar: 'sm'}")->html();

        self::assertStringContainsString('mmueller', $html);
        self::assertStringNotContainsString('Michael', $html);
        self::assertStringNotContainsString('title=', $html);
        self::assertStringContainsString('data-avatar="fallback"', $html);
    }

    public function testNoAvatarByDefault(): void
    {
        $this->signIn();

        self::assertCount(0, $this->render($this->user('mmueller', 'Michael Müller'))->filter('.avatar'));
    }

    public function testAvatarOptionRendersADecorativeAvatarOfTheGivenSize(): void
    {
        $this->signIn();

        $crawler = $this->render($this->user('mmueller', 'Michael Müller'), "{avatar: 'xs'}");
        $avatar = $crawler->filter('.user-link > .avatar');

        self::assertSame('MM', trim($avatar->text()));
        self::assertStringContainsString('avatar-xs', (string) $avatar->attr('class'));
        self::assertSame('true', $avatar->attr('aria-hidden'));
        self::assertSame('Michael Müller', trim($crawler->filter('.user-link > a')->text()));
    }

    public function testUnlinkedUserKeepsTheTitle(): void
    {
        $this->signIn();

        // Without ROLE_PARTICIPANT the name is not linked.
        $crawler = $this->render($this->user('mmueller', 'Michael Müller'), '{requireParticipant: true}');

        self::assertCount(0, $crawler->filter('a'));
        self::assertSame('@mmueller', $crawler->filter('span[title]')->attr('title'));
        self::assertSame('Michael Müller', trim($crawler->filter('span[title]')->text()));
    }

    public function testOutputHasNoSurroundingWhitespace(): void
    {
        $this->signIn();

        // Text right after the call, as in "name, date", must not get a space before the comma.
        $text = $this->render($this->user('mmueller', 'Michael Müller'), "{avatar: 'inline'}")->text(normalizeWhitespace: false);

        self::assertSame('MM Michael Müller', preg_replace('/\s+/', ' ', trim($text)));
        self::assertSame($text, trim($text));
    }

    public function testNullUserRendersTheFallback(): void
    {
        $this->signIn();

        self::assertSame('unknown', trim($this->render(null, "{empty: 'unknown', avatar: 'xs'}")->text()));
    }

    private function render(?User $user, string $options = '{}'): Crawler
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $template = $twig->createTemplate(
            "{% import '@Shared/_macros/users.html.twig' as users %}<div>{{ users.link(user, ".$options.') }}</div>',
        );

        return new Crawler($template->render(['user' => $user]))->filter('div')->first();
    }

    private function user(string $username, ?string $displayName): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setDisplayName($displayName);
        $user->setPublicId(Uuid::v7());

        return $user;
    }

    private function signIn(): void
    {
        $viewer = new User();
        $viewer->setUsername('viewer');
        $viewer->setRoles(['ROLE_USER']);

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = self::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken($viewer, 'main', $viewer->getRoles()));
    }
}
