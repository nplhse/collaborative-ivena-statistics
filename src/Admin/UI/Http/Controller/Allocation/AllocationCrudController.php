<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Allocation;

use App\Allocation\Domain\Entity\Allocation;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * @extends AbstractCrudController<Allocation>
 */
final class AllocationCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Allocation::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Allocation')
            ->setEntityLabelInPlural('Allocations')
            ->setSearchFields(['id', 'import.name', 'hospital.name', 'indicationRaw.name'])
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
        yield IdField::new('id')
            ->onlyOnDetail();

        yield AssociationField::new('import', 'Import');
        yield AssociationField::new('indicationRaw', 'Indication Raw');
        yield AssociationField::new('hospital', 'Hospital');
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();

        yield AssociationField::new('dispatchArea', 'Dispatch Area')
            ->hideOnIndex();
        yield AssociationField::new('state', 'State')
            ->hideOnIndex();
        yield DateTimeField::new('arrivalAt', 'Arrival')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnIndex();
        yield ChoiceField::new('gender', 'Gender')
            ->setChoices([
                'Male' => AllocationGender::MALE,
                'Female' => AllocationGender::FEMALE,
                'Other' => AllocationGender::OTHER,
            ])
            ->hideOnIndex();
        yield IntegerField::new('age', 'Age')
            ->hideOnIndex();
        yield BooleanField::new('requiresResus', 'Requires Resus')
            ->hideOnIndex();
        yield BooleanField::new('requiresCathlab', 'Requires Cathlab')
            ->hideOnIndex();
        yield BooleanField::new('isCPR', 'CPR')
            ->hideOnIndex();
        yield BooleanField::new('isVentilated', 'Ventilated')
            ->hideOnIndex();
        yield BooleanField::new('isShock', 'Shock')
            ->hideOnIndex();
        yield BooleanField::new('isPregnant', 'Pregnant')
            ->hideOnIndex();
        yield BooleanField::new('isWithPhysician', 'With Physician')
            ->hideOnIndex();
        yield ChoiceField::new('transportType', 'Transport Type')
            ->setChoices([
                'Ground' => AllocationTransportType::GROUND,
                'Air' => AllocationTransportType::AIR,
            ])
            ->hideOnIndex();
        yield ChoiceField::new('urgency', 'Urgency')
            ->setChoices([
                'Emergency' => AllocationUrgency::EMERGENCY,
                'Inpatient' => AllocationUrgency::INPATIENT,
                'Outpatient' => AllocationUrgency::OUTPATIENT,
            ])
            ->hideOnIndex();
        yield AssociationField::new('speciality', 'Speciality')
            ->hideOnIndex();
        yield AssociationField::new('department', 'Department')
            ->hideOnIndex();
        yield BooleanField::new('departmentWasClosed', 'Department Was Closed')
            ->hideOnIndex();
        yield AssociationField::new('occasion', 'Occasion')
            ->hideOnIndex();
        yield AssociationField::new('assignment', 'Assignment')
            ->hideOnIndex();
        yield AssociationField::new('infection', 'Infection')
            ->hideOnIndex();
        yield AssociationField::new('indicationNormalized', 'Indication Normalized')
            ->hideOnIndex();
        yield AssociationField::new('assessment', 'Assessment')
            ->hideOnIndex();
    }
}
