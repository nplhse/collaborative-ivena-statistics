<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure\Repository;

use App\Onboarding\Domain\Entity\UserOnboardingStep;
use App\Onboarding\Domain\Enum\OnboardingStepKey;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserOnboardingStep>
 */
final class UserOnboardingStepRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserOnboardingStep::class);
    }

    public function save(UserOnboardingStep $step): void
    {
        $this->getEntityManager()->persist($step);
        $this->getEntityManager()->flush();
    }

    public function findForUserAndStep(User $user, OnboardingStepKey $stepKey): ?UserOnboardingStep
    {
        return $this->findOneBy([
            'user' => $user,
            'stepKey' => $stepKey,
        ]);
    }

    /**
     * @return list<UserOnboardingStep>
     */
    public function findCompletedByUser(User $user): array
    {
        $userId = $user->getId();
        if (null === $userId) {
            return [];
        }

        /** @var list<UserOnboardingStep> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('IDENTITY(s.user) = :userId')
            ->setParameter('userId', $userId, Types::INTEGER)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return array<string, UserOnboardingStep>
     */
    public function findCompletedByUserIndexed(User $user): array
    {
        $indexed = [];
        foreach ($this->findCompletedByUser($user) as $step) {
            $indexed[$step->getStepKey()->value] = $step;
        }

        return $indexed;
    }
}
