<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\User;

use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @extends AbstractCrudController<User>
 */
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
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
            ->add(Crud::PAGE_EDIT, Action::INDEX);
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
        yield ChoiceField::new('roles')
            ->setChoices([
                'Admin' => 'ROLE_ADMIN',
                'User' => 'ROLE_USER',
            ])
            ->allowMultipleChoices()
            ->renderAsBadges([
                'ROLE_ADMIN' => 'danger',
                'ROLE_USER' => 'primary',
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

        return $user;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            return;
        }

        $plainPassword = $entityInstance->getPassword();
        if (null === $plainPassword || '' === $plainPassword) {
            throw new \LogicException('Password must not be empty when creating a user.');
        }

        $entityInstance->setPassword($this->passwordHasher->hashPassword($entityInstance, $plainPassword));

        parent::persistEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            return;
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
    }
}
