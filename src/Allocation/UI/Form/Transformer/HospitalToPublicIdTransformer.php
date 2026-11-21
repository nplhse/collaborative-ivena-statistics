<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form\Transformer;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A hidden field with the public id of a hospital; ids that are not a hospital become null.
 *
 * @implements DataTransformerInterface<Hospital|null, string>
 */
final readonly class HospitalToPublicIdTransformer implements DataTransformerInterface
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
    ) {
    }

    #[\Override]
    public function transform(mixed $value): string
    {
        return $value instanceof Hospital ? $value->getPublicIdString() : '';
    }

    #[\Override]
    public function reverseTransform(mixed $value): ?Hospital
    {
        if (!\is_string($value) || !Uuid::isValid($value)) {
            return null;
        }

        $hospital = $this->hospitalRepository->findOneByPublicId($value);

        return $hospital instanceof Hospital ? $hospital : null;
    }
}
