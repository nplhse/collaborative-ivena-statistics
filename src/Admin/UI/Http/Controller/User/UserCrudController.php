<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\User;

use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
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
            ->setSearchFields(['id', 'username'])
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

            if (\is_string($originalPassword) && '' !== $originalPassword) {
                $entityInstance->setPassword($originalPassword);
            }
        } else {
            $entityInstance->setPassword($this->passwordHasher->hashPassword($entityInstance, $plainPassword));
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}
