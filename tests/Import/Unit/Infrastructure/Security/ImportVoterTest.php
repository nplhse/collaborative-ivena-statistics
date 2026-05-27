<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Security;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ImportVoterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private ImportVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->voter = self::getContainer()->get(ImportVoter::class);
    }

    public function testViewGrantedForHospitalOwner(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner->_real()), $import->_real(), [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedForForeignParticipant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder->_real()), $import->_real(), [ImportVoter::VIEW]),
        );
    }

    private function createImportForOwner(object $owner): object
    {
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => '/tmp/test.csv',
        ]);
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
