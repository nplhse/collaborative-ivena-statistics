<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

final class FlagMappingStrategy
{
    /**
     * @param object $entity must expose setRequiresResus(), setRequiresCathlab(), setIsCPR(), setIsVentilated(),
     *                       setIsShock(), setIsPregnant(), setIsWithPhysician()
     * @param object $dto    must expose requiresResus, requiresCathlab, isCPR, isVentilated, isShock, isPregnant, isWithPhysician
     */
    public function apply(object $entity, object $dto, bool $defaultNullToFalse): void
    {
        $requiresResus = $defaultNullToFalse ? ($dto->requiresResus ?? false) : $dto->requiresResus;
        $requiresCathlab = $defaultNullToFalse ? ($dto->requiresCathlab ?? false) : $dto->requiresCathlab;

        $isCPR = $defaultNullToFalse ? ($dto->isCPR ?? false) : $dto->isCPR;
        $isVentilated = $defaultNullToFalse ? ($dto->isVentilated ?? false) : $dto->isVentilated;
        $isShock = $defaultNullToFalse ? ($dto->isShock ?? false) : $dto->isShock;
        $isPregnant = $defaultNullToFalse ? ($dto->isPregnant ?? false) : $dto->isPregnant;
        $isWithPhysician = $defaultNullToFalse ? ($dto->isWithPhysician ?? false) : $dto->isWithPhysician;

        $entity->setRequiresResus($requiresResus);
        $entity->setRequiresCathlab($requiresCathlab);

        $entity->setIsCPR($isCPR);
        $entity->setIsVentilated($isVentilated);
        $entity->setIsShock($isShock);
        $entity->setIsPregnant($isPregnant);
        $entity->setIsWithPhysician($isWithPhysician);
    }
}
