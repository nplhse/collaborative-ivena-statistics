<?php

declare(strict_types=1);

namespace App\User\Domain\Entity;

use App\User\Infrastructure\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

/** @psalm-suppress UnusedClass, PossiblyUnusedProperty */
#[ORM\Entity(repositoryClass: ResetPasswordRequestRepository::class)]
class ResetPasswordRequest implements ResetPasswordRequestInterface
{
    use ResetPasswordRequestTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    public function __construct(#[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ?User $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken)
    {
        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    #[\Override]
    public function getUser(): User
    {
        if (!$this->user instanceof User) {
            throw new \LogicException('Reset password request has no user.');
        }

        return $this->user;
    }
}
