<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

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

        if ($this->isAddressLike($value)) {
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
     * @phpstan-assert-if-true object{getStreet(): string, getPostalCode(): string, getCity(): string, getState(): string, getCountry(): string} $value
     */
    private function isAddressLike(mixed $value): bool
    {
        return \is_object($value)
            && method_exists($value, 'getStreet')
            && method_exists($value, 'getPostalCode')
            && method_exists($value, 'getCity')
            && method_exists($value, 'getState')
            && method_exists($value, 'getCountry');
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
