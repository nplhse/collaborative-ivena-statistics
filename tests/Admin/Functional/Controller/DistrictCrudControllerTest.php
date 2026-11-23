<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Http\BkgDistrictBoundaryClient;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DistrictCrudControllerTest extends WebTestCase
{
    use Factories;

    private int $httpCalls = 0;

    public function testCreatingADistrictValidatesTheKeyAndCallsNoExternalService(): void
    {
        $client = $this->adminClient();
        $area = DispatchAreaFactory::createOne(['name' => 'Darmstadt']);

        $crawler = $client->request(Request::METHOD_GET, '/admin/district/new');
        self::assertResponseIsSuccessful();
        self::assertSame('', $crawler->filter('input[name="District[ags]"]')->attr('value') ?? '');

        $form = $crawler->filter('form[name="District"]')->form();
        $client->submit($form, ['District[ags]' => '641', 'District[name]' => 'Darmstadt']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'exactly five digits');

        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[name="District"]')->form();
        $client->submit($form, ['District[ags]' => '06411', 'District[name]' => 'Darmstadt', 'District[dispatchArea]' => (string) $area->getId()]);
        self::assertResponseRedirects();

        $district = $this->em()->getRepository(District::class)->findOneBy(['ags' => '06411']);
        self::assertInstanceOf(District::class, $district);
        self::assertSame('06', $district->getStateCode());
        self::assertSame($area->getId(), $district->getDispatchArea()?->getId());
        self::assertSame(0, $this->httpCalls);
    }

    public function testADuplicateKeyIsRejected(): void
    {
        $client = $this->adminClient();
        $this->em()->persist(new District('06411', 'Darmstadt'));
        $this->em()->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin/district/new');
        $client->submit($crawler->filter('form[name="District"]')->form(), ['District[ags]' => '06411', 'District[name]' => 'Duplicate']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'already exists');
    }

    public function testTheAdminFetchesAMissingBoundary(): void
    {
        $client = $this->adminClient();
        $district = new District('06411', 'Darmstadt');
        $this->em()->persist($district);
        $this->em()->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin/district/'.$district->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Missing');
        self::assertSame(0, $this->httpCalls);

        $client->request(Request::METHOD_POST, (string) $crawler->filter('form[action*="fetch-boundary"]')->attr('action'));
        self::assertResponseRedirects();
        self::assertSame(1, $this->httpCalls);

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('body', 'Available');
        self::assertSelectorTextContains('body', '© BKG');
        self::assertCount(0, $crawler->filter('form[action*="fetch-boundary"]'));

        $this->em()->clear();
        $geometry = $this->em()->find(DistrictGeometry::class, $district->getId());
        self::assertSame('bkg', $geometry?->getGeometryProvider());
    }

    public function testAnInvalidTokenStartsNoFetch(): void
    {
        $client = $this->adminClient();
        $district = new District('06411', 'Darmstadt');
        $this->em()->persist($district);
        $this->em()->flush();
        $crawler = $client->request(Request::METHOD_GET, '/admin/district/'.$district->getId());
        $url = (string) preg_replace('/token=[^&]+/', 'token=wrong', (string) $crawler->filter('form[action*="fetch-boundary"]')->attr('action'));

        $client->request(Request::METHOD_POST, $url);

        self::assertResponseRedirects();
        self::assertSame(0, $this->httpCalls);
    }

    public function testAFetchThatCannotStartIsReported(): void
    {
        $client = $this->adminClient();
        $district = new District('06411', 'Darmstadt');
        $this->em()->persist($district);
        $this->em()->flush();
        $crawler = $client->request(Request::METHOD_GET, '/admin/district/'.$district->getId());
        $url = (string) $crawler->filter('form[action*="fetch-boundary"]')->attr('action');
        $client->request(Request::METHOD_POST, $url);

        $client->request(Request::METHOD_POST, $url);

        self::assertResponseRedirects();
        self::assertSame(1, $this->httpCalls);
    }

    public function testTheDispatchAreaFormOffersOnlyFreeDistrictsAndShowsTheShapeState(): void
    {
        $client = $this->adminClient();
        $area = DispatchAreaFactory::createOne(['name' => 'Kassel']);
        $other = DispatchAreaFactory::createOne(['name' => 'Fulda']);
        $free = new District('06611', 'Kassel');
        $taken = new District('06631', 'Fulda')->setDispatchArea($other);
        $em = $this->em();
        $em->persist($free);
        $em->persist($taken);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin/dispatch-area/'.$area->getId().'/edit');
        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="DispatchArea[districts][]"] option')->each(static fn ($o): string => trim($o->text()));
        self::assertContains('06611 Kassel', $options);
        self::assertNotContains('06631 Fulda', $options);

        $form = $crawler->filter('form[name="DispatchArea"]')->form();
        $client->submit($form, ['DispatchArea[districts]' => [(string) $free->getId()]]);
        self::assertResponseRedirects();

        $this->em()->clear();
        $stored = $this->em()->find(District::class, $free->getId());
        self::assertSame($area->getId(), $stored?->getDispatchArea()?->getId());
        self::assertInstanceOf(DispatchArea::class, $stored->getDispatchArea());

        $client->request(Request::METHOD_GET, '/admin/dispatch-area/'.$area->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '06611 Kassel');
        self::assertSelectorTextContains('body', '1 boundary missing');
    }

    public function testNonAdminsCannotOpenTheDistrictAdmin(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'plain-'.bin2hex(random_bytes(4))]);
        \assert($user instanceof User);
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, '/admin/district');

        self::assertResponseStatusCodeSame(403);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        $http = new MockHttpClient(function (): MockResponse {
            ++$this->httpCalls;

            return new MockResponse((string) json_encode(['features' => [[
                'properties' => ['ags' => '06411', 'bez' => 'Kreisfreie Stadt'],
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[8.6, 49.9], [8.7, 49.9], [8.7, 50.0], [8.6, 49.9]]]],
            ]]]));
        });
        self::getContainer()->set(BkgDistrictBoundaryClient::class, new BkgDistrictBoundaryClient($http, new NullLogger(), new NativeClock()));

        $admin = UserFactory::new()->asAdmin()->create(['username' => 'district-admin-'.bin2hex(random_bytes(4))]);
        $client->loginUser($admin);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
