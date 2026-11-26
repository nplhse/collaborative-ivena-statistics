<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\UploadAuthority;

use App\Allocation\Application\Hospital\UploadAuthority\UploadAuthority;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The upload authority is needed once per hospital and terms version (#1565): hospitals A and B, one stored
 * confirmation for A under version 2026-12.
 */
#[ResetDatabase]
final class UploadAuthorityTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private User $user;

    private Hospital $hospitalA;

    private Hospital $hospitalB;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->hospitalA = HospitalFactory::createOne(['name' => 'A']);
        $this->hospitalB = HospitalFactory::createOne(['name' => 'B']);
        $this->entityManager()->persist(new TermsAcceptance($this->user, TermsAcceptanceKind::UploadAuthority, '2026-12', 'de', null, new \DateTimeImmutable('2026-12-01 10:00'), $this->hospitalA));
        $this->entityManager()->flush();
    }

    public function testOnlyTheConfirmedHospitalUnderTheCurrentVersionNeedsNoConfirmation(): void
    {
        $authority = $this->authority('2026-12');

        self::assertFalse($authority->isRequired($this->user, $this->hospitalA));
        self::assertTrue($authority->isRequired($this->user, $this->hospitalB));
        self::assertSame([(int) $this->hospitalB->getId()], $authority->hospitalIdsRequiringConfirmation($this->user, [(int) $this->hospitalA->getId(), (int) $this->hospitalB->getId()]));
    }

    public function testANewTermsVersionAsksAgain(): void
    {
        self::assertTrue($this->authority('2027-03')->isRequired($this->user, $this->hospitalA));
    }

    public function testWithoutAConfiguredVersionAnyConfirmationOfTheHospitalCounts(): void
    {
        $authority = $this->authority(null);

        self::assertFalse($authority->isRequired($this->user, $this->hospitalA));
        self::assertTrue($authority->isRequired($this->user, $this->hospitalB));
    }

    public function testAdministratorsNeedNoConfirmation(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        self::assertSame([], $this->authority('2026-12')->hospitalIdsRequiringConfirmation($admin, [(int) $this->hospitalA->getId(), (int) $this->hospitalB->getId()]));
    }

    public function testConfirmStoresHospitalVersionAndTimeOrThoseOfAnEarlierConfirmation(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-12-05 08:00'));
        $authority = $this->authority('2026-12');

        $authority->confirm($this->user, $this->hospitalB, 'de');
        $authority->confirm($this->user, $this->hospitalB, 'de', new \DateTimeImmutable('2026-11-20 09:00'), '2026-11');
        $this->entityManager()->flush();
        [$now, $earlier] = $this->entityManager()->getRepository(TermsAcceptance::class)
            ->findBy(['hospital' => $this->hospitalB], ['acceptedAt' => 'DESC']);

        self::assertSame($this->hospitalB, $now->getHospital());
        self::assertSame(TermsAcceptanceKind::UploadAuthority, $now->getKind());
        self::assertSame('2026-12', $now->getTermsVersion());
        self::assertSame('2026-12-05 08:00', $now->getAcceptedAt()->format('Y-m-d H:i'));
        self::assertSame('2026-11', $earlier->getTermsVersion());
        self::assertSame('2026-11-20 09:00', $earlier->getAcceptedAt()->format('Y-m-d H:i'));
    }

    public function testAnEmptyHospitalListNeedsNoQuery(): void
    {
        $repository = self::getContainer()->get(TermsAcceptanceRepository::class);
        self::assertInstanceOf(TermsAcceptanceRepository::class, $repository);

        self::assertSame([], $repository->hospitalIdsWithUploadAuthority($this->user, [], '2026-12'));
    }

    private function authority(?string $version): UploadAuthority
    {
        self::getContainer()->set(TermsVersion::class, new TermsVersion($version));
        $authority = self::getContainer()->get(UploadAuthority::class);
        self::assertInstanceOf(UploadAuthority::class, $authority);

        return $authority;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
