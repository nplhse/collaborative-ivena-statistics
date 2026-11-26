<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals\Action;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class MyHospitalActionRegistry
{
    /**
     * @param iterable<MyHospitalActionProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(MyHospitalActionProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, list<MyHospitalAction>> actions by hospital id, ordered by position and key
     */
    public function actionsFor(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $actions = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->actionsFor($hospitalIds) as $hospitalId => $hospitalActions) {
                if (!\in_array($hospitalId, $hospitalIds, true)) {
                    continue;
                }

                foreach ($hospitalActions as $action) {
                    $actions[$hospitalId][] = $action;
                }
            }
        }

        foreach ($actions as $hospitalId => $hospitalActions) {
            usort($hospitalActions, static fn (MyHospitalAction $a, MyHospitalAction $b): int => [$a->position, $a->key] <=> [$b->position, $b->key]);
            $actions[$hospitalId] = $hospitalActions;
        }

        return $actions;
    }
}
