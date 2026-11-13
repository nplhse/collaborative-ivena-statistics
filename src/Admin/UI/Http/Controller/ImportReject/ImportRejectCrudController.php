<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\ImportReject;

use App\Import\Domain\Entity\ImportReject;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<ImportReject>
 */
#[IsGranted('ROLE_ADMIN')]
final class ImportRejectCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return ImportReject::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.import_reject.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.import_reject.entity.plural', domain: 'admin'))
            ->setSearchFields(['id', 'lineNumber', 'import.name', 'import.hospital.name'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX);
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('import', new TranslatableMessage('label.import', domain: 'admin')))
            ->add(NumericFilter::new('lineNumber', new TranslatableMessage('admin.import_reject.field.line', domain: 'admin')))
            ->add(DateTimeFilter::new('createdAt', new TranslatableMessage('label.created', domain: 'messages')));
    }

    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $searchTerm = trim($searchDto->getQuery());
        if ('' !== $searchTerm) {
            // Keep search behavior close to legacy backend: free-text match on reject messages JSON.
            $qb->orWhere('LOWER(CAST_TEXT(entity.messages)) LIKE :messagesSearch')
                ->setParameter('messagesSearch', '%'.mb_strtolower($searchTerm).'%');
        }

        return $qb;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();

        yield AssociationField::new('import', new TranslatableMessage('label.import', domain: 'admin'));
        yield TextField::new('import', new TranslatableMessage('admin.hospital.entity.singular', domain: 'admin'))
            ->setSortable(false)
            ->formatValue(static fn (mixed $value, ImportReject $reject): string => $reject->getImport()?->getHospital()?->getName() ?? '—');

        yield IntegerField::new('lineNumber', new TranslatableMessage('admin.import_reject.field.line', domain: 'admin'));
        yield TextField::new('import', new TranslatableMessage('admin.import_reject.field.messages', domain: 'admin'))
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(static fn (mixed $value, ImportReject $reject): string => self::buildMessageBadges($reject->getMessages()));

        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yy HH:mm');

        yield ArrayField::new('messages', new TranslatableMessage('admin.import_reject.field.messages', domain: 'admin'))
            ->onlyOnDetail();
        yield TextField::new('import', new TranslatableMessage('admin.import_reject.field.row_json', domain: 'admin'))
            ->onlyOnDetail()
            ->setSortable(false)
            ->setTemplatePath('@Admin/crud/field/json_pre.html.twig')
            ->formatValue(static fn (mixed $value, ImportReject $reject): string => json_encode(
                $reject->getRow(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
    }

    /**
     * @param array<mixed> $messages
     */
    private static function buildMessageBadges(array $messages): string
    {
        if ([] === $messages) {
            return 'No messages';
        }

        $categories = self::extractMessageCategories($messages);
        if ([] === $categories) {
            return sprintf('%d errors', \count($messages));
        }

        $shown = array_slice($categories, 0, 4);
        $badges = array_map(
            static fn (string $category): string => sprintf('<span class="badge bg-secondary me-1">%s</span>', htmlspecialchars($category, ENT_QUOTES)),
            $shown
        );
        $hiddenCount = \count($categories) - \count($shown);
        if ($hiddenCount > 0) {
            $badges[] = sprintf('<span class="badge bg-secondary-lt">+%d</span>', $hiddenCount);
        }

        return implode('', $badges);
    }

    /**
     * @param array<mixed> $messages
     *
     * @return list<string>
     */
    private static function extractMessageCategories(array $messages): array
    {
        $categories = [];

        foreach ($messages as $message) {
            if (!\is_scalar($message)) {
                continue;
            }

            $raw = trim((string) $message);
            if ('' === $raw) {
                continue;
            }

            if (str_contains($raw, ':')) {
                $key = trim(explode(':', $raw, 2)[0]);
            } elseif (str_contains($raw, '|')) {
                $key = trim(explode('|', $raw, 2)[0]);
            } else {
                $parts = preg_split('/\s+/', $raw) ?: [];
                $key = '' !== ($parts[0] ?? '') ? trim($parts[0]) : $raw;
            }

            if ('' !== $key && !\in_array($key, $categories, true)) {
                $categories[] = $key;
            }
        }

        return $categories;
    }
}
