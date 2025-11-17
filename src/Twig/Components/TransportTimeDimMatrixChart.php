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

#[AsTwigComponent('TransportTimeDimMatrixChart')]
final class TransportTimeDimMatrixChart
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public Scope $scope;

    public string $dimType = 'occasion';

    public string $view = 'int';

    public int $limit = 5;

    /** @var list<string> */
    public array $bucketKeys = [];

    /** @var list<string> */
    public array $labels = [];

    /** @var list<array{name:string,data:list<float|int>}> */
    public array $series = [];

    public int $height = 260;

    public string $domId = '';

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
        if ($this->limit < 1) {
            $this->limit = 5;
        }

        if ([] === $this->bucketKeys) {
            $this->bucketKeys = ['<10', '10-20', '20-30', '30-40', '40-50', '50-60', '>60'];
        }

        $this->labels = $this->bucketKeys;

        $rows = $this->reader->readMatrix($this->scope, $this->dimType);

        if ([] === $rows) {
            $this->series = [];
            $this->domId = $this->domId ?: 'chart-tt-dim-empty';

            return;
        }

        $ids = array_values(array_unique(array_map(static fn (array $r): int => $r['dimId'], $rows)));
        $nameById = $this->resolveNames($this->dimType, $ids);

        $rows = array_slice($rows, 0, $this->limit);

        $series = [];
        foreach ($rows as $row) {
            $total = max(0, $row['total']);
            $data = [];

            $dimId = $row['dimId'];
            $displayName = $nameById[$dimId] ?? $this->fallbackLabel($this->dimType, $dimId);

            foreach ($this->bucketKeys as $bucket) {
                $count = $row['buckets'][$bucket] ?? 0;
                if ('pct' === $this->view) {
                    $data[] = $total > 0 ? (100.0 * (float) $count / (float) $total) : 0.0;
                } else {
                    $data[] = $count;
                }
            }

            $series[] = [
                'name' => $displayName,
                'data' => $data,
            ];
        }

        $this->series = $series;
        $this->domId = $this->domId ?: ('chart-tt-dim-'.bin2hex(random_bytes(4)));
    }

    public function hasData(): bool
    {
        if ([] === $this->series) {
            return false;
        }

        foreach ($this->series as $s) {
            $sum = array_sum(array_map('floatval', $s['data'] ?? []));
            if ($sum > 0.0) {
                return true;
            }
        }

        return false;
    }

    public function viewUrl(string $view): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
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

    public function limitUrl(int $limit): string
    {
        $r = $this->requestStack->getCurrentRequest();
        if (!$r) {
            return '#';
        }

        $route = (string) $r->attributes->get('_route');

        $params = array_merge(
            $r->attributes->get('_route_params', []),
            $r->query->all(),
            ['tt_limit' => $limit]
        );

        return $this->router->generate($route, $params);
    }

    public function dimLabel(): string
    {
        return match ($this->dimType) {
            'occasion' => 'Occasion',
            'assignment' => 'Assignment',
            'dispatch_area' => 'Dispatch Area',
            'state' => 'State',
            default => ucfirst(str_replace('_', ' ', $this->dimType)),
        };
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

        switch ($dimType) {
            case 'assignment':
                $entities = $this->assignmentRepository->findBy(['id' => $ids]);
                break;

            case 'dispatch_area':
                $entities = $this->dispatchAreaRepository->findBy(['id' => $ids]);
                break;

            case 'occasion':
                $entities = $this->occasionRepository->findBy(['id' => $ids]);
                break;

            case 'indication':
            case 'indication_normalized':
                $entities = $this->indicationRepository->findBy(['id' => $ids]);
                break;

            case 'speciality':
                $entities = $this->specialityRepository->findBy(['id' => $ids]);
                break;

            case 'state':
                $entities = $this->stateRepository->findBy(['id' => $ids]);
                break;

            default:
                return [];
        }

        $names = [];
        foreach ($entities as $entity) {
            $id = $entity->getId();
            if (null === $id) {
                continue;
            }

            $names[$id] = (string) $entity->getName();
        }

        return $names;
    }

    private function fallbackLabel(string $type, int $id): string
    {
        return ucfirst(str_replace('_', ' ', $type)).' #'.$id;
    }
}
