<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Old indication review URLs keep working until v0.4-beta (#1128, removal in #1160).
 */
#[ResetDatabase]
final class LegacyIndicationReviewRedirectControllerTest extends WebTestCase
{
    use Factories;

    private const string PUBLIC_ID = '0193a7f2-6c1e-7b3d-9f4a-2e5c8d1b0a47';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyPaths(): iterable
    {
        yield 'worklist' => ['/explore/indication/raw/review?segment=unreviewed&search=STEMI', '/tools/indication-review?segment=unreviewed&search=STEMI'];
        yield 'start matching' => ['/explore/indication/raw/review/start/matching', '/tools/indication-review/start/matching'];
        yield 'start reviewing' => ['/explore/indication/raw/review/start/reviewing', '/tools/indication-review/start/reviewing'];
        yield 'item' => ['/explore/indication/raw/review/'.self::PUBLIC_ID.'?segment=open', '/tools/indication-review/'.self::PUBLIC_ID.'?segment=open'];
        yield 'skip' => ['/explore/indication/raw/review/'.self::PUBLIC_ID.'/skip?segment=open', '/tools/indication-review/'.self::PUBLIC_ID.'/skip?segment=open'];
    }

    #[DataProvider('legacyPaths')]
    public function testLegacyPathRedirectsPermanentlyWithQueryString(string $legacyPath, string $target): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]));
        $client->request(Request::METHOD_GET, $legacyPath);

        self::assertResponseStatusCodeSame(Response::HTTP_PERMANENTLY_REDIRECT);
        self::assertResponseRedirects($target);
    }

    public function testLegacyItemKeepsThePostMethod(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]));
        $client->request(Request::METHOD_POST, '/explore/indication/raw/review/'.self::PUBLIC_ID, ['indication_raw_review' => ['action' => 'skip']]);

        self::assertResponseStatusCodeSame(Response::HTTP_PERMANENTLY_REDIRECT);
        self::assertResponseRedirects('/tools/indication-review/'.self::PUBLIC_ID);
    }

    public function testLegacyRouteNamesStillGenerateTheOldPaths(): void
    {
        self::bootKernel();
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        self::assertSame('/explore/indication/raw/review', $urlGenerator->generate('app_explore_indication_raw_review_worklist'));
        self::assertSame('/explore/indication/raw/review/'.self::PUBLIC_ID, $urlGenerator->generate('app_explore_indication_raw_review', ['publicId' => self::PUBLIC_ID]));
    }

    public function testGuestIsSentToLoginFirst(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/explore/indication/raw/review');

        self::assertResponseRedirects('/login');
    }
}
