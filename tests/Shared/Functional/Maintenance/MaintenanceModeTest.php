<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Maintenance;

use App\Shared\Infrastructure\Maintenance\MaintenanceMode;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Maintenance mode (#1680, ADR 046): while the flag is on, only administrators get past the maintenance page.
 */
#[ResetDatabase]
final class MaintenanceModeTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    private MaintenanceMode $maintenanceMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $maintenanceMode = self::getContainer()->get(MaintenanceMode::class);
        self::assertInstanceOf(MaintenanceMode::class, $maintenanceMode);
        $this->maintenanceMode = $maintenanceMode;
        $this->maintenanceMode->disable();
    }

    protected function tearDown(): void
    {
        $this->maintenanceMode->disable();
        parent::tearDown();
    }

    public function testWithoutTheFlagNothingChanges(): void
    {
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="maintenance-page"]');
        self::assertSelectorNotExists('[data-testid="maintenance-banner"]');
    }

    public function testVisitorsGetTheMaintenancePage(): void
    {
        $this->maintenanceMode->enable();

        $this->client->request(Request::METHOD_GET, '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '300');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertSelectorTextContains('[data-testid="maintenance-page"]', 'Maintenance in progress');
    }

    public function testSignedInParticipantsGetTheMaintenancePage(): void
    {
        $this->maintenanceMode->enable();
        $this->client->loginUser(UserFactory::new()->asParticipant()->create());

        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorExists('[data-testid="maintenance-page"]');
    }

    public function testBackgroundRequestsGetAnEmpty503(): void
    {
        $this->maintenanceMode->enable();

        $this->client->request(Request::METHOD_POST, '/feedback');
        self::assertResponseStatusCodeSame(503);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        $this->client->request(Request::METHOD_GET, '/', server: ['HTTP_TURBO_FRAME' => 'content']);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    public function testHealthAndSignInStayReachable(): void
    {
        $this->maintenanceMode->enable();

        $this->client->request(Request::METHOD_GET, '/health');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('degraded', $payload['status']);

        $this->client->request(Request::METHOD_GET, '/login');
        self::assertResponseIsSuccessful();
    }

    public function testAdministratorsUseTheApplicationAndSeeTheBannerOutsideTheAdmin(): void
    {
        $this->maintenanceMode->enable();
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());

        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="maintenance-banner"]');

        $this->client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="maintenance-banner"]');
        self::assertSelectorExists('[data-health-check="maintenance"]');
    }

    public function testAdministratorsKeepAccessWhileImpersonatingAParticipant(): void
    {
        $participant = UserFactory::new()->asParticipant()->create();
        $this->client->loginUser(UserFactory::new()->asAdmin()->create());
        $this->client->request(Request::METHOD_GET, '/?_switch_user='.$participant->getUserIdentifier());
        $this->maintenanceMode->enable();

        $this->client->request(Request::METHOD_GET, '/settings/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="maintenance-banner"]');
    }
}
