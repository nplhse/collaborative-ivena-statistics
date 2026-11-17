<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Infrastructure\Security;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Infrastructure\Security\Voter\ImportCreateVoter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportCreateVoterTest extends KernelTestCase
{
    use Factories;

    private ImportCreateVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->voter = self::getContainer()->get(ImportCreateVoter::class);
    }

    public function testGrantedWithoutSubjectForHospitalOwner(): void
    {
        $owner = $this->participant();
        HospitalFactory::createOne(['owner' => $owner]);

        self::assertSame(Voter::ACCESS_GRANTED, $this->voter->vote($this->token($owner), null, [ImportCreateVoter::CREATE]));
    }

    public function testDeniedWithoutSubjectForParticipantWithoutUploadRight(): void
    {
        $user = $this->participant();
        $this->foreignHospital();

        self::assertSame(Voter::ACCESS_DENIED, $this->voter->vote($this->token($user), null, [ImportCreateVoter::CREATE]));
    }

    public function testHospitalSubjectChecksThatHospital(): void
    {
        $owner = $this->participant();
        $own = HospitalFactory::createOne(['owner' => $owner]);
        $foreign = $this->foreignHospital();

        self::assertSame(Voter::ACCESS_GRANTED, $this->voter->vote($this->token($owner), $own, [ImportCreateVoter::CREATE]));
        self::assertSame(Voter::ACCESS_DENIED, $this->voter->vote($this->token($owner), $foreign, [ImportCreateVoter::CREATE]));
    }

    public function testDeniedForAnonymousUser(): void
    {
        self::assertSame(Voter::ACCESS_DENIED, $this->voter->vote(new NullToken(), null, [ImportCreateVoter::CREATE]));
    }

    public function testAbstainsOnOtherAttributesAndSubjects(): void
    {
        $owner = $this->participant();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        self::assertSame(Voter::ACCESS_ABSTAIN, $this->voter->vote($this->token($owner), $hospital, ['VIEW']));
        self::assertSame(Voter::ACCESS_ABSTAIN, $this->voter->vote($this->token($owner), $owner, [ImportCreateVoter::CREATE]));
    }

    private function participant(): User
    {
        return UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
    }

    /**
     * Without an explicit owner the factory may pick the user under test.
     */
    private function foreignHospital(): Hospital
    {
        return HospitalFactory::createOne(['owner' => UserFactory::createOne(['roles' => ['ROLE_USER']])]);
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
