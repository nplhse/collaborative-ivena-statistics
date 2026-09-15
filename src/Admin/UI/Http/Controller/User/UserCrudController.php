<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\User;

use App\Admin\Application\Service\HospitalPermissionLabelFormatter;
use App\Admin\UI\Http\Controller\Hospital\HospitalAccessGrantCrudController;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use App\Allocation\Infrastructure\Repository\HospitalAccessGrantRepository;
use App\Shared\Application\Locale\SupportedLocales;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Application\Event\UserBecameParticipant;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityRemoveException;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @extends AbstractCrudController<User>
 */
#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditContext $auditContext,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly HospitalAccessGrantRepository $hospitalAccessGrantRepository,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setSearchFields(['id', 'username', 'email'])
            ->setDefaultSort(['username' => 'ASC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $this->createImpersonateAction())
            ->add(Crud::PAGE_DETAIL, $this->createImpersonateAction())
            ->add(Crud::PAGE_DETAIL, $this->createAccessGrantsAction())
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_EDIT, Action::DELETE)
            ->update(Crud::PAGE_INDEX, Action::DELETE, $this->withUserDeleteVisibility(...))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $this->withUserDeleteVisibility(...))
            ->update(Crud::PAGE_EDIT, Action::DELETE, $this->withUserDeleteVisibility(...));
    }

    #[\Override]
    public function delete(AdminContext $context): KeyValueStore|Response
    {
        $csrfToken = $context->getRequest()->request->has('token')
            ? (string) $context->getRequest()->request->get('token')
            : null;
        if (!$this->isCsrfTokenValid('ea-delete', $csrfToken)) {
            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        $entityInstance = $context->getEntity()->getInstance();
        if (!$entityInstance instanceof User) {
            return $this->redirectBlockedUserDelete();
        }

        if ($this->isCurrentUser($entityInstance)) {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.user.delete.self', domain: 'admin'));

            return $this->redirectToUserIndex();
        }

        if (!$entityInstance->getHospitals()->isEmpty()) {
            return $this->redirectBlockedUserDelete();
        }

        try {
            return parent::delete($context);
        } catch (EntityRemoveException) {
            return $this->redirectBlockedUserDelete();
        }
    }

    #[\Override]
    public function batchDelete(AdminContext $context, BatchActionDto $batchActionDto): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('ea-batch-action-'.Action::BATCH_DELETE.'-'.$batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        return $this->redirectBlockedUserDelete();
    }

    private function withUserDeleteVisibility(Action $action): Action
    {
        return $action->displayIf(fn (User $user): bool => $this->canOfferUserDelete($user));
    }

    private function canOfferUserDelete(User $user): bool
    {
        if ($this->isCurrentUser($user)) {
            return false;
        }

        return $user->getHospitals()->isEmpty();
    }

    private function isCurrentUser(User $user): bool
    {
        $currentUser = $this->security->getUser();

        return $currentUser instanceof User && $user->getId() === $currentUser->getId();
    }

    private function redirectBlockedUserDelete(): RedirectResponse
    {
        $this->addFlash('danger', new TranslatableMessage('flash.admin.user.delete.blocked', domain: 'admin'));

        return $this->redirectToUserIndex();
    }

    private function redirectToUserIndex(): RedirectResponse
    {
        return $this->redirect(
            $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset(EA::ENTITY_ID)
                ->generateUrl(),
        );
    }

    private function createAccessGrantsAction(): Action
    {
        return Action::new(
            'accessGrants',
            new TranslatableMessage('admin.user.action.access_grants', domain: 'admin'),
            'fas fa-hospital-user',
        )
            ->linkToUrl(fn (User $user): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(HospitalAccessGrantCrudController::class)
                ->setAction(Action::INDEX)
                ->set(EA::FILTERS, [
                    'user' => [
                        'comparison' => ComparisonType::EQ,
                        'value' => $user->getId(),
                    ],
                ])
                ->generateUrl());
    }

    private function createImpersonateAction(): Action
    {
        return Action::new('impersonate', 'label.impersonate', 'fas fa-user-secret')
            ->linkToUrl(fn (User $user): string => $this->urlGenerator->generate('app_default', [
                '_switch_user' => $user->getUserIdentifier(),
            ]))
            ->displayIf(function (User $user): bool {
                if (!$user->isEnabled()) {
                    return false;
                }

                if (\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
                    return false;
                }

                $currentUser = $this->security->getUser();
                if (!$currentUser instanceof User) {
                    return false;
                }

                return $user->getId() !== $currentUser->getId();
            });
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.account', domain: 'admin'));
        yield TextField::new('username');
        yield TextField::new('email');
        yield ChoiceField::new('locale', 'Locale')
            ->setChoices([
                'English' => SupportedLocales::DEFAULT,
                'German' => SupportedLocales::GERMAN,
            ])
            ->setRequired(false)
            ->hideOnIndex();
        yield TextField::new('password')
            ->setFormType(PasswordType::class)
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp('On edit leave empty to keep the current password.')
            ->setFormTypeOption('empty_data', '')
            ->setFormTypeOption('mapped', Crud::PAGE_NEW === $pageName)
            ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
            ->onlyOnForms();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.permissions', domain: 'admin'));
        yield BooleanField::new('isEnabled')
            ->setHelp(new TranslatableMessage('help.admin.user.is_enabled', domain: 'admin'))
            ->renderAsSwitch();
        yield BooleanField::new('isVerified')
            ->hideOnIndex();
        yield BooleanField::new('credentialsExpired')
            ->hideOnIndex();
        yield BooleanField::new('receivesMonthlySubmissionReminder', 'Monthly submission reminder')
            ->renderAsSwitch()
            ->hideOnIndex();
        yield ChoiceField::new('roles')
            ->setChoices([
                'Admin' => UserRole::ADMIN,
                'Participant' => UserRole::PARTICIPANT,
                'Board Member' => UserRole::BOARD_MEMBER,
                'User' => UserRole::USER,
                'Receives Feedback' => UserRole::FEEDBACK_RECIPIENT,
                'Receives notifications' => UserRole::RECEIVES_NOTIFICATION,
                'Reviews indications' => UserRole::REVIEW_INDICATIONS,
            ])
            ->allowMultipleChoices()
            ->renderAsBadges([
                UserRole::ADMIN => 'danger',
                UserRole::PARTICIPANT => 'warning',
                UserRole::BOARD_MEMBER => 'info',
                UserRole::USER => 'primary',
                UserRole::FEEDBACK_RECIPIENT => 'success',
                UserRole::RECEIVES_NOTIFICATION => 'info',
                UserRole::REVIEW_INDICATIONS => 'secondary',
            ]);

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.hospitals', domain: 'admin'));
        yield AssociationField::new('hospitals', 'Owned hospitals')
            ->onlyOnDetail()
            ->setSortable(false)
            ->renderAsHtml()
            ->formatValue(fn (mixed $_, User $user): string => $this->formatOwnedHospitalsHtml($user));
        yield TextField::new('accessGrantsSummary', new TranslatableMessage('admin.user.field.access_grants', domain: 'admin'))
            ->onlyOnDetail()
            ->setVirtual(true)
            ->setSortable(false)
            ->renderAsHtml()
            ->setValue('')
            ->formatValue(fn (mixed $_, User $user): string => $this->formatAccessGrantsHtml($user));

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
    }

    private function formatOwnedHospitalsHtml(User $user): string
    {
        $parts = [];
        foreach ($user->getHospitals() as $hospital) {
            $hospitalId = $hospital->getId();
            if (null === $hospitalId) {
                continue;
            }

            $url = $this->adminUrlGenerator
                ->unsetAll()
                ->setController(HospitalCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($hospitalId)
                ->generateUrl();
            $name = htmlspecialchars((string) $hospital->getName(), ENT_QUOTES);
            $parts[] = sprintf('<a href="%s">%s</a>', htmlspecialchars($url, ENT_QUOTES), $name);
        }

        return [] === $parts ? '—' : implode('<br>', $parts);
    }

    private function formatAccessGrantsHtml(User $user): string
    {
        $grants = $this->hospitalAccessGrantRepository->findForUser($user);
        if ([] === $grants) {
            return '—';
        }

        $parts = [];
        foreach ($grants as $grant) {
            $hospital = $grant->getHospital();
            $hospitalId = $hospital?->getId();
            if (null === $hospital || null === $hospitalId) {
                continue;
            }

            $url = $this->adminUrlGenerator
                ->unsetAll()
                ->setController(HospitalCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($hospitalId)
                ->generateUrl();
            $name = htmlspecialchars((string) $hospital->getName(), ENT_QUOTES);
            $permissions = htmlspecialchars(HospitalPermissionLabelFormatter::formatMask($grant->getPermissions()), ENT_QUOTES);
            $parts[] = sprintf('<a href="%s">%s</a> (%s)', htmlspecialchars($url, ENT_QUOTES), $name, $permissions);
        }

        return [] === $parts ? '—' : implode('<br>', $parts);
    }

    #[\Override]
    public function createEntity(string $entityFqcn): User
    {
        $user = new User();
        $user->setCredentialsExpired(true);
        $user->setIsEnabled(true);

        return $user;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            return;
        }

        $createdAsParticipant = UserRole::containsParticipant($entityInstance->getRoles());
        $this->auditContext->beginIntent('user.admin.created', ['source' => 'easyadmin']);
        try {
            $plainPassword = $entityInstance->getPassword();
            if (null === $plainPassword || '' === $plainPassword) {
                throw new \LogicException('Password must not be empty when creating a user.');
            }

            $entityInstance->setPassword($this->passwordHasher->hashPassword($entityInstance, $plainPassword));

            parent::persistEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }

        $userId = $entityInstance->getId();
        if (null !== $userId) {
            $this->eventDispatcher->dispatch(new UserRegistered($userId));
            if ($createdAsParticipant) {
                $this->eventDispatcher->dispatch(new UserBecameParticipant($userId));
            }
        }
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            return;
        }

        $becameParticipant = false;
        $this->auditContext->beginIntent('user.admin.updated', ['source' => 'easyadmin']);
        try {
            $currentUser = $this->security->getUser();
            if (
                $currentUser instanceof User
                && $entityInstance->getId() === $currentUser->getId()
                && !$entityInstance->isEnabled()
            ) {
                // Discard the in-memory disable before aborting. Rendering the
                // exception page (or any later flush) would otherwise persist it.
                $entityManager->refresh($entityInstance);
                throw new \LogicException('You cannot disable your own account.');
            }

            $this->applyPasswordOnUpdate($entityManager, $entityInstance);

            $originalRoles = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['roles'] ?? [];
            $becameParticipant = !UserRole::containsParticipant($originalRoles)
                && UserRole::containsParticipant($entityInstance->getRoles());

            parent::updateEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }

        if ($becameParticipant) {
            $userId = $entityInstance->getId();
            if (null !== $userId) {
                $this->eventDispatcher->dispatch(new UserBecameParticipant($userId));
            }
        }
    }

    private function applyPasswordOnUpdate(EntityManagerInterface $entityManager, User $user): void
    {
        $submitted = $this->submittedPlainPassword();
        $current = $user->getPassword() ?? '';
        $willHashSubmitted = '' !== $submitted && !$this->looksLikePasswordHash($submitted);

        if ($willHashSubmitted) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $submitted));

            return;
        }

        if ($this->looksLikePasswordHash($current)) {
            return;
        }

        $originalData = $entityManager->getUnitOfWork()->getOriginalEntityData($user);
        $originalPassword = $originalData['password'] ?? null;

        if (!\is_string($originalPassword) || '' === $originalPassword) {
            $userId = $user->getId();
            if (null !== $userId) {
                $storedPassword = $entityManager->getConnection()->fetchOne(
                    'SELECT password FROM "user" WHERE id = :id',
                    ['id' => $userId]
                );
                if (\is_string($storedPassword) && '' !== $storedPassword) {
                    $originalPassword = $storedPassword;
                }
            }
        }

        if (\is_string($originalPassword) && '' !== $originalPassword) {
            $user->setPassword($originalPassword);

            return;
        }

        throw new \LogicException('Could not preserve existing password while updating user.');
    }

    private function submittedPlainPassword(): string
    {
        $request = $this->getContext()?->getRequest();
        if (!$request instanceof Request) {
            return '';
        }

        $formData = $request->request->all('User');
        $password = $formData['password'] ?? null;

        return \is_string($password) ? $password : '';
    }

    private function looksLikePasswordHash(string $value): bool
    {
        return str_starts_with($value, '$2y$')
            || str_starts_with($value, '$2a$')
            || str_starts_with($value, '$2b$')
            || str_starts_with($value, '$argon2id$')
            || str_starts_with($value, '$argon2i$');
    }
}
