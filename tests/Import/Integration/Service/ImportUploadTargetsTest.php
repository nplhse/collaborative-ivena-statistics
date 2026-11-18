<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Application\Service\ImportTypeExtensionRegistry;
use App\Import\Application\Service\ImportUploadTargets;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Import\Doubles\Service\StubImportTypeExtension;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportUploadTargetsTest extends KernelTestCase
{
    use Factories;

    private const array PARTICIPANT = ['ROLE_USER', 'ROLE_PARTICIPANT'];

    public function testParticipantWithoutAnyGrantHasNoTarget(): void
    {
        $user = $this->user(self::PARTICIPANT);
        $this->hospital();

        $targets = $this->targets();

        self::assertSame([], $targets->hospitalIdsByType($user));
        self::assertFalse($targets->canUpload($user));
    }

    public function testOwnerMayUploadToOwnHospitalOnly(): void
    {
        $owner = $this->user(self::PARTICIPANT);
        $own = $this->hospital($owner);
        $foreign = $this->hospital();

        $targets = $this->targets();

        self::assertSame([ImportType::ALLOCATION->value => [$own], ImportType::CLOSURE->value => [$own]], $targets->hospitalIdsByType($owner));
        self::assertTrue($targets->canUpload($owner, ImportType::ALLOCATION, $own));
        self::assertTrue($targets->canUpload($owner, ImportType::CLOSURE, $own));
        self::assertFalse($targets->canUpload($owner, null, $foreign));
    }

    public function testImportGrantCountsButOtherPermissionsDoNot(): void
    {
        $user = $this->user(self::PARTICIPANT);
        $withImport = $this->hospital();
        $withStatistics = $this->hospital();
        $this->grant($user, $withImport, HospitalPermission::View, HospitalPermission::Import);
        $this->grant($user, $withStatistics, HospitalPermission::View, HospitalPermission::Statistics);

        self::assertSame([ImportType::ALLOCATION->value => [$withImport], ImportType::CLOSURE->value => [$withImport]], $this->targets()->hospitalIdsByType($user));
    }

    public function testClosureListsNeedNoExtraRole(): void
    {
        // Closure lists left the beta (#1350).
        $user = $this->user(self::PARTICIPANT);
        $hospital = $this->hospital($user);

        self::assertSame(
            [ImportType::ALLOCATION->value => [$hospital], ImportType::CLOSURE->value => [$hospital]],
            $this->targets()->hospitalIdsByType($user),
        );
    }

    public function testExtensionTypeAloneGivesOnlyThatType(): void
    {
        $user = $this->user(self::PARTICIPANT);
        $hospital = $this->hospital();

        $targets = $this->targets(new StubImportTypeExtension(true, [$hospital]));

        self::assertSame([ImportType::ENCOUNTER->value => [$hospital]], $targets->hospitalIdsByType($user));
        self::assertTrue($targets->canUpload($user));
        self::assertTrue($targets->canUpload($user, ImportType::ENCOUNTER, $hospital));
        self::assertFalse($targets->canUpload($user, ImportType::ALLOCATION, $hospital));
    }

    public function testTypeAndHospitalMustMatch(): void
    {
        $user = $this->user(self::PARTICIPANT);
        $importHospital = $this->hospital($user);
        $encounterHospital = $this->hospital();

        $targets = $this->targets(new StubImportTypeExtension(true, [$encounterHospital]));

        self::assertTrue($targets->canUpload($user, null, $importHospital));
        self::assertTrue($targets->canUpload($user, null, $encounterHospital));
        self::assertFalse($targets->canUpload($user, ImportType::ALLOCATION, $encounterHospital));
        self::assertFalse($targets->canUpload($user, ImportType::ENCOUNTER, $importHospital));
    }

    public function testUnavailableExtensionIsLeftOut(): void
    {
        $user = $this->user(self::PARTICIPANT);
        $hospital = $this->hospital();

        self::assertSame([], $this->targets(new StubImportTypeExtension(false, [$hospital]))->hospitalIdsByType($user));
    }

    public function testAdminMayUploadAllocationsEverywhere(): void
    {
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN']);
        $first = $this->hospital();
        $second = $this->hospital();

        $hospitalIds = $this->targets()->hospitalIdsByType($admin)[ImportType::ALLOCATION->value] ?? [];
        sort($hospitalIds);

        self::assertSame([$first, $second], $hospitalIds);
    }

    public function testOwnerWithoutParticipantRoleHasNoTarget(): void
    {
        $owner = $this->user(['ROLE_USER']);
        $hospital = $this->hospital($owner);

        $targets = $this->targets(new StubImportTypeExtension(true, [$hospital]));

        self::assertSame([], $targets->hospitalIdsByType($owner));
    }

    private function targets(ImportTypeExtensionInterface ...$extensions): ImportUploadTargets
    {
        self::bootKernel();
        $container = self::getContainer();

        return new ImportUploadTargets(
            $container->get(HospitalPermissionAccess::class),
            new ImportTypeExtensionRegistry($extensions),
            $container->get(RoleHierarchyInterface::class),
        );
    }

    /**
     * @param list<string> $roles
     */
    private function user(array $roles): User
    {
        return UserFactory::createOne(['roles' => $roles]);
    }

    private function hospital(?User $owner = null): int
    {
        // Without an explicit owner the factory may pick the user under test.
        $hospital = HospitalFactory::createOne(['owner' => $owner ?? UserFactory::createOne(['roles' => ['ROLE_USER']])]);

        return (int) $hospital->getId();
    }

    private function grant(User $user, int $hospitalId, HospitalPermission ...$permissions): void
    {
        HospitalAccessGrantFactory::createOne([
            'hospital' => HospitalFactory::find($hospitalId),
            'user' => $user,
            'permissions' => HospitalPermissionMask::fromPermissions($permissions),
        ]);
    }
}
