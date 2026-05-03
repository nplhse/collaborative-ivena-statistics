<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Allocation\Domain\Entity\Address;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException;

final readonly class AuditValueNormalizer
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function normalize(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        if (\is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof Address) {
            return [
                'street' => $value->getStreet(),
                'postalCode' => $value->getPostalCode(),
                'city' => $value->getCity(),
                'state' => $value->getState(),
                'country' => $value->getCountry(),
            ];
        }

        if ($value instanceof Collection) {
            return array_map($this->normalize(...), $value->toArray());
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item);
            }

            return $normalized;
        }

        if (\is_object($value)) {
            return $this->normalizeEntityReference($value);
        }

        return null;
    }

    /**
     * @return array{class: string, id: mixed}|string
     */
    private function normalizeEntityReference(object $entity): string|array
    {
        try {
            $meta = $this->em->getClassMetadata($entity::class);
        } catch (MappingException) {
            return $entity::class;
        }

        $ids = $meta->getIdentifierValues($entity);

        return [
            'class' => $meta->getName(),
            'id' => 1 === \count($ids) ? array_values($ids)[0] : $ids,
        ];
    }
}
