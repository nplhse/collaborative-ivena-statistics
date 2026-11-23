<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Geo\DispatchAreaShapes;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DispatchAreaShapesControllerTest extends WebTestCase
{
    use DispatchAreaShapes;
    use Factories;
    use InteractsWithAuthenticatedUser;

    private const string URL = '/statistics/geo/dispatch-areas.geojson';

    public function testAnonymousVisitorsGetNoShapes(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, self::URL);

        self::assertResponseRedirects();
    }

    public function testASignedInUserGetsPrivateShapesAndARevalidation(): void
    {
        $client = self::createClient();
        DispatchAreaFactory::createOne(['name' => 'Fulda', 'state' => StateFactory::createOne(['name' => 'Hessen'])]);
        $this->giveDispatchAreasShapes();
        $this->loginAsRoleUser($client);

        $client->request(Request::METHOD_GET, self::URL);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/geo+json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        /** @var array{features: list<array{properties: array{key: string, neighbour: bool}}>} $data */
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($this->dispatchAreaKey('Fulda'), $data['features'][0]['properties']['key']);
        self::assertTrue($data['features'][0]['properties']['neighbour']);

        $client->request(Request::METHOD_GET, self::URL, server: ['HTTP_IF_NONE_MATCH' => (string) $response->getEtag()]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
        self::assertSame('', (string) $client->getResponse()->getContent());
    }
}
