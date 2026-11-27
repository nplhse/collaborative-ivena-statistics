<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;

final class StatisticsFilterFactoryAccessTest extends DatabaseKernelTestCase
{
    private StatisticsFilterFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(StatisticsFilterFactory::class);
    }

    public function testInvalidCohortFallsBackToPublicForAnonymousUser(): void
    {
        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital_cohort',
                '',
                'unknown_cohort',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
    }

    public function testExplicitMyHospitalsWithoutAccessRedirectsToPublic(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'my_hospitals',
                '',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertTrue($filter->requiresPublicRedirect);
    }

    public function testParticipantWithHospitalCanUseHospitalScope(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $user]);

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                (string) $hospital->getId(),
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('hospital', $filter->scope->value);
        self::assertSame($hospital->getId(), $filter->hospitalId);
    }

    public function testAnonymousMyHospitalsScopeBecomesPublic(): void
    {
        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'my_hospitals',
                '',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                false,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
    }

    public function testAnonymousHospitalScopeBecomesPublic(): void
    {
        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                '99',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->hospitalId);
    }

    public function testHospitalScopeWithoutIdFallsBackToMyHospitalsForParticipant(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                '',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('my_hospitals', $filter->scope->value);
    }

    public function testUnauthorizedHospitalFallsBackToMyHospitalsWhenParticipantHasOwnedHospitals(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $otherOwner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);
        $foreign = HospitalFactory::createOne(['owner' => $otherOwner]);
        self::assertNotNull($foreign->getId());

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                (string) $foreign->getId(),
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('my_hospitals', $filter->scope->value);
        self::assertNull($filter->hospitalId);
    }

    public function testUnauthorizedHospitalRedirectsToPublicWithoutMyHospitalsAccess(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $otherOwner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $foreign = HospitalFactory::createOne(['owner' => $otherOwner]);
        self::assertNotNull($foreign->getId());

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital',
                (string) $foreign->getId(),
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('public', $filter->scope->value);
        self::assertTrue($filter->requiresPublicRedirect);
    }

    public function testInvalidCohortFallsBackToMyHospitalsForParticipant(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);

        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'hospital_cohort',
                '',
                'unknown_cohort',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            $user,
        );

        self::assertSame('my_hospitals', $filter->scope->value);
    }

    /**
     * Statistics of a hospital need the STATISTICS permission; VIEW alone is not enough (permission-model.md).
     */
    public function testGranteeWithStatisticsPermissionCanUseHospitalScope(): void
    {
        [$grantee, $hospitalId] = $this->granteeWith([HospitalPermission::View, HospitalPermission::Statistics]);

        $filter = $this->factory->createFromInput($this->hospitalScopeInput($hospitalId), $grantee);

        self::assertSame('hospital', $filter->scope->value);
        self::assertSame($hospitalId, $filter->hospitalId);
    }

    public function testGranteeWithOnlyViewPermissionIsRedirectedToPublic(): void
    {
        [$grantee, $hospitalId] = $this->granteeWith([HospitalPermission::View]);

        $filter = $this->factory->createFromInput($this->hospitalScopeInput($hospitalId), $grantee);

        self::assertSame('public', $filter->scope->value);
        self::assertNull($filter->hospitalId);
        self::assertTrue($filter->requiresPublicRedirect);
    }

    public function testUnknownScopeTokenFallsBackToPublic(): void
    {
        $filter = $this->factory->createFromInput(
            new StatisticsFilterInput(
                'not_a_real_scope',
                '',
                '',
                '',
                '',
                'last_12_months',
                null,
                null,
                null,
                true,
            ),
            null,
        );

        self::assertSame('public', $filter->scope->value);
    }

    /**
     * A participant without hospitals of their own, granted access to someone else's hospital.
     *
     * @param list<HospitalPermission> $permissions
     *
     * @return array{User, int}
     */
    private function granteeWith(array $permissions): array
    {
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions($permissions),
        ]);

        return [$grantee, (int) $hospital->getId()];
    }

    private function hospitalScopeInput(int $hospitalId): StatisticsFilterInput
    {
        return new StatisticsFilterInput('hospital', (string) $hospitalId, '', '', '', 'last_12_months', null, null, null, true);
    }
}
