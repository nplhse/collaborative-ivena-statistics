<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Hospital;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Hospital>
 */
final class HospitalCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Hospital::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Hospital')
            ->setEntityLabelInPlural('Hospitals')
            ->setSearchFields(['id', 'name'])
            ->setDefaultSort(['id' => 'ASC']);
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
        yield TextField::new('name', 'Name');
        yield AssociationField::new('owner', 'Owner');
        yield AssociationField::new('dispatchArea', 'Dispatch Area');
        yield AssociationField::new('state', 'State');

        yield TextField::new('address.street', 'Street')
            ->hideOnIndex();
        yield TextField::new('address.postalCode', 'Postal code')
            ->hideOnIndex();
        yield TextField::new('address.city', 'City')
            ->hideOnIndex();
        yield TextField::new('address.state', 'State')
            ->hideOnIndex();
        yield TextField::new('address.country', 'Country')
            ->hideOnIndex();

        yield ChoiceField::new('location', 'Location')
            ->setChoices([
                'Urban' => HospitalLocation::URBAN,
                'Mixed' => HospitalLocation::MIXED,
                'Rural' => HospitalLocation::RURAL,
            ])
            ->hideOnIndex();
        yield ChoiceField::new('tier', 'Tier')
            ->setChoices([
                'Basic' => HospitalTier::BASIC,
                'Extended' => HospitalTier::EXTENDED,
                'Full' => HospitalTier::FULL,
            ])
            ->hideOnIndex();
        yield ChoiceField::new('size', 'Size')
            ->setChoices([
                'Small' => HospitalSize::SMALL,
                'Medium' => HospitalSize::MEDIUM,
                'Large' => HospitalSize::LARGE,
            ])
            ->hideOnIndex();
        yield IntegerField::new('beds', 'Beds')
            ->hideOnIndex();
        yield BooleanField::new('isParticipating', 'Participating');

        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield AssociationField::new('createdBy', 'Created by')
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Updated by')
            ->onlyOnDetail();
    }
}
