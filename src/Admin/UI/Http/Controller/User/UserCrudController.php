<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\User;

use App\Shared\Application\Locale\SupportedLocales;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $this->createImpersonateAction())
            ->add(Crud::PAGE_DETAIL, $this->createImpersonateAction())
            ->add(Crud::PAGE_EDIT, Action::INDEX);
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
        yield IdField::new('id')
            ->onlyOnDetail();
        yield TextField::new('username');
        yield TextField::new('email');
        yield BooleanField::new('isVerified');
        yield BooleanField::new('credentialsExpired');
        yield BooleanField::new('isEnabled')
            ->renderAsSwitch();
        yield ChoiceField::new('locale', 'Locale')
            ->setChoices([
                'English' => SupportedLocales::DEFAULT,
                'German' => SupportedLocales::GERMAN,
            ])
            ->setRequired(false);
        yield BooleanField::new('receivesMonthlySubmissionReminder', 'Monthly submission reminder')
            ->renderAsSwitch();
        yield AssociationField::new('hospitals', 'Owned hospitals')
            ->hideOnForm();
        yield ChoiceField::new('roles')
            ->setChoices([
                'Admin' => UserRole::ADMIN,
                'Participant' => UserRole::PARTICIPANT,
                'User' => UserRole::USER,
                'Receives Feedback' => UserRole::FEEDBACK_RECIPIENT,
                'Receives notifications' => UserRole::RECEIVES_NOTIFICATION,
                'Reviews indications' => UserRole::REVIEW_INDICATIONS,
            ])
            ->allowMultipleChoices()
            ->renderAsBadges([
                UserRole::ADMIN => 'danger',
                UserRole::PARTICIPANT => 'warning',
                UserRole::USER => 'primary',
                UserRole::FEEDBACK_RECIPIENT => 'success',
                UserRole::RECEIVES_NOTIFICATION => 'info',
                UserRole::REVIEW_INDICATIONS => 'secondary',
            ]);
        yield TextField::new('password')
            ->setFormType(PasswordType::class)
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp('On edit leave empty to keep the current password.')
            ->setFormTypeOption('empty_data', '')
            ->onlyOnForms();
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
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            return;
        }

        $this->auditContext->beginIntent('user.admin.updated', ['source' => 'easyadmin']);
        try {
            $currentUser = $this->security->getUser();
            if (
                $currentUser instanceof User
                && $entityInstance->getId() === $currentUser->getId()
                && !$entityInstance->isEnabled()
            ) {
                throw new \LogicException('You cannot disable your own account.');
            }

            $plainPassword = $entityInstance->getPassword() ?? '';
            if ('' === $plainPassword) {
                $originalData = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
                $originalPassword = $originalData['password'] ?? null;

                if (!\is_string($originalPassword) || '' === $originalPassword) {
                    $userId = $entityInstance->getId();
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
                    $entityInstance->setPassword($originalPassword);
                } else {
                    throw new \LogicException('Could not preserve existing password while updating user.');
                }
            } else {
                $entityInstance->setPassword($this->passwordHasher->hashPassword($entityInstance, $plainPassword));
            }

            parent::updateEntity($entityManager, $entityInstance);
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
