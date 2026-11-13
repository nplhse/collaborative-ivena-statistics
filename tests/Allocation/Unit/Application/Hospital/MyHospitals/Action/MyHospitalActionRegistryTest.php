<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals\Action;

use App\Allocation\Application\Hospital\MyHospitals\Action\MyHospitalAction;
use App\Allocation\Application\Hospital\MyHospitals\Action\MyHospitalActionProviderInterface;
use App\Allocation\Application\Hospital\MyHospitals\Action\MyHospitalActionRegistry;
use PHPUnit\Framework\TestCase;

final class MyHospitalActionRegistryTest extends TestCase
{
    public function testActionsAreMergedPerHospitalAndOrderedByPositionThenKey(): void
    {
        $registry = new MyHospitalActionRegistry([
            $this->provider([1 => [$this->action('b', 10)], 2 => [$this->action('a', 0)]]),
            $this->provider([1 => [$this->action('a', 10), $this->action('c', 0)]]),
        ]);

        $actions = $registry->actionsFor([1, 2]);

        self::assertSame(['c', 'a', 'b'], $this->keys($actions[1]));
        self::assertSame(['a'], $this->keys($actions[2]));
    }

    public function testActionsForHospitalsOutsideThePageAreDropped(): void
    {
        $registry = new MyHospitalActionRegistry([
            $this->provider([1 => [$this->action('a', 0)], 99 => [$this->action('b', 0)]]),
        ]);

        self::assertSame([1], array_keys($registry->actionsFor([1])));
    }

    public function testNoHospitalsAskNoProvider(): void
    {
        $provider = $this->createMock(MyHospitalActionProviderInterface::class);
        $provider->expects(self::never())->method('actionsFor');

        self::assertSame([], new MyHospitalActionRegistry([$provider])->actionsFor([]));
    }

    /**
     * @param list<MyHospitalAction> $actions
     *
     * @return list<string>
     */
    private function keys(array $actions): array
    {
        return array_map(static fn (MyHospitalAction $action): string => $action->key, $actions);
    }

    private function action(string $key, int $position): MyHospitalAction
    {
        return new MyHospitalAction($key, 'label', 'allocation', 'tabler:tool', 'app_tools_index', position: $position);
    }

    /**
     * @param array<int, list<MyHospitalAction>> $actions
     */
    private function provider(array $actions): MyHospitalActionProviderInterface
    {
        return new readonly class($actions) implements MyHospitalActionProviderInterface {
            /**
             * @param array<int, list<MyHospitalAction>> $actions
             */
            public function __construct(private array $actions)
            {
            }

            #[\Override]
            public function actionsFor(array $hospitalIds): array
            {
                return $this->actions;
            }
        };
    }
}
