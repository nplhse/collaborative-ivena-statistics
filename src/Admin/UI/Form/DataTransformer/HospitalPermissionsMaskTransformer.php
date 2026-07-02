<?php

declare(strict_types=1);

namespace App\Admin\UI\Form\DataTransformer;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * @implements DataTransformerInterface<int|null, list<HospitalPermission>>
 */
final class HospitalPermissionsMaskTransformer implements DataTransformerInterface
{
    /**
     * @return list<HospitalPermission>
     */
    #[\Override]
    public function transform(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (!\is_int($value)) {
            throw new TransformationFailedException('Expected an integer permission mask.');
        }

        $permissions = [];
        foreach (HospitalPermission::assignableCases() as $permission) {
            if (HospitalPermissionMask::has($value, $permission)) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * @param list<HospitalPermission>|mixed $value
     */
    #[\Override]
    public function reverseTransform(mixed $value): int
    {
        if (!\is_array($value)) {
            return 0;
        }

        $permissions = array_values(array_filter(
            $value,
            static fn (mixed $permission): bool => $permission instanceof HospitalPermission,
        ));

        return HospitalPermissionMask::fromPermissions($permissions);
    }
}
