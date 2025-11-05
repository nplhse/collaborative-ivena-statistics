<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Contract\ScopeProviderInterface;
use App\Message\RecomputeScope;
use App\Message\ScheduleScope;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsMessageHandler]
final class ScheduleScopesHandler
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
