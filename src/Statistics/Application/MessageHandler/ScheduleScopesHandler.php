<?php

declare(strict_types=1);

namespace App\Statistics\Application\MessageHandler;

use App\Statistics\Application\Contract\ScopeProviderInterface;
use App\Statistics\Application\Message\RecomputeScope;
use App\Statistics\Application\Message\ScheduleScope;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/** @psalm-suppress ClassMustBeFinal */
#[AsMessageHandler]
class ScheduleScopesHandler
{
    /**
     * @param iterable<ScopeProviderInterface> $providers
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        #[AutowireIterator('app.stats.scope_provider')]
        private iterable $providers,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ScheduleScope $message): void
    {
        foreach ($this->providers as $provider) {
            foreach ($provider->provideForImport($message->importId) as $scope) {
                $msg = new RecomputeScope(
                    $scope->scopeType,
                    $scope->scopeId,
                    $scope->granularity,
                    $scope->periodKey
                );

                // Hospital slices => HIGH transport; all others => NORMAL
                $stamps = $scope->isHospital()
                    ? [new TransportNamesStamp(['async_priority_high'])]
                    : [];

                $this->messageBus->dispatch($msg, $stamps);
            }
        }
    }
}
