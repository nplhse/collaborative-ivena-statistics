<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Security\Voter;

use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Security\Voter\IndicationRawReviewVoter;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationRawReviewVoterTest extends KernelTestCase
{
    use Factories;

    private IndicationRawReviewVoter $voter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->voter = new IndicationRawReviewVoter(self::getContainer()->get(RoleHierarchyInterface::class));
    }

    public function testParticipantCanView(): void
    {
        $user = UserFactory::new()->withoutPersisting()->create(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, [IndicationRawReviewVoter::VIEW]));
    }

    public function testAdminWithoutExplicitParticipantRoleCanView(): void
    {
        $user = UserFactory::new()->withoutPersisting()->create(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, [IndicationRawReviewVoter::VIEW]));
    }

    public function testAdminWithoutExplicitReviewerRolesCanEditAndReview(): void
    {
        $user = UserFactory::new()->withoutPersisting()->create(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, [IndicationRawReviewVoter::EDIT_MATCH]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, [IndicationRawReviewVoter::REVIEW]));
    }

    public function testParticipantWithoutReviewRoleCannotEdit(): void
    {
        $user = UserFactory::new()->withoutPersisting()->create(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, null, [IndicationRawReviewVoter::EDIT_MATCH]));
    }

    public function testReviewerWithBothRolesCanEdit(): void
    {
        $user = UserFactory::new()->withoutPersisting()->create([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, [IndicationRawReviewVoter::EDIT_MATCH]));
    }

    public function testFirstMatcherCannotReview(): void
    {
        $matcher = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS]]);
        $raw = IndicationRawFactory::createOne(['firstMatchedBy' => $matcher]);
        $token = new UsernamePasswordToken($matcher, 'main', $matcher->getRoles());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $raw, [IndicationRawReviewVoter::REVIEW]));
    }

    public function testAdminCanReviewOwnProposal(): void
    {
        $admin = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        $raw = IndicationRawFactory::createOne(['firstMatchedBy' => $admin]);
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $raw, [IndicationRawReviewVoter::REVIEW]));
    }
}
