<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Application\Explore\ExploreFilterOptionsProvider;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\UI\Http\DTO\HospitalQueryParametersDTO;
use App\Allocation\UI\Twig\HospitalListColumns;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\UI\Http\DataTablePreferenceQueryState;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/explore/hospital', name: 'app_explore_hospital_list', methods: ['GET'])]
final class ListHospitalsController extends AbstractController
{
    public function __construct(
        private readonly HospitalRepository $hospitalRepository,
        private readonly ExploreFilterOptionsProvider $filterOptionsProvider,
        private readonly PaginatorInterface $paginator,
        private readonly HospitalListColumns $columns,
        private readonly DataTablePreferenceService $dataTablePreferences,
    ) {
    }

    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[MapQueryString] HospitalQueryParametersDTO $queryParametersDTO,
    ): Response {
        $schema = $this->columns->preferenceSchema();
        $queryPreferences = DataTablePreferenceQueryState::fromRequest($request);
        $preferences = $this->dataTablePreferences->resolve(
            $user,
            $schema,
            $queryPreferences->visibleColumns,
            $queryPreferences->columnOrder,
            $queryPreferences->pageSize,
        );
        // An explicit limit wins (shared links); otherwise the user's saved page size applies.
        $perPage = $request->query->has('limit') ? $queryParametersDTO->limit : $preferences->pageSize;

        $paginator = $this->paginator
            ->query($this->hospitalRepository->hospitalListQuery($queryParametersDTO))
            ->perPage(max(1, $perPage))
            ->paginate();

        return $this->render('@Allocation/hospitals/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_hospital_list',
            'search' => $queryParametersDTO->search,
            'sortBy' => $queryParametersDTO->sortBy,
            'orderBy' => $queryParametersDTO->orderBy,
            'hospitalColumns' => HospitalListColumns::columns(),
            'hospitalPreferences' => $preferences,
            'hospitalPreferenceKey' => $schema->key,
            'hospitalPreferencesPersisted' => $user instanceof User,
            'filters' => $queryParametersDTO,
            'activeFilterCount' => $this->countActiveFilters($queryParametersDTO),
            'tiers' => HospitalTier::cases(),
            'locations' => HospitalLocation::cases(),
            'sizes' => HospitalSize::cases(),
            'states' => $this->filterOptionsProvider->states(),
            'dispatchAreas' => $this->filterOptionsProvider->dispatchAreas(),
        ]);
    }

    private function countActiveFilters(HospitalQueryParametersDTO $queryParametersDTO): int
    {
        $activeFilterCount = 0;

        $filterFields = [
            'tier',
            'location',
            'size',
            'dispatchArea',
            'state',
            'participating',
            'search',
        ];

        foreach ($filterFields as $field) {
            $value = $queryParametersDTO->{$field} ?? null;
            if (null !== $value && '' !== $value) {
                ++$activeFilterCount;
            }
        }

        return $activeFilterCount;
    }
}
