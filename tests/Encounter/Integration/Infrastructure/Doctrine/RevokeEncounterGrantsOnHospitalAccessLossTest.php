<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Doctrine;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RevokeEncounterGrantsOnHospitalAccessLossTest extends KernelTestCase
{
    use EncounterTestFixtures;
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDeletingHospitalGrantRemovesEncounterGrantAndAuditsIt(): void
    {
        $hospital = $this->hospital($this->user());
        $member = $this->user();
        $hospitalGrant = $this->hospitalGrant($hospital, $member);
        $encounterGrantId = $this->encounterGrant($hospital, $member, [EncounterGrantAction::ViewCases])->getId();

        $this->removeHospitalGrant((int) $hospitalGrant->getId());

        self::assertNull($this->findEncounterGrant($hospital, $member));
        self::assertNotNull($this->entityManager()->getRepository(AuditEntry::class)->findOneBy([
            'entityClass' => EncounterAccessGrant::class,
            'entityId' => (string) $encounterGrantId,
            'action' => 'delete',
        ]));
    }

    public function testOtherUsersAndHospitalsKeepTheirGrants(): void
    {
        $hospitalA = $this->hospital($this->user());
        $hospitalB = $this->hospital($this->user());
        $member = $this->user();
        $colleague = $this->user();
        $grantA = $this->hospitalGrant($hospitalA, $member);
        $this->hospitalGrant($hospitalB, $member);
        $this->hospitalGrant($hospitalA, $colleague);
        $this->encounterGrant($hospitalA, $member, [EncounterGrantAction::ViewCases]);
        $this->encounterGrant($hospitalB, $member, [EncounterGrantAction::ViewCases]);
        $this->encounterGrant($hospitalA, $colleague, [EncounterGrantAction::ViewCases]);

        $this->removeHospitalGrant((int) $grantA->getId());

        self::assertNull($this->findEncounterGrant($hospitalA, $member));
        self::assertNotNull($this->findEncounterGrant($hospitalB, $member));
        self::assertNotNull($this->findEncounterGrant($hospitalA, $colleague));
    }

    public function testOwnerKeepsEncounterGrantWhenTheirHospitalGrantIsRemoved(): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $hospitalGrant = $this->hospitalGrant($hospital, $owner);
        $this->encounterGrant($hospital, $owner, [EncounterGrantAction::Upload]);

        $this->removeHospitalGrant((int) $hospitalGrant->getId());

        self::assertNotNull($this->findEncounterGrant($hospital, $owner));
    }

    public function testOwnerChangeRemovesEncounterGrantOfPreviousOwner(): void
    {
        $previousOwner = $this->user();
        $hospital = $this->hospital($previousOwner);
        $this->encounterGrant($hospital, $previousOwner, [EncounterGrantAction::Upload]);

        $this->changeOwner($hospital, $this->user());

        self::assertNull($this->findEncounterGrant($hospital, $previousOwner));
    }

    public function testPreviousOwnerWithHospitalGrantKeepsEncounterGrant(): void
    {
        $previousOwner = $this->user();
        $hospital = $this->hospital($previousOwner);
        $this->hospitalGrant($hospital, $previousOwner);
        $this->encounterGrant($hospital, $previousOwner, [EncounterGrantAction::Upload]);

        $this->changeOwner($hospital, $this->user());

        self::assertNotNull($this->findEncounterGrant($hospital, $previousOwner));
    }

    public function testDeletingHospitalRemovesItsEncounterGrants(): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $this->encounterGrant($hospital, $owner, [EncounterGrantAction::Upload]);
        $hospitalId = (int) $hospital->getId();

        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement('DELETE FROM hospital_access_grant WHERE hospital_id = ?', [$hospitalId]);
        $connection->executeStatement('DELETE FROM hospital WHERE id = ?', [$hospitalId]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM encounter_access_grant WHERE hospital_id = ?', [$hospitalId]));
    }

    private function removeHospitalGrant(int $grantId): void
    {
        $em = $this->entityManager();
        $em->clear();
        $grant = $em->find(HospitalAccessGrant::class, $grantId);
        self::assertInstanceOf(HospitalAccessGrant::class, $grant);
        $em->remove($grant);
        $em->flush();
    }

    private function changeOwner(Hospital $hospital, User $newOwner): void
    {
        $em = $this->entityManager();
        $em->clear();
        $managed = $em->find(Hospital::class, $hospital->getId());
        self::assertInstanceOf(Hospital::class, $managed);
        $managed->setOwner($em->getReference(User::class, (int) $newOwner->getId()));
        $em->flush();
    }
}
