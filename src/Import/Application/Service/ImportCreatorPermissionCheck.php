<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;

/**
 * An import is always processed as its creator. This check tells whether the creator may still import
 * into the import's hospital, so the message handlers and the reprocess action decide the same way.
 */
final readonly class ImportCreatorPermissionCheck
{
    public function __construct(
        private HospitalPermissionAccess $hospitalPermissionAccess,
    ) {
    }

    /**
     * @return ImportRunReasonCode|null why the creator may not process the import, or null if they may
     */
    public function failureFor(Import $import): ?ImportRunReasonCode
    {
        $createdBy = $import->getCreatedBy();
        if (!$createdBy instanceof User) {
            return ImportRunReasonCode::MissingCreator;
        }

        if (ImportType::CLOSURE === $import->getType() && !\in_array(UserRole::CLOSURE_BETA, $createdBy->getRoles(), true)) {
            return ImportRunReasonCode::MissingClosureRole;
        }

        $hospitalId = $import->getHospital()?->getId();
        if (null === $hospitalId) {
            return ImportRunReasonCode::MissingHospital;
        }

        if (!$this->hospitalPermissionAccess->hasPermission($createdBy, $hospitalId, HospitalPermission::Import)) {
            return ImportRunReasonCode::MissingImportPermission;
        }

        return null;
    }
}
