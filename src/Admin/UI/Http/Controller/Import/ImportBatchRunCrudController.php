<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Import;

use App\Import\Domain\Entity\ImportBatchRun;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<ImportBatchRun>
 */
#[IsGranted('ROLE_ADMIN')]
final class ImportBatchRunCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return ImportBatchRun::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Import batch run')
            ->setEntityLabelInPlural('Import batch runs')
            ->setDefaultSort(['startedAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();
        yield ChoiceField::new('status', 'Status')
            ->renderAsBadges();
        yield DateTimeField::new('startedAt', 'Started')
            ->setFormat('dd.MM.yyyy HH:mm');
        yield DateTimeField::new('finishedAt', 'Finished')
            ->setFormat('dd.MM.yyyy HH:mm');
        yield DateTimeField::new('createdAt', 'Created')
            ->onlyOnDetail();
        yield TextField::new('optionsPreview', 'Options')
            ->onlyOnDetail()
            ->setValue('')
            ->formatValue(static function (mixed $_, ImportBatchRun $run): string {
                $encoded = json_encode(
                    $run->getOptions(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
                );

                return \is_string($encoded) ? $encoded : '';
            });
        yield AssociationField::new('items', 'Items')
            ->onlyOnDetail();
    }
}
