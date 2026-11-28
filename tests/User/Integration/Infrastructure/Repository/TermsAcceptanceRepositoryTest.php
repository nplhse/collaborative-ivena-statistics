<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hospitals whose upload authority is renewed together with a new terms version (#1565). The page that offers the
 * renewal is in `TermsAcceptanceAtLoginTest`.
 */
final class TermsAcceptanceRepositoryTest extends DatabaseKernelTestCase
{
    public function testOnlyHospitalsConfirmedUnderAnEarlierVersionAndNotYetUnderThisOneAreRenewed(): void
    {
        $alice = UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test']);
        $bob = UserFactory::createOne(['username' => 'bob', 'email' => 'bob@example.test']);
        $north = HospitalFactory::createOne(['name' => 'Klinikum Nord']);
        $south = HospitalFactory::createOne(['name' => 'Klinikum Süd']);
        $east = HospitalFactory::createOne(['name' => 'Klinikum Ost']);
        $west = HospitalFactory::createOne(['name' => 'Klinikum West']);
        $centre = HospitalFactory::createOne(['name' => 'Klinikum Mitte']);
        $this->confirm($alice, $south, '2026-11');
        $this->confirm($alice, $north, '2026-10');
        // Already renewed under 2026-12.
        $this->confirm($alice, $east, '2026-11');
        $this->confirm($alice, $east, '2026-12');
        // Confirmed under 2026-12 only: nothing to renew.
        $this->confirm($alice, $west, '2026-12');
        // Bob's confirmation does not count for Alice.
        $this->confirm($bob, $centre, '2026-11');

        $hospitals = self::getContainer()->get(TermsAcceptanceRepository::class)->hospitalsWithUploadAuthorityToRenew($alice, '2026-12');

        // Sorted by name.
        self::assertSame(['Klinikum Nord', 'Klinikum Süd'], array_map(static fn (Hospital $hospital): ?string => $hospital->getName(), $hospitals));
    }

    public function testAnAccountWithoutUploadAuthorityHasNothingToRenew(): void
    {
        $alice = UserFactory::createOne(['username' => 'alice', 'email' => 'alice@example.test']);

        $hospitals = self::getContainer()->get(TermsAcceptanceRepository::class)->hospitalsWithUploadAuthorityToRenew($alice, '2026-12');

        self::assertSame([], $hospitals);
    }

    private function confirm(User $user, Hospital $hospital, string $version): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TermsAcceptance($user, TermsAcceptanceKind::UploadAuthority, $version, 'en', null, null, $hospital));
        $entityManager->flush();
    }
}
