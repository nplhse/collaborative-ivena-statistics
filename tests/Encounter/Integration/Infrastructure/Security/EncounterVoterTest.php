<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Security;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Infrastructure\Security\Voter\EncounterVoter;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Access matrix of ADR 028. Each case names the user's relation to the hospital explicitly.
 * Administrators hold every grant action implicitly and may change the key.
 */
#[ResetDatabase]
final class EncounterVoterTest extends KernelTestCase
{
    use EncounterTestFixtures;
    use Factories;

    private EncounterVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->voter = self::getContainer()->get(EncounterVoter::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function caseLevelAttributes(): iterable
    {
        yield 'view cases' => [EncounterAttribute::VIEW_CASES];
        yield 'review' => [EncounterAttribute::REVIEW];
        yield 'upload' => [EncounterAttribute::UPLOAD];
        yield 'view source file' => [EncounterAttribute::VIEW_SOURCE_FILE];
        yield 'download source file' => [EncounterAttribute::DOWNLOAD_SOURCE_FILE];
        yield 'aggregates' => [EncounterAttribute::VIEW_AGGREGATES];
    }

    #[DataProvider('caseLevelAttributes')]
    public function testOwnerWithoutGrantIsDenied(string $attribute): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($owner, $hospital, $attribute));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminAttributes(): iterable
    {
        yield from self::caseLevelAttributes();
        yield 'reprocess source file' => [EncounterAttribute::REPROCESS_SOURCE_FILE];
        yield 'delete import' => [EncounterAttribute::DELETE_IMPORT];
        yield 'manage grants' => [EncounterAttribute::MANAGE_GRANTS];
    }

