<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\DataFixtures\Reference\ReferenceDataLoader;
use App\Import\Infrastructure\Indication\IndicationKey;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ReferenceDataLoaderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function loadAreasPersistsStatesAndDispatchAreasWithRelations(): void
    {
        self::bootKernel();
        $loader = $this->loader();
        $user = UserFactory::createOne();

        $loader->loadAreas($user->_real());
        $this->entityManager()->flush();

        $states = $this->entityManager()->getRepository(State::class)->findBy([], ['name' => 'ASC']);
        $areas = $this->entityManager()->getRepository(DispatchArea::class)->findBy([], ['name' => 'ASC']);

        self::assertNotEmpty($states);
        self::assertCount(25, $areas);

        $hessen = $this->entityManager()->getRepository(State::class)->findOneBy(['name' => 'Hessen']);
        self::assertInstanceOf(State::class, $hessen);

        $frankfurt = $this->entityManager()->getRepository(DispatchArea::class)->findOneBy(['name' => 'Frankfurt']);
        self::assertInstanceOf(DispatchArea::class, $frankfurt);
        self::assertInstanceOf(State::class, $frankfurt->getState());
        self::assertSame('Hessen', $frankfurt->getState()->getName());
        self::assertSame($user->_real(), $frankfurt->getCreatedBy());
    }

    #[Test]
    public function loadHospitalsCreatesFirstHospitalWithExpectedFields(): void
    {
        self::bootKernel();
        $loader = $this->loader();
        $user = UserFactory::createOne();

        $loader->loadAreas($user->_real());
        $loader->loadHospitals($user->_real(), all: true);
        $this->entityManager()->flush();

        /** @var Hospital|null $first */
        $first = $this->entityManager()->getRepository(Hospital::class)->findOneBy(
            ['name' => 'Agaplesion Bethanien Krankenhaus'],
        );

        self::assertInstanceOf(Hospital::class, $first);
        self::assertSame(204, $first->getBeds());
        self::assertSame(HospitalTier::BASIC, $first->getTier());
        self::assertSame(HospitalSize::MEDIUM, $first->getSize());
        self::assertSame(HospitalLocation::URBAN, $first->getLocation());
        self::assertNull($first->getOwner());
        self::assertSame($user->_real(), $first->getCreatedBy());
        self::assertInstanceOf(State::class, $first->getState());
        self::assertInstanceOf(DispatchArea::class, $first->getDispatchArea());

        $address = $first->getAddress();
        self::assertSame('Im Prüfling 21-25', $address->getStreet());
        self::assertSame('Frankfurt am Main', $address->getCity());
        self::assertSame('Hessen', $address->getState());
        self::assertSame('60389', $address->getPostalCode());
        self::assertSame('Deutschland', $address->getCountry());
    }

    #[Test]
    public function loadHospitalsThrowsWhenAreasAreMissing(): void
    {
        self::bootKernel();
        $loader = $this->loader();
        $user = UserFactory::createOne();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown dispatch area reference: Hessen / Frankfurt');

        $loader->loadHospitals($user->_real(), all: true);
    }

    #[Test]
    public function loadIndicationsLinksRawEntriesToNormalized(): void
    {
        self::bootKernel();
        $loader = $this->loader();
        $user = UserFactory::createOne();

        $loader->loadIndications($user->_real());

        $normalizedCount = $this->entityManager()
            ->getRepository(IndicationNormalized::class)
            ->count([]);
        $rawCount = $this->entityManager()
            ->getRepository(IndicationRaw::class)
            ->count([]);

        self::assertSame(210, $normalizedCount);
        self::assertGreaterThanOrEqual(210, $rawCount);

        /** @var IndicationNormalized|null $sample */
        $sample = $this->entityManager()->getRepository(IndicationNormalized::class)->findOneBy(['code' => 0]);
        self::assertInstanceOf(IndicationNormalized::class, $sample);

        $hash = IndicationKey::hashFrom((string) $sample->getCode(), $sample->getName());
        /** @var IndicationRaw|null $raw */
        $raw = $this->entityManager()->getRepository(IndicationRaw::class)->findOneBy(['hash' => $hash]);

        self::assertNotNull($raw);
        self::assertSame($sample->getId(), $raw->getTarget()?->getId());
        self::assertSame($sample->getId(), $raw->getNormalized()?->getId());
    }

    #[Test]
    public function loadIndicationsFixesOrphanedRawEntriesWithMatchingHash(): void
    {
        self::bootKernel();
        $loader = $this->loader();
        $user = UserFactory::createOne();

        $normalized = IndicationNormalizedFactory::createOne([
            'code' => 301,
            'name' => 'Appendizitis',
            'createdBy' => $user,
        ]);

        $hash = IndicationKey::hashFrom((string) $normalized->getCode(), $normalized->getName());
        $raw = IndicationRawFactory::createOne([
            'code' => $normalized->getCode(),
            'name' => $normalized->getName(),
            'hash' => $hash,
            'createdBy' => $user,
        ]);

        self::assertNull($raw->getTarget());
        self::assertNull($raw->getNormalized());

        $loader->loadIndications($user->_real());

        /** @var IndicationRaw|null $reloaded */
        $reloaded = $this->entityManager()->getRepository(IndicationRaw::class)->findOneBy(['hash' => $hash]);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getTarget());
        self::assertNotNull($reloaded->getNormalized());
        self::assertSame($normalized->getId(), $reloaded->getTarget()->getId());
        self::assertSame($normalized->getId(), $reloaded->getNormalized()->getId());
    }

    private function loader(): ReferenceDataLoader
    {
        /** @var ReferenceDataLoader $loader */
        $loader = self::getContainer()->get(ReferenceDataLoader::class);

        return $loader;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
