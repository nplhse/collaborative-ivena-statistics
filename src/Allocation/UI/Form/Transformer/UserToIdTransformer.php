<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form\Transformer;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\Form\DataTransformerInterface;

/**
 * @implements DataTransformerInterface<User|null, string>
 */
final readonly class UserToIdTransformer implements DataTransformerInterface
{
    /**
     * @param list<int> $allowedUserIds
     */
    public function __construct(
        private UserRepository $userRepository,
        private array $allowedUserIds,
    ) {
    }

    #[\Override]
    public function transform(mixed $value): string
    {
        if ($value instanceof User) {
            return (string) $value->getId();
        }

        return '';
    }

    #[\Override]
    public function reverseTransform(mixed $value): ?User
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $userId = (int) $value;
        if (!\in_array($userId, $this->allowedUserIds, true)) {
            return null;
        }

        $user = $this->userRepository->find($userId);

        return $user instanceof User ? $user : null;
    }
}
