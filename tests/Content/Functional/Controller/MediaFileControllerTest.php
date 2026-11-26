<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Domain\Enum\MediaVisibility;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Media\MediaStorage;
use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Delivery of media files through /media and the old URLs /uploads/media (ADR 039).
 */
#[ResetDatabase]
final class MediaFileControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;

    public function testStoredFileIsDeliveredWithLongLivedPublicCaching(): void
    {
        $client = self::createClient();
        $media = MediaFactory::createOne(['originalFilename' => 'Größe Übersicht.png']);
        $url = new MediaPublicUrlResolver()->resolve($media);

        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(self::getContainer()->get(MediaStorage::class)->absolutePath((string) $media->getStorageKey()), $response->getFile()->getPathname());
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString("filename*=utf-8''Gr%C3%B6%C3%9Fe%20%C3%9Cbersicht.png", (string) $response->headers->get('Content-Disposition'));
        self::assertSame([], $response->headers->getCookies(), 'the stateless route sets no cookie');
    }

    public function testSignedInUserGetsTheFileWithPublicCachingAndNoCookie(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());
        $url = new MediaPublicUrlResolver()->resolve(MediaFactory::createOne());

        $client->request(Request::METHOD_GET, $url);

        $this->assertDeliveredStateless($client);
    }

    public function testUserSignedInWithRememberMeGetsTheFileWithPublicCachingAndNoCookie(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['username' => 'media-remember-me', 'isVerified' => true]);
        $client->getContainer()->get('cache.rate_limiter')->clear();
        // A real login with "remember me": with its cookie, the remember-me authenticator reads the token (#1595).
        $this->loginWithRememberMe($client, 'media-remember-me');
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'));
        $url = new MediaPublicUrlResolver()->resolve(MediaFactory::createOne());

        $client->request(Request::METHOD_GET, $url);

        $this->assertDeliveredStateless($client);
    }

    public function testAnonymousVisitorWithASessionGetsTheFileWithPublicCachingAndNoCookie(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/login');
        self::assertNotNull($client->getCookieJar()->get('MOCKSESSID'), 'the login page starts a session');
        $url = new MediaPublicUrlResolver()->resolve(MediaFactory::createOne());

        $client->request(Request::METHOD_GET, $url);

        $this->assertDeliveredStateless($client);
    }

    public function testUnchangedFileAnswersNotModified(): void
    {
        $client = self::createClient();
        $url = new MediaPublicUrlResolver()->resolve(MediaFactory::createOne());
        $client->request(Request::METHOD_GET, $url);
        $etag = (string) $client->getResponse()->headers->get('ETag');
        self::assertNotSame('', $etag);

        $client->request(Request::METHOD_GET, $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
    }

    public function testUnknownKeyIsNotFound(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRestrictedMediaIsNotDelivered(): void
    {
        $client = self::createClient();
        $media = MediaFactory::createOne(['visibility' => MediaVisibility::Restricted]);

        $client->request(Request::METHOD_GET, new MediaPublicUrlResolver()->resolve($media));

        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingFileIsNotFound(): void
    {
        $client = self::createClient();
        $media = MediaFactory::createOne();
        unlink(self::getContainer()->get(MediaStorage::class)->absolutePath((string) $media->getStorageKey()));

        $client->request(Request::METHOD_GET, new MediaPublicUrlResolver()->resolve($media));

        self::assertResponseStatusCodeSame(404);
    }

    public function testOldUrlOfAMediaItemNotYetMovedServesTheOldFile(): void
    {
        $client = self::createClient();
        MediaFactory::new()->legacy('not-moved.png')->create();

        $client->request(Request::METHOD_GET, '/uploads/media/not-moved.png');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('max-age=3600', $cacheControl);
        self::assertStringNotContainsString('immutable', $cacheControl);
    }

    public function testOldUrlOfAMovedMediaItemRedirectsPermanently(): void
    {
        $client = self::createClient();
        $media = MediaFactory::new()->legacy('moved.png')->create();
        $media->attachStoredFile('originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png', 68, str_repeat('a', 64), new \DateTimeImmutable('2026-11-21'));
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $client->request(Request::METHOD_GET, '/uploads/media/moved.png');

        self::assertResponseRedirects('/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png', 301);
    }

    public function testOldUrlWithoutMediaItemIsNotFound(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/uploads/media/unknown.png');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A request with a session cookie must not touch the session on the stateless route (#1570): in debug mode
     * Symfony throws, otherwise the response turns private.
     */
    private function assertDeliveredStateless(KernelBrowser $client): void
    {
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
        self::assertSame([], $response->headers->getCookies(), 'the stateless route sets no cookie');
    }
}
