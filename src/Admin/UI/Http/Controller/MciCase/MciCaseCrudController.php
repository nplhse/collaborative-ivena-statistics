<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\MciCase;

use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<MciCase>
 */
#[IsGranted('ROLE_ADMIN')]
final class MciCaseCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return MciCase::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.mci_case.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.mci_case.entity.plural', domain: 'admin'))
            ->setSearchFields(['id', 'import.name', 'hospital.name', 'mciTitle', 'mciId'])
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
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.overview', domain: 'admin'));
        yield AssociationField::new('import', new TranslatableMessage('label.import', domain: 'admin'));
        yield AssociationField::new('hospital', new TranslatableMessage('admin.hospital.entity.singular', domain: 'admin'))
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield DateTimeField::new('arrivalAt', new TranslatableMessage('label.arrival_at', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield AssociationField::new('dispatchArea', new TranslatableMessage('admin.dispatch_area.entity.singular', domain: 'admin'))
            ->hideOnIndex();
        yield AssociationField::new('state', new TranslatableMessage('label.state', domain: 'messages'))
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.incident', domain: 'admin'));
        yield TextField::new('mciId', new TranslatableMessage('admin.mci_case.field.mci_id', domain: 'admin'))
            ->hideOnIndex();
        yield TextField::new('mciTitle', new TranslatableMessage('admin.mci_case.field.mci_title', domain: 'admin'))
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.patient', domain: 'admin'));
        yield ChoiceField::new('gender', new TranslatableMessage('label.gender', domain: 'messages'))
            ->setChoices([
                'Male' => AllocationGender::MALE,
                'Female' => AllocationGender::FEMALE,
                'Other' => AllocationGender::OTHER,
            ])
            ->hideOnIndex();
        yield IntegerField::new('age', new TranslatableMessage('label.age', domain: 'messages'))
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.clinical', domain: 'admin'));
        yield BooleanField::new('requiresResus', new TranslatableMessage('label.requires_resus', domain: 'messages'))
            ->hideOnIndex();
        yield BooleanField::new('requiresCathlab', new TranslatableMessage('label.requires_cathlab', domain: 'messages'))
            ->hideOnIndex();
        yield BooleanField::new('isCPR', new TranslatableMessage('label.is_cpr', domain: 'messages'))
            ->hideOnIndex();
        yield BooleanField::new('isVentilated', new TranslatableMessage('label.is_ventilated', domain: 'messages'))
            ->hideOnIndex();
        yield BooleanField::new('isShock', new TranslatableMessage('label.is_shock', domain: 'messages'))
            ->hideOnIndex();
        yield BooleanField::new('isPregnant', new TranslatableMessage('label.pregnant', domain: 'messages'))
            ->hideOnIndex();
        yield BooleanField::new('isWithPhysician', new TranslatableMessage('admin.allocation.field.with_physician', domain: 'admin'))
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.transport', domain: 'admin'));
        yield ChoiceField::new('transportType', new TranslatableMessage('label.transport_type', domain: 'messages'))
            ->setChoices([
                'Ground' => AllocationTransportType::GROUND,
                'Air' => AllocationTransportType::AIR,
            ])
            ->hideOnIndex();
        yield ChoiceField::new('urgency', new TranslatableMessage('label.urgency', domain: 'messages'))
            ->setChoices([
                'Emergency' => AllocationUrgency::EMERGENCY,
                'Inpatient' => AllocationUrgency::INPATIENT,
                'Outpatient' => AllocationUrgency::OUTPATIENT,
            ])
            ->hideOnIndex();
        yield AssociationField::new('speciality', new TranslatableMessage('label.speciality', domain: 'messages'))
            ->hideOnIndex();
        yield AssociationField::new('department', new TranslatableMessage('label.department', domain: 'messages'))
            ->hideOnIndex();
        yield BooleanField::new('departmentWasClosed', new TranslatableMessage('field.departmentWasClosed', domain: 'messages'))
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.indications', domain: 'admin'));
        yield AssociationField::new('occasion', new TranslatableMessage('label.occasion', domain: 'messages'))
            ->hideOnIndex();
        yield AssociationField::new('infection', new TranslatableMessage('label.infection', domain: 'messages'))
            ->hideOnIndex();
        yield AssociationField::new('indicationRaw', new TranslatableMessage('admin.indication_raw.entity.singular', domain: 'admin'))
            ->hideOnIndex();
        yield AssociationField::new('indicationNormalized', new TranslatableMessage('admin.indication_normalized.entity.singular', domain: 'admin'))
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
    }
}
