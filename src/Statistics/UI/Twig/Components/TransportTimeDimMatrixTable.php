<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Reader\TransportTimeDimMatrixReader;
use App\Statistics\Infrastructure\Resolver\TransportTimeDimNameResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent(name: 'TransportTimeDimMatrixTable', template: '@Statistics/components/TransportTimeDimMatrixTable.html.twig')]
final class TransportTimeDimMatrixTable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Scope $scope;

    public string $dimType = 'occasion';

    public string $view = 'int';

    /** @var list<string> Set by template or defaulted in init() */
    public array $bucketKeys = [];

    /**
     * @var list<array{
     *   dimId:int,
     *   name:string,
     *   total:int,
     *   buckets:array<string,int>
     * }>
     */
    public array $rows = [];

    /**
     * @var list<array{
     *   dimId:int,
     *   total:int,
     *   buckets:array<string,int>
     * }>
     */
    public array $matrixRows = [];

    public function __construct(
        private readonly TransportTimeDimMatrixReader $reader,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly TransportTimeDimNameResolver $nameResolver,
    ) {
    }

    #[PostMount]
    public function init(): void
    {
        if ([] === $this->bucketKeys) {
            $this->bucketKeys = ['<10', '10-20', '20-30', '30-40', '40-50', '50-60', '>60'];
        }

        $raw = $this->matrixRows;

        if ([] === $raw) {
            $raw = $this->reader->readMatrix($this->scope, $this->dimType);
        }

        if ([] === $raw) {
            $this->rows = [];

            return;
        }

        $ids = array_values(array_unique(array_map(
            static fn (array $r): int => $r['dimId'],
            $raw
        )));

        $nameById = $this->nameResolver->resolve($this->dimType, $ids);

        $rows = [];
        foreach ($raw as $r) {
            $id = $r['dimId'];
            $rows[] = [
                'dimId' => $id,
                'name' => $nameById[$id] ?? $this->nameResolver->fallbackLabel($this->dimType, $id),
                'total' => $r['total'],
                'buckets' => $r['buckets'],
            ];
        }

        $this->rows = $rows;
    }

    public function dimLabel(): string
    {
        return match ($this->dimType) {
            'occasion' => 'Occasion',
            'assignment' => 'Assignment',
            'dispatch_area' => 'Dispatch Area',
            'state' => 'State',
            'speciality' => 'Speciality',
            'indication_normalized', 'indication' => 'Indication',
            default => ucfirst(str_replace('_', ' ', $this->dimType)),
        };
    }

    public function viewUrl(string $view): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (null === $r) {
            return '#';
        }

        $route = (string) $r->attributes->get('_route');

        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            ['view' => $view]
        );

        return $this->router->generate($route, $params);
    }
}
