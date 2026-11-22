<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Functional\Controller;

use App\Onboarding\Domain\Entity\WhatsNewRelease;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The "What's new for you" card on the dashboard (#1485). Now is 2026-11-22 10:00; the update was installed on
 * 2026-11-21, so the card is within its display window.
 */
#[ResetDatabase]
final class DismissWhatsNewControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime('2026-11-22 10:00:00');
    }

    public function testAccountOlderThanTheUpdateSeesAndHidesTheCard(): void
    {
        $client = self::createClient();
        $this->installReleaseYesterday();
        $user = $this->userCreated('2026-10-01 08:00:00', ['displayName' => 'Erika Muster']);
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="whats-new-item-display_name"]', 'Your display name: Erika Muster');
        self::assertSelectorExists('[data-testid="whats-new-item-settings_overview"] a[href="/settings"]');

        $client->submit($crawler->filter('[data-testid="whats-new-dismiss"]')->form());

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorNotExists('[data-testid="dashboard-whats-new-card"]');
    }

    public function testAccountCreatedAfterTheUpdateDoesNotSeeTheCard(): void
    {
        $client = self::createClient();
        $this->installReleaseYesterday();
        $client->loginUser($this->userCreated('2026-11-21 12:00:00'));

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="dashboard-whats-new-card"]');
    }

    public function testDismissNeedsAValidToken(): void
    {
        $client = self::createClient();
        $this->installReleaseYesterday();
        $client->loginUser($this->userCreated('2026-10-01 08:00:00'));

        $client->request(Request::METHOD_POST, '/whats-new/v0.3/dismiss', ['_token' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function userCreated(string $createdAt, array $attributes = []): User
    {
        $user = UserFactory::createOne($attributes);
        $user->setCreatedAt(new \DateTimeImmutable($createdAt));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private function installReleaseYesterday(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $release = $entityManager->find(WhatsNewRelease::class, 'v0.3');
        if ($release instanceof WhatsNewRelease) {
            $release->setInstalledAt(new \DateTimeImmutable('2026-11-21 00:00:00'));
        } else {
            $entityManager->persist(new WhatsNewRelease('v0.3', new \DateTimeImmutable('2026-11-21 00:00:00')));
        }

        $entityManager->flush();
    }
}
