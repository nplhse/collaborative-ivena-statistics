<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Integration\Application\WhatsNew;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Onboarding\Application\Dto\WhatsNewItemView;
use App\Onboarding\Application\WhatsNew\WhatsNewService;
use App\Onboarding\Domain\Entity\WhatsNewRelease;
use App\Onboarding\Domain\Enum\WhatsNewItemKey;
use App\Onboarding\Infrastructure\Repository\UserWhatsNewDismissalRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The release v0.3 is installed on 2026-11-01 00:00; the card is shown for 60 days, until 2026-12-31 00:00.
 */
final class WhatsNewServiceTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private const string INSTALLED_AT = '2026-11-01 00:00:00';

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime('2026-11-22 10:00:00');
        self::bootKernel();
        $this->installRelease('v0.3', self::INSTALLED_AT);
    }

    public function testAccountOlderThanTheUpdateSeesTheCard(): void
    {
        $card = $this->service()->buildCardForUser($this->userCreatedAt('2026-10-31 23:59:59'));

        self::assertNotNull($card);
        self::assertSame('v0.3', $card->releaseKey);
        self::assertSame([WhatsNewItemKey::DisplayName, WhatsNewItemKey::SettingsOverview], $this->itemKeys($card->items));
    }

    public function testAccountCreatedAtOrAfterTheUpdateDoesNotSeeTheCard(): void
    {
        self::assertNull($this->service()->buildCardForUser($this->userCreatedAt(self::INSTALLED_AT)));
        self::assertNull($this->service()->buildCardForUser($this->userCreatedAt('2026-11-15 08:00:00')));
    }

    public function testCardEndsSixtyDaysAfterTheUpdate(): void
    {
        $user = $this->userCreatedAt('2026-01-01 00:00:00');

        self::mockTime('2026-12-30 23:59:59');
        self::assertNotNull($this->service()->buildCardForUser($user));

        self::mockTime('2026-12-31 00:00:00');
        self::assertNull($this->service()->buildCardForUser($user));
    }

    public function testNoCardWithoutTheReleaseRow(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($entityManager->find(WhatsNewRelease::class, 'v0.3'));
        $entityManager->flush();

        self::assertNull($this->service()->buildCardForUser($this->userCreatedAt('2026-01-01 00:00:00')));
    }

    public function testDismissedCardStaysHidden(): void
    {
        $user = $this->userCreatedAt('2026-01-01 00:00:00');

        $this->service()->dismiss($user, 'v0.3');
        $this->service()->dismiss($user, 'v0.3');

        self::assertNull($this->service()->buildCardForUser($user));
        self::assertTrue(self::getContainer()->get(UserWhatsNewDismissalRepository::class)->hasDismissed($user, 'v0.3'));
    }

    public function testUnknownReleaseCannotBeDismissed(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->service()->dismiss($this->userCreatedAt('2026-01-01 00:00:00'), 'v9.9');
    }

    public function testDisplayNameItemNamesTheNameThatIsSet(): void
    {
        $user = $this->userCreatedAt('2026-01-01 00:00:00', ['displayName' => 'Dr. Erika Muster']);

        $item = $this->service()->buildCardForUser($user)?->items[0];

        self::assertNotNull($item);
        self::assertSame('whats_new.items.display_name.set.title', $item->title->getMessage());
        self::assertSame(['name' => 'Dr. Erika Muster'], $item->title->getParameters());
        self::assertSame('/settings/account', $item->actionUrl);
    }

    public function testDisplayNameItemAsksForANameWhenThereIsNone(): void
    {
        $item = $this->service()->buildCardForUser($this->userCreatedAt('2026-01-01 00:00:00'))?->items[0];

        self::assertSame('whats_new.items.display_name.missing.title', $item?->title->getMessage());
    }

    public function testMyHospitalsItemNeedsClinicAccess(): void
    {
        $user = $this->userCreatedAt('2026-01-01 00:00:00', ['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        HospitalFactory::createOne(['owner' => $user]);

        $card = $this->service()->buildCardForUser($user);

        self::assertSame(
            [WhatsNewItemKey::DisplayName, WhatsNewItemKey::MyHospitals, WhatsNewItemKey::SettingsOverview],
            $this->itemKeys($card->items ?? []),
        );
    }

    private function service(): WhatsNewService
    {
        return self::getContainer()->get(WhatsNewService::class);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function userCreatedAt(string $createdAt, array $attributes = []): User
    {
        $user = UserFactory::createOne($attributes);
        $user->setCreatedAt(new \DateTimeImmutable($createdAt));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private function installRelease(string $releaseKey, string $installedAt): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $release = $entityManager->find(WhatsNewRelease::class, $releaseKey);
        if ($release instanceof WhatsNewRelease) {
            $release->setInstalledAt(new \DateTimeImmutable($installedAt));
        } else {
            $entityManager->persist(new WhatsNewRelease($releaseKey, new \DateTimeImmutable($installedAt)));
        }

        $entityManager->flush();
    }

    /**
     * @param list<WhatsNewItemView> $items
     *
     * @return list<WhatsNewItemKey>
     */
    private function itemKeys(array $items): array
    {
        return array_map(static fn (WhatsNewItemView $item): WhatsNewItemKey => $item->key, $items);
    }
}
