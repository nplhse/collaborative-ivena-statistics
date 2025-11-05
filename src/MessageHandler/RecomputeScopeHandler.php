<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Contract\CalculatorInterface;
use App\Message\RecomputeScope;
use App\Model\Scope;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RecomputeScopeHandler
{
    /** @param iterable<CalculatorInterface> $calculators */
    public function __construct(
        #[AutowireIterator('app.stats.calculator')]
        private iterable $calculators,
        private LockFactory $lockFactory,
    ) {
    }

    public function __invoke(RecomputeScope $message): void
    {
        $scope = new Scope(
            $message->scopeType,
            $message->scopeId,
            $message->granularity,
            $message->periodKey
        );

        $lock = $this->lockFactory->createLock($scope->lockKey());

        if (!$lock->acquire()) {
            return;
        }

        try {
            foreach ($this->calculators as $calculator) {
                if ($calculator->supports($scope)) {
                    $calculator->calculate($scope);
                }
            }
        } finally {
            $lock->release();
        }
    }
}
