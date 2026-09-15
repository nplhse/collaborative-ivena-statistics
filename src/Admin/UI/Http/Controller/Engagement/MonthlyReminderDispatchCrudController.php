<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Engagement;

use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderSender;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\User\Domain\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractCrudController<MonthlyReminderDispatch>
 */
#[IsGranted('ROLE_ADMIN')]
final class MonthlyReminderDispatchCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

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
        $sendReminder = Action::new(
            'sendMonthlyReminder',
            new TranslatableMessage('admin.hospital.action.send_reminder', domain: 'admin'),
            'fas fa-envelope',
        )
            ->linkToCrudAction('sendMonthlyReminder')
            ->displayIf(static function (MonthlyReminderDispatch $dispatch): bool {
                $hospital = $dispatch->getHospital();

                return $hospital->isParticipating() && $hospital->getOwner() instanceof User;
            })
            ->askConfirmation(new TranslatableMessage('admin.hospital.action.send_reminder.confirm', domain: 'admin'));

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $sendReminder)
            ->add(Crud::PAGE_DETAIL, $sendReminder);
    }

    #[AdminRoute(path: '/{entityId}/send-monthly-reminder', name: 'send_monthly_reminder')]
    public function sendMonthlyReminder(
        #[MapEntity(id: 'entityId')] MonthlyReminderDispatch $dispatch,
        MonthlyReminderSender $monthlyReminderSender,
    ): RedirectResponse {
        $hospital = $dispatch->getHospital();
        $errors = $monthlyReminderSender->sendForHospital($hospital, MonthlyReminderTrigger::Admin);
        if ([] === $errors) {
            $this->addFlash('success', new TranslatableMessage('flash.admin.hospital.reminder.sent', domain: 'admin'));
        } else {
            $this->addFlash('error', $this->translator->trans($errors[0], [], 'engagement'));
        }

        return $this->redirect(
            $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($dispatch->getId())
                ->generateUrl(),
        );
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
            ->add(ChoiceFilter::new('status', 'Status')->setChoices([
                'Queued' => MonthlyReminderDispatchStatus::Queued->value,
                'Sent' => MonthlyReminderDispatchStatus::Sent->value,
                'Failed' => MonthlyReminderDispatchStatus::Failed->value,
            ]))
            ->add(DateTimeFilter::new('sentAt', 'Queued at'));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.dispatch', domain: 'admin'));
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
        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'Queued' => MonthlyReminderDispatchStatus::Queued->value,
                'Sent' => MonthlyReminderDispatchStatus::Sent->value,
                'Failed' => MonthlyReminderDispatchStatus::Failed->value,
            ])
            ->renderAsBadges([
                MonthlyReminderDispatchStatus::Queued->value => 'info',
                MonthlyReminderDispatchStatus::Sent->value => 'success',
                MonthlyReminderDispatchStatus::Failed->value => 'danger',
            ]);
        yield TextField::new('recipientEmail', 'Recipient')
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
        yield DateTimeField::new('sentAt', 'Queued at')
            ->setFormat('dd.MM.yyyy HH:mm');
        yield DateTimeField::new('deliveredAt', 'Delivered at')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield TextareaField::new('failureReason', 'Failure reason')
            ->onlyOnDetail()
            ->renderAsHtml(false);
    }
}
