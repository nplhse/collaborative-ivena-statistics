<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Application\Service\ImportListAccess;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Query\ImportBatchProgressQuery;
use App\Import\Infrastructure\Query\ListImportsQuery;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\Import\UI\Http\DTO\ListImportQueryParametersDTO;
use App\Shared\UI\Twig\DataTable\DataTableBulkAction;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Pagination\PaginationInterface;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/import', name: 'app_import_index')]
#[IsGranted('ROLE_PARTICIPANT')]
final class ListImportController extends AbstractController
{
    public function __construct(
        private readonly ListImportsQuery $listImportsQuery,
        private readonly HospitalRepository $hospitalRepository,
        private readonly ImportListAccess $importListAccess,
        private readonly PaginatorInterface $paginator,
        private readonly ImportBatchProgressQuery $batchProgressQuery,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapQueryString] ListImportQueryParametersDTO $query,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('You must be logged in to view imports.');
        }

        $paginator = $this->paginator
            ->query($this->listImportsQuery->listQuery($user, $query))
            ->perPage($query->limit > 0 ? $query->limit : 1)
            ->paginate();

        $bulkSelectableIds = $this->bulkSelectableIds($paginator);

        return $this->render('@Import/index.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_import_index',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'filters' => $query,
            'activeFilterCount' => $this->countActiveFilters($query),
            'hospitals' => $this->hospitalRepository->findAccessibleHospitalSummaries($user),
            'owners' => $this->importListAccess->resolveOwnerChoices($user),
            'statuses' => ImportStatus::cases(),
            'canImport' => [] !== $this->importListAccess->resolveAccessibleHospitalIds($user),
            'bulkActions' => [] === $bulkSelectableIds ? [] : $this->bulkActions(),
            'bulkSelectableIds' => $bulkSelectableIds,
            'batch' => $query->batch,
            'batchProgress' => null === $query->batch ? null : $this->batchProgressQuery->forUser($query->batch, $user),
        ]);
    }

    /**
     * Rows the user may change; the bulk endpoint checks every id again.
     *
     * @param PaginationInterface<mixed> $paginator
     *
     * @return list<int>
     */
    private function bulkSelectableIds(PaginationInterface $paginator): array
    {
        $ids = [];
        foreach ($paginator->getItems() as $import) {
            if (!$import instanceof Import) {
                continue;
            }

            $id = $import->getId();
            if (null !== $id && $this->isGranted(ImportVoter::DELETE, $import)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<DataTableBulkAction>
     */
    private function bulkActions(): array
    {
        return [
            new DataTableBulkAction(
                name: BulkImportActionController::ACTION_REPROCESS,
                label: $this->trans('import.bulk.reprocess.label'),
                icon: 'tabler:refresh',
                confirmTitleOne: $this->trans('import.bulk.reprocess.confirm_title.one'),
                confirmTitleOther: $this->trans('import.bulk.reprocess.confirm_title.other', ['count' => '%count%']),
                confirmMessage: $this->trans('import.bulk.reprocess.confirm_message'),
                confirmLabel: $this->trans('import.bulk.reprocess.confirm'),
            ),
        ];
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'import');
    }

    private function countActiveFilters(ListImportQueryParametersDTO $query): int
    {
        $count = 0;
        $filterFields = ['search', 'hospitalId', 'ownerId', 'status'];

        foreach ($filterFields as $field) {
            $value = $query->{$field} ?? null;
            if (null !== $value && '' !== $value) {
                ++$count;
            }
        }

        if (!$query->isDefaultCreatedFrom()) {
            ++$count;
        }

        if (!$query->isDefaultCreatedUntil()) {
            ++$count;
        }

        if (null !== $query->batch) {
            ++$count;
        }

        return $count;
    }
}
