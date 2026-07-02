<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Engagement;

use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<MonthlyReminderDispatch>
 */
#[IsGranted('ROLE_ADMIN')]
final class MonthlyReminderDispatchCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return MonthlyReminderDispatch::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Monthly reminder dispatch')
            ->setEntityLabelInPlural('Monthly reminder dispatches')
            ->setPageTitle(Crud::PAGE_INDEX, new TranslatableMessage('ops.reminder_dispatch.title', domain: 'admin'))
            ->setHelp(Crud::PAGE_INDEX, new TranslatableMessage('ops.reminder_dispatch.help', domain: 'admin'))
            ->setSearchFields(['reportingPeriod', 'trigger', 'hospital.name'])
            ->setDefaultSort(['sentAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('hospital', 'Hospital'))
            ->add(ChoiceFilter::new('trigger', 'Trigger')->setChoices([
                'Scheduler' => MonthlyReminderTrigger::Scheduler->value,
                'Admin' => MonthlyReminderTrigger::Admin->value,
                'CLI' => MonthlyReminderTrigger::Cli->value,
            ]))
            ->add(DateTimeFilter::new('sentAt', 'Sent at'));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();
        yield AssociationField::new('hospital', 'Hospital');
        yield TextField::new('reportingPeriod', 'Reporting period');
        yield ChoiceField::new('trigger', 'Trigger')
            ->setChoices([
                'Scheduler' => MonthlyReminderTrigger::Scheduler->value,
                'Admin' => MonthlyReminderTrigger::Admin->value,
                'CLI' => MonthlyReminderTrigger::Cli->value,
            ])
            ->renderAsBadges([
                MonthlyReminderTrigger::Scheduler->value => 'primary',
                MonthlyReminderTrigger::Admin->value => 'warning',
                MonthlyReminderTrigger::Cli->value => 'secondary',
            ]);
        yield DateTimeField::new('sentAt', 'Sent at')
            ->setFormat('dd.MM.yyyy HH:mm');
    }
}
