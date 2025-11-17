<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Scope;
use App\Repository\AssignmentRepository;
use App\Repository\DispatchAreaRepository;
use App\Repository\IndicationNormalizedRepository;
use App\Repository\OccasionRepository;
use App\Repository\SpecialityRepository;
use App\Repository\StateRepository;
use App\Service\Statistics\TransportTimeDimMatrixReader;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent(name: 'TransportTimeDimMatrixTable')]
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

    public function __construct(
        private readonly TransportTimeDimMatrixReader $reader,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly DispatchAreaRepository $dispatchAreaRepository,
        private readonly OccasionRepository $occasionRepository,
        private readonly IndicationNormalizedRepository $indicationRepository,
        private readonly SpecialityRepository $specialityRepository,
        private readonly StateRepository $stateRepository,
    ) {
    }

    #[PostMount]
    public function init(): void
    {
        if ([] === $this->bucketKeys) {
            $this->bucketKeys = [
                '<10',
                '10-20',
                '20-30',
                '30-40',
                '40-50',
                '50-60',
                '>60',
            ];
        }

        $raw = $this->reader->readMatrix($this->scope, $this->dimType);

        if ([] === $raw) {
            $this->rows = [];

            return;
        }

        $ids = array_values(array_unique(array_map(
            static fn (array $r): int => $r['dimId'],
            $raw
        )));

        $nameById = $this->resolveNames($this->dimType, $ids);

        $rows = [];
        foreach ($raw as $r) {
            $id = $r['dimId'];
            $rows[] = [
                'dimId' => $id,
                'name' => $nameById[$id] ?? $this->fallbackLabel($this->dimType, $id),
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

    /**
     * @param list<int> $ids
     *
     * @return array<int,string> map[id] => name
     */
    private function resolveNames(string $dimType, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var iterable<object> $entities */
        $entities = match ($dimType) {
            'assignment' => $this->assignmentRepository->findBy(['id' => $ids]),
            'dispatch_area' => $this->dispatchAreaRepository->findBy(['id' => $ids]),
            'occasion' => $this->occasionRepository->findBy(['id' => $ids]),
            'indication', 'indication_normalized' => $this->indicationRepository->findBy(['id' => $ids]),
            'speciality' => $this->specialityRepository->findBy(['id' => $ids]),
            'state' => $this->stateRepository->findBy(['id' => $ids]),
            default => [],
        };

        /** @var array<int,string> $names */
        $names = [];
        foreach ($entities as $entity) {
            if (!method_exists($entity, 'getId') || !method_exists($entity, 'getName')) {
                continue;
            }

            $id = (int) $entity->getId();
            $names[$id] = (string) $entity->getName();
        }

        return $names;
    }

    private function fallbackLabel(string $type, int $id): string
    {
        return ucfirst(str_replace('_', ' ', $type)).' #'.$id;
    }
}
