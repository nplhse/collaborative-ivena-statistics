<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Hospital;

use App\Admin\Application\Service\HospitalPermissionLabelFormatter;
use App\Admin\UI\Form\HospitalPermissionsFieldType;
use App\Allocation\Application\Hospital\HospitalAssociationSnapshot;
use App\Allocation\Application\Hospital\HospitalRelationNotifier;
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
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<HospitalAccessGrant>
 */
#[IsGranted('ROLE_ADMIN')]
final class HospitalAccessGrantCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AuditContext $auditContext,
        private readonly HospitalRelationNotifier $hospitalRelationNotifier,
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
            ->setEntityLabelInSingular(new TranslatableMessage('admin.hospital_access_grant.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.hospital_access_grant.entity.plural', domain: 'admin'))
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
            ->add(EntityFilter::new('hospital', new TranslatableMessage('admin.hospital.entity.singular', domain: 'admin')))
            ->add(EntityFilter::new('user', new TranslatableMessage('admin.hospital_access_grant.field.user', domain: 'admin')));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();
        yield AssociationField::new('hospital', new TranslatableMessage('admin.hospital.entity.singular', domain: 'admin'));
        yield AssociationField::new('user', new TranslatableMessage('admin.hospital_access_grant.field.user', domain: 'admin'));
        yield Field::new('permissions', new TranslatableMessage('label.hospital_access_grant.permissions', domain: 'messages'))
            ->setFormType(HospitalPermissionsFieldType::class)
            ->hideOnIndex()
            ->formatValue(static fn (mixed $value, HospitalAccessGrant $grant): string => HospitalPermissionLabelFormatter::formatMask($grant->getPermissions()));
        yield TextField::new('permissionsSummary', new TranslatableMessage('label.hospital_access_grant.permissions', domain: 'messages'))
            ->onlyOnIndex()
            ->setValue('')
            ->formatValue(static fn (mixed $_, HospitalAccessGrant $grant): string => HospitalPermissionLabelFormatter::formatMask($grant->getPermissions()));
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->hideOnForm();
        yield AssociationField::new('createdBy', new TranslatableMessage('label.created_by', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', new TranslatableMessage('label.updated_by', domain: 'messages'))
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

        $this->hospitalRelationNotifier->associated($entityInstance);
    }

    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $snapshot = $this->hospitalRelationNotifier->snapshot($entityInstance);

        $this->auditContext->beginIntent('hospital_access_grant.admin.deleted', ['source' => 'easyadmin']);
        try {
            parent::deleteEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }

        if ($snapshot instanceof HospitalAssociationSnapshot) {
            $this->hospitalRelationNotifier->disassociated($snapshot);
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
