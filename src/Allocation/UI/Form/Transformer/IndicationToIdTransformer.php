<?php

namespace App\Allocation\UI\Form\Transformer;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use Symfony\Component\Form\DataTransformerInterface;

/**
 * @implements DataTransformerInterface<IndicationNormalized, string>
 */
final class IndicationToIdTransformer implements DataTransformerInterface
{
    public function __construct(
        private IndicationNormalizedRepository $repository,
    ) {
    }

    #[\Override]
    public function transform($value): string
    {
        if ($value instanceof IndicationNormalized) {
            return (string) $value->getId();
        }

        return '';
    }

    #[\Override]
    public function reverseTransform($value): ?IndicationNormalized
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return $this->repository->findOneBy(['id' => $value]);
    }
}
