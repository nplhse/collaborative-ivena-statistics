<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Doctrine;

use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class OriginalEntityUser
{
    public static function from(EntityManagerInterface $entityManager, object $entity, string $field): ?User
    {
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entity);
        $value = $original[$field] ?? null;
        if ($value instanceof User) {
            return $value;
        }

        if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
            $found = $entityManager->find(User::class, (int) $value);

            return $found instanceof User ? $found : null;
        }

        return null;
    }
}
