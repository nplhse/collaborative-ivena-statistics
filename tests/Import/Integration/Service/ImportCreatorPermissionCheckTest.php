<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Service\ImportCreatorPermissionCheck;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;

final class ImportCreatorPermissionCheckTest extends DatabaseKernelTestCase
{
    private ImportCreatorPermissionCheck $check;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->check = self::getContainer()->get(ImportCreatorPermissionCheck::class);
    }

    public function testImportWithoutCreatorIsRejected(): void
    {
        self::assertSame(ImportRunReasonCode::MissingCreator, $this->check->failureFor(new Import()));
    }

    public function testImportWithoutHospitalIsRejected(): void
    {
        $import = new Import()->setCreatedBy($this->participant());

        self::assertSame(ImportRunReasonCode::MissingHospital, $this->check->failureFor($import));
    }

    public function testHospitalOwnerMayProcess(): void
    {
        $owner = $this->participant();

        self::assertNull($this->check->failureFor($this->import($owner, $this->hospital($owner))));
    }

    public function testCreatorWithoutImportPermissionIsRejected(): void
    {
        $creator = $this->participant();

        self::assertSame(
            ImportRunReasonCode::MissingImportPermission,
            $this->check->failureFor($this->import($creator, $this->hospital($this->participant()))),
        );
    }

    public function testCreatorWithImportGrantMayProcess(): void
    {
        $owner = $this->participant();
        $creator = $this->participant();
        $hospital = $this->hospital($owner);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $creator,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        self::assertNull($this->check->failureFor($this->import($creator, $hospital)));
    }

    public function testClosureImportNeedsNoExtraRole(): void
    {
        // Closure imports left the beta (#1350): the import permission is enough, as for allocations.
        $owner = $this->participant();
        $import = $this->import($owner, $this->hospital($owner), ImportType::CLOSURE);

        self::assertNull($this->check->failureFor($import));
    }

    private function participant(): User
    {
        return UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
    }

    private function hospital(User $owner): Hospital
    {
        $state = StateFactory::createOne();

        return HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);
    }

    private function import(User $creator, Hospital $hospital, ImportType $type = ImportType::ALLOCATION): Import
    {
        return new Import()
            ->setCreatedBy($creator)
            ->setHospital($hospital)
            ->setType($type);
    }
}
