<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\UI\Http\Controller\Allocation\AllocationCrudController;
use App\Admin\UI\Http\Controller\DashboardController;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Admin\UI\Http\Controller\ImportReject\ImportRejectCrudController;
use App\Import\Domain\Enum\ImportStatus;
use App\Kpi\Application\Contract\AdminLinkGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AdminLinkGeneratorInterface::class)]
final readonly class EasyAdminAdminLinkGenerator implements AdminLinkGeneratorInterface
{
    public function __construct(
        private AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function hospitalCrudIndexUrl(): string
    {
        return $this->crudIndexUrl(HospitalCrudController::class);
    }

    public function importCrudIndexUrl(): string
    {
        return $this->crudIndexUrl(ImportCrudController::class);
    }

    public function allocationCrudIndexUrl(): string
    {
        return $this->crudIndexUrl(AllocationCrudController::class);
    }

    public function importRejectCrudIndexUrl(): string
    {
        return $this->crudIndexUrl(ImportRejectCrudController::class);
    }

    public function failedImportsCrudIndexUrl(): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(ImportCrudController::class)
            ->setAction(Action::INDEX)
            ->set(EA::FILTERS, [
                'status' => [
                    'comparison' => ComparisonType::EQ,
                    'value' => ImportStatus::FAILED->value,
                ],
            ])
            ->generateUrl();
    }

    public function importDetailUrl(int $importId): string
    {
        return $this->adminUrlGenerator
            ->setController(ImportCrudController::class)
            ->setAction('detail')
            ->setEntityId($importId)
            ->generateUrl();
    }

    /**
     * @param class-string $controller
     */
    private function crudIndexUrl(string $controller): string
    {
        return $this->adminUrlGenerator
            ->setController($controller)
            ->generateUrl();
    }
}
