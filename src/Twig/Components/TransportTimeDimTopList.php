<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Scope;
use App\Service\Statistics\TransportTimeDimTopReader;
use App\Service\TransportTimeDimNameResolver;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent(name: 'TransportTimeDimTopList')]
final class TransportTimeDimTopList
{
    /**
     * Current scope, passed from template.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public Scope $scope;

    /**
     * Dimension type stored in agg_allocations_transport_time_dim.dim_type,
     * e.g. "dispatch_area", "indication_normalized".
     */
    public string $dimType = 'dispatch_area';

    /**
     * Card title.
     */
    public string $title = 'Top items';

    /**
     * Optional Tabler icon class (without leading "ti ").
     * Example: "ti-map", "ti-stethoscope".
     */
    public ?string $icon = null;

    /**
     * Maximum number of rows to show.
     */
    public int $limit = 10;

    /** Selected bucket key: 'all' or one of '<10', '10-20', ... */
    public string $bucket = 'all';

    /**
     * @var list<array{value:string,label:string}>
     */
    public array $buckets = [];

    /**
     * Whether to show withPhysician columns.
     */
    public bool $withPhysician = true;

    public bool $withProgress = false;

    /**
     * @var list<array{
     *   dimId:int,
     *   name:string,
     *   total:int,
     *   share:float,
     *   withPhysician:int,
     *   withPhysicianShare:float
     * }>
     */
    public array $rows = [];

    public function __construct(
        private readonly TransportTimeDimTopReader $reader,
        private readonly TransportTimeDimNameResolver $nameResolver,
    ) {
    }

    #[PostMount]
    public function init(): void
    {
        $bucket = $this->bucket;

        if ('all' === $bucket || '' === $bucket) {
            $bucket = null;
        }

        $raw = $this->reader->readTop($this->scope, $this->dimType, $this->limit, $bucket);

        if ([] === $raw) {
            $this->rows = [];

            return;
        }

        // Collect distinct dimension IDs as a 0-based list<int> for resolveNames()
        $ids = array_values(array_unique(array_map(static fn (array $r): int => $r['dimId'], $raw)));

        // Resolve labels based on dimType
        $nameById = $this->nameResolver->resolve($this->dimType, $ids);

        $rows = [];
        foreach ($raw as $r) {
            $id = $r['dimId'];
            $rows[] = [
                'dimId' => $id,
                'name' => $nameById[$id] ?? $this->nameResolver->fallbackLabel($this->dimType, $id),
                'total' => $r['total'],
                'share' => $r['share'],
                'withPhysician' => $r['withPhysician'],
                'withPhysicianShare' => $r['withPhysicianShare'],
            ];
        }

        $this->rows = $rows;
    }

    public function hasData(): bool
    {
        return [] !== $this->rows;
    }
}
