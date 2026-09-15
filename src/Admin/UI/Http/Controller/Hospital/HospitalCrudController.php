<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Hospital;

use App\Admin\Application\Service\HospitalPermissionLabelFormatter;
use App\Admin\UI\Http\Controller\Engagement\MonthlyReminderDispatchCrudController;
use App\Admin\UI\Http\Controller\User\UserCrudController;
use App\Allocation\Application\Hospital\HospitalRelationNotifier;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Doctrine\OriginalEntityUser;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderSender;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
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
        private readonly HospitalRelationNotifier $hospitalRelationNotifier,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Hospital::class;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->auditContext->beginIntent('hospital.admin.created', ['source' => 'easyadmin']);
        try {
            parent::persistEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }

        $this->hospitalRelationNotifier->ownershipChanged(null, $entityInstance->getOwner(), $entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $previousOwner = OriginalEntityUser::from($entityManager, $entityInstance, 'owner');

        $this->auditContext->beginIntent('hospital.admin.updated', ['source' => 'easyadmin']);
        try {
            parent::updateEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }

        $this->hospitalRelationNotifier->ownershipChanged($previousOwner, $entityInstance->getOwner(), $entityInstance);
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
        $sendReminder = Action::new(
            'sendMonthlyReminder',
            new TranslatableMessage('admin.hospital.action.send_reminder', domain: 'admin'),
            'fas fa-envelope',
        )
            ->linkToCrudAction('sendMonthlyReminder')
            ->displayIf(static fn (Hospital $hospital): bool => $hospital->isParticipating() && $hospital->getOwner() instanceof \App\User\Domain\Entity\User)
            ->askConfirmation(new TranslatableMessage('admin.hospital.action.send_reminder.confirm', domain: 'admin'));

        $reminderHistory = Action::new(
            'reminderHistory',
            new TranslatableMessage('admin.hospital.action.reminder_history', domain: 'admin'),
            'fas fa-clock-rotate-left',
        )
            ->linkToUrl(fn (Hospital $hospital): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(MonthlyReminderDispatchCrudController::class)
                ->setAction(Action::INDEX)
                ->set(EA::FILTERS, [
                    'hospital' => [
                        'comparison' => ComparisonType::EQ,
                        'value' => $hospital->getId(),
                    ],
                ])
                ->generateUrl());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_DETAIL, $sendReminder)
            ->add(Crud::PAGE_DETAIL, $reminderHistory);
    }

    #[AdminRoute(path: '/{entityId}/send-monthly-reminder', name: 'send_monthly_reminder')]
    public function sendMonthlyReminder(
        #[MapEntity(id: 'entityId')] Hospital $hospital,
        MonthlyReminderSender $monthlyReminderSender,
    ): RedirectResponse {
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
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.identity', domain: 'admin'));
        yield TextField::new('name', 'Name');
        yield AssociationField::new('owner', 'Owner');
        yield AssociationField::new('dispatchArea', 'Dispatch Area');
        yield AssociationField::new('state', 'State');

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.address', domain: 'admin'));
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

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.classification', domain: 'admin'));
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
        yield NumberField::new('latitude', 'Latitude')
            ->hideOnIndex();
        yield NumberField::new('longitude', 'Longitude')
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.participation', domain: 'admin'));
        yield BooleanField::new('isParticipating', 'Participating');
        yield DateTimeField::new('participatingSince', 'Participating since')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.access', domain: 'admin'));
        yield AssociationField::new('accessGrants', 'Access grants')
            ->onlyOnDetail()
            ->setSortable(false)
            ->renderAsHtml()
            ->formatValue(fn (mixed $_, Hospital $hospital): string => $this->formatAccessGrantsHtml($hospital));

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield AssociationField::new('createdBy', 'Created by')
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Updated by')
            ->onlyOnDetail();
    }

    private function formatAccessGrantsHtml(Hospital $hospital): string
    {
        $parts = [];
        foreach ($hospital->getAccessGrants() as $grant) {
            $user = $grant->getUser();
            $userId = $user?->getId();
            if (null === $user || null === $userId) {
                continue;
            }

            $url = $this->adminUrlGenerator
                ->unsetAll()
                ->setController(UserCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($userId)
                ->generateUrl();
            $name = htmlspecialchars((string) $user->getUsername(), ENT_QUOTES);
            $permissions = htmlspecialchars(HospitalPermissionLabelFormatter::formatMask($grant->getPermissions()), ENT_QUOTES);
            $parts[] = sprintf('<a href="%s">%s</a> (%s)', htmlspecialchars($url, ENT_QUOTES), $name, $permissions);
        }

        return [] === $parts ? '—' : implode('<br>', $parts);
    }
}
