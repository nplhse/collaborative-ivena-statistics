<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Hospital;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderSender;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
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
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractCrudController<Hospital>
 */
#[IsGranted('ROLE_ADMIN')]
final class HospitalCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AuditContext $auditContext,
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Hospital::class;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->auditContext->beginIntent('hospital.admin.created', ['source' => 'easyadmin']);
        try {
            parent::persistEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->auditContext->beginIntent('hospital.admin.updated', ['source' => 'easyadmin']);
        try {
            parent::updateEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }
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
        $sendReminder = Action::new('sendMonthlyReminder', 'admin.hospital.action.send_reminder', 'fas fa-envelope')
            ->linkToCrudAction('sendMonthlyReminder')
            ->displayIf(static fn (Hospital $hospital): bool => $hospital->isParticipating() && $hospital->getOwner() instanceof \App\User\Domain\Entity\User)
            ->askConfirmation(new TranslatableMessage('admin.hospital.action.send_reminder.confirm', domain: 'admin'));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_DETAIL, $sendReminder);
    }

    #[AdminRoute(path: '/{entityId}/send-monthly-reminder', name: 'send_monthly_reminder')]
    public function sendMonthlyReminder(Hospital $hospital, MonthlyReminderSender $monthlyReminderSender): RedirectResponse
    {
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
                ->setEntityId($hospital->getId())
                ->generateUrl(),
        );
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
