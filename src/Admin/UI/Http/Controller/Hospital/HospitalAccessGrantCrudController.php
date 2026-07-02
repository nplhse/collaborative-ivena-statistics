<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Hospital;

use App\Admin\Application\Service\HospitalPermissionLabelFormatter;
use App\Admin\UI\Form\HospitalPermissionsFieldType;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<HospitalAccessGrant>
 */
#[IsGranted('ROLE_ADMIN')]
final class HospitalAccessGrantCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AuditContext $auditContext,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return HospitalAccessGrant::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Hospital access grant')
            ->setEntityLabelInPlural('Hospital access grants')
            ->setSearchFields(['id', 'hospital.name', 'user.username', 'user.email'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX);
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('hospital', 'Hospital'))
            ->add(EntityFilter::new('user', 'User'));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();
        yield AssociationField::new('hospital', 'Hospital');
        yield AssociationField::new('user', 'User');
        yield Field::new('permissions', 'Permissions')
            ->setFormType(HospitalPermissionsFieldType::class)
            ->hideOnIndex()
            ->formatValue(static fn (mixed $value, HospitalAccessGrant $grant): string => HospitalPermissionLabelFormatter::formatMask($grant->getPermissions()));
        yield TextField::new('permissionsSummary', 'Permissions')
            ->onlyOnIndex()
            ->setValue('')
            ->formatValue(static fn (mixed $_, HospitalAccessGrant $grant): string => HospitalPermissionLabelFormatter::formatMask($grant->getPermissions()));
        yield DateTimeField::new('createdAt', 'Created')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->hideOnForm();
        yield AssociationField::new('createdBy', 'Created by')
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Updated by')
            ->onlyOnDetail();
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->auditContext->beginIntent('hospital_access_grant.admin.created', ['source' => 'easyadmin']);
        try {
            parent::persistEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->auditContext->beginIntent('hospital_access_grant.admin.updated', ['source' => 'easyadmin']);
        try {
            parent::updateEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