    #[DataProvider('adminAttributes')]
    public function testAdminIsGrantedWithoutStoredGrant(string $attribute): void
    {
        $hospital = $this->hospital($this->user());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->admin(), $hospital, $attribute));
    }

    public function testImpersonatingAdminHasOnlyTheImpersonatedUsersRights(): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $member = $this->user();
        $this->hospitalGrant($hospital, $member);
        $this->encounterGrant($hospital, $member, [EncounterGrantAction::ViewCases]);
        $admin = $this->admin();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voteAs($owner, $admin, $hospital, EncounterAttribute::VIEW_CASES));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteAs($member, $admin, $hospital, EncounterAttribute::VIEW_CASES));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voteAs($member, $admin, $hospital, EncounterAttribute::DOWNLOAD_SOURCE_FILE));
    }

    #[DataProvider('caseLevelAttributes')]
    public function testHospitalGrantWithoutEncounterGrantIsDenied(string $attribute): void
    {
        $hospital = $this->hospital($this->user());
        $member = $this->user();
        $this->hospitalGrant($hospital, $member);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, $attribute));
    }

    public function testViewCasesOnHospitalADoesNotOpenHospitalB(): void
    {
        $hospitalA = $this->hospital($this->user());
        $hospitalB = $this->hospital($this->user());
        $member = $this->user();
        $this->hospitalGrant($hospitalA, $member);
        $this->encounterGrant($hospitalA, $member, [EncounterGrantAction::ViewCases]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($member, $hospitalA, EncounterAttribute::VIEW_CASES));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospitalB, EncounterAttribute::VIEW_CASES));
    }

    public function testGrantWithoutBetaRoleIsDenied(): void
    {
        $hospital = $this->hospital($this->user());
        $member = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $this->hospitalGrant($hospital, $member);
        $this->encounterGrant($hospital, $member, [EncounterGrantAction::ViewCases, EncounterGrantAction::Upload]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, EncounterAttribute::VIEW_CASES));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, EncounterAttribute::UPLOAD));
    }

    public function testReviewGrantAllowsViewingCases(): void
    {
        $hospital = $this->hospital($this->user());
        $member = $this->user();
        $this->hospitalGrant($hospital, $member);
        $this->encounterGrant($hospital, $member, [EncounterGrantAction::Review]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($member, $hospital, EncounterAttribute::REVIEW));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($member, $hospital, EncounterAttribute::VIEW_CASES));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, EncounterAttribute::UPLOAD));
    }

    public function testUploadGrantDoesNotAllowCasesOrKeyChange(): void
    {
        $hospital = $this->hospital($this->user());
        $uploader = $this->user();
        $this->hospitalGrant($hospital, $uploader);
        $this->encounterGrant($hospital, $uploader, [EncounterGrantAction::Upload]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($uploader, $hospital, EncounterAttribute::UPLOAD));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($uploader, $hospital, EncounterAttribute::DOWNLOAD_SOURCE_FILE));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($uploader, $hospital, EncounterAttribute::REPROCESS_SOURCE_FILE));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($uploader, $hospital, EncounterAttribute::DELETE_IMPORT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($uploader, $hospital, EncounterAttribute::VIEW_CASES));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($uploader, $hospital, EncounterAttribute::CHANGE_KEY));
    }

    public function testAdminMayChangeKeyWithoutGrantOrOwnership(): void
    {
        $hospital = $this->hospital($this->user());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->admin(), $hospital, EncounterAttribute::CHANGE_KEY));
    }

    public function testAllGrantActionsDoNotAllowKeyChange(): void
    {
        $hospital = $this->hospital($this->user());
        $member = $this->user();
        $this->hospitalGrant($hospital, $member);
        $this->encounterGrant($hospital, $member, EncounterGrantAction::cases());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, EncounterAttribute::CHANGE_KEY));
    }

    public function testOwnerWithBetaRoleMayChangeKey(): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $ownerWithoutBeta = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $otherHospital = $this->hospital($ownerWithoutBeta);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($owner, $hospital, EncounterAttribute::CHANGE_KEY));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($ownerWithoutBeta, $otherHospital, EncounterAttribute::CHANGE_KEY));
    }

    public function testViewCasesGrantDoesNotAllowReprocessing(): void
    {
        $hospital = $this->hospital($this->user());
        $member = $this->user();
        $this->hospitalGrant($hospital, $member);
        $this->encounterGrant($hospital, $member, [EncounterGrantAction::ViewCases]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, EncounterAttribute::REPROCESS_SOURCE_FILE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, EncounterAttribute::DELETE_IMPORT));
    }

    public function testOwnerWithoutGrantMayNotDeleteImports(): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($owner, $hospital, EncounterAttribute::DELETE_IMPORT));
    }

    public function testAggregatesNeedAnyGrantAndStatisticsPermission(): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $member = $this->user();
        $this->hospitalGrant($hospital, $member); // VIEW only, no STATISTICS
        $this->encounterGrant($hospital, $member, [EncounterGrantAction::Upload]);
        $this->encounterGrant($hospital, $owner, [EncounterGrantAction::Upload]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, EncounterAttribute::VIEW_AGGREGATES));
        // Owners hold every hospital permission (ADR 002), so the encounter grant is the deciding part.
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($owner, $hospital, EncounterAttribute::VIEW_AGGREGATES));
    }

    public function testGrantManagementIsForOwnersAndAdmins(): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $member = $this->user();
        $this->hospitalGrant($hospital, $member);
        $this->encounterGrant($hospital, $member, EncounterGrantAction::cases());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($owner, $hospital, EncounterAttribute::MANAGE_GRANTS));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->admin(), $hospital, EncounterAttribute::MANAGE_GRANTS));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($member, $hospital, EncounterAttribute::MANAGE_GRANTS));
    }

    public function testUnrelatedAttributesAreAbstained(): void
    {
        $hospital = $this->hospital($this->user());

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->admin(), $hospital, 'EDIT'));
    }

    private function vote(User $user, Hospital $hospital, string $attribute): int
    {
        return $this->voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $hospital, [$attribute]);
    }

    private function voteAs(User $impersonated, User $admin, Hospital $hospital, string $attribute): int
    {
        $original = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        $token = new SwitchUserToken($impersonated, 'main', $impersonated->getRoles(), $original);

        return $this->voter->vote($token, $hospital, [$attribute]);
    }
}
