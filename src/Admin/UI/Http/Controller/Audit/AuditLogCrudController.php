<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Audit;

use App\Admin\UI\Http\Controller\DashboardController;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\ActionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<AuditEntry>
 */
#[IsGranted('ROLE_ADMIN')]
final class AuditLogCrudController extends AbstractCrudController
{
    private const int JSON_PREVIEW_MAX = 120_000;
    private const int INDEX_CHANGES_PREVIEW_KEYS = 4;

    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return AuditEntry::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Audit entry')
            ->setEntityLabelInPlural('Audit log')
            ->setPageTitle(Crud::PAGE_INDEX, 'Audit log')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Audit entry')
            ->setDefaultSort(['occurredAt' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setSearchFields(['requestId', 'entityClass', 'entityId', 'action', 'origin']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $timeRangeGroup = ActionGroup::new('audit_time_range', 'Time range', 'fas fa-calendar')
            ->createAsGlobalActionGroup()
            ->addAction(Action::new('auditLast24h', 'Last 24 hours', 'fas fa-calendar-day')
                ->linkToUrl(fn (): string => $this->indexUrlSince(new \DateTimeImmutable('-24 hours'))))
            ->addAction(Action::new('auditLast7d', 'Last 7 days', 'fas fa-calendar-week')
                ->linkToUrl(fn (): string => $this->indexUrlSince(new \DateTimeImmutable('-7 days'))))
            ->addAction(Action::new('auditLast30d', 'Last 30 days', 'fas fa-calendar')
                ->linkToUrl(fn (): string => $this->indexUrlSince(new \DateTimeImmutable('-30 days'))));

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $timeRangeGroup)
            ->add(Crud::PAGE_DETAIL, Action::new('auditSameRequest', 'All in this request', 'fas fa-stream')
                ->linkToUrl(fn (AuditEntry $entry): string => $this->indexUrlWithFilters([
                    'requestId' => [
                        'comparison' => ComparisonType::EQ,
                        'value' => $entry->getRequestId(),
                    ],
                ])))
            ->add(Crud::PAGE_DETAIL, Action::new('auditSameEntity', 'History for this record', 'fas fa-clock-rotate-left')
                ->displayIf(fn (AuditEntry $entry): bool => null !== $entry->getEntityId() && '' !== $entry->getEntityId())
                ->linkToUrl(fn (AuditEntry $entry): string => $this->indexUrlWithFilters([
                    'entityClass' => [
                        'comparison' => ComparisonType::EQ,
                        'value' => $entry->getEntityClass(),
                    ],
                    'entityId' => [
                        'comparison' => ComparisonType::EQ,
                        'value' => (string) $entry->getEntityId(),
                    ],
                ])));
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('occurredAt', 'Time'))
            ->add(ChoiceFilter::new('action', 'Action')->setChoices([
                'Create' => 'create',
                'Update' => 'update',
                'Delete' => 'delete',
            ]))
            ->add(ChoiceFilter::new('origin', 'Origin')->setChoices([
                'HTTP' => 'http',
                'CLI' => 'cli',
                'Messenger' => 'messenger',
            ]))
            ->add(TextFilter::new('requestId', 'Request ID'))
            ->add(TextFilter::new('entityClass', 'Entity class (substring)'))
            ->add(EntityFilter::new('actor', 'Actor'));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();

        yield DateTimeField::new('occurredAt', 'Time')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->setSortable(true);

        yield TextField::new('intentForIndex', 'Intent')
            ->onlyOnIndex()
            ->setValue('')
            ->formatValue(static function (mixed $_, AuditEntry $entity): string {
                $meta = $entity->getMetadata();
                if (!\is_array($meta) || !isset($meta['intent']) || !\is_string($meta['intent'])) {
                    return '';
                }

                return $meta['intent'];
            });

        yield TextField::new('changedFieldsSummaryForIndex', 'Changed fields')
            ->onlyOnIndex()
            ->setValue('')
            ->formatValue(static fn (mixed $_, AuditEntry $entity): string => self::summarizeChangesForIndex($entity));

        yield TextField::new('action', 'Action')
            ->setSortable(true);

        yield TextField::new('origin', 'Origin')
            ->setSortable(true);

        yield TextField::new('requestId', 'Request ID')
            ->setSortable(true);

        yield TextField::new('entityClass', 'Entity')
            ->formatValue(static function (mixed $value): string {
                if (!\is_string($value) || '' === $value) {
                    return '';
                }

                $pos = strrpos($value, '\\');

                return false === $pos ? $value : substr($value, $pos + 1);
            });

        yield TextField::new('entityId', 'Entity ID');

        yield AssociationField::new('actor', 'Actor');

        // Virtual fields: JSON arrays are rejected by EasyAdmin TextField before formatValue; empty string avoids the "inaccessible" template.
        yield TextField::new('changesForAdminDisplay', 'Changes')
            ->onlyOnDetail()
            ->renderAsHtml()
            ->setTemplatePath('@Admin/crud/field/audit_rich.html.twig')
            ->setValue('')
            ->formatValue(static fn (mixed $_, AuditEntry $entity): string => self::formatChangesBlock($entity));

        yield TextField::new('metadataForAdminDisplay', 'Metadata')
            ->onlyOnDetail()
            ->renderAsHtml()
            ->setTemplatePath('@Admin/crud/field/audit_rich.html.twig')
            ->setValue('')
            ->formatValue(static fn (mixed $_, AuditEntry $entity): string => self::formatMetadataBlock($entity));
    }

    private static function formatChangesBlock(AuditEntry $entity): string
    {
        $changes = $entity->getChanges();
        if ([] === $changes) {
            return '<p class="text-body-secondary mb-0"><em>No field changes</em></p>';
        }

        if (!self::isOldNewChangeSetShape($changes)) {
            return self::wrapJsonPanel(self::encodeJsonPretty($changes));
        }

        $blocks = '';
        foreach ($changes as $field => $delta) {
            /** @var array{old: mixed, new: mixed} $delta */
            $fieldEsc = self::h($field);
            $blocks .= '<div class="audit-diff-field card mb-3 shadow-sm overflow-hidden">'
                .'<div class="card-header py-2 px-3 font-monospace small fw-semibold bg-body-tertiary border-bottom">'.$fieldEsc.'</div>'
                .'<div class="card-body p-0">'
                .'<div class="border-bottom bg-body-secondary bg-opacity-25 px-3 py-2">'
                .'<div class="text-body-secondary text-uppercase fw-semibold small mb-2" style="letter-spacing: .03em;">Previous</div>'
                .'<div class="small">'.self::formatAuditScalarOrStructured($delta['old']).'</div>'
                .'</div>'
                .'<div class="px-3 py-2">'
                .'<div class="text-body-secondary text-uppercase fw-semibold small mb-2" style="letter-spacing: .03em;">New</div>'
                .'<div class="small">'.self::formatAuditScalarOrStructured($delta['new']).'</div>'
                .'</div>'
                .'</div></div>';
        }

        return '<div class="audit-diff-stack">'.$blocks.'</div>';
    }

    private static function formatMetadataBlock(AuditEntry $entity): string
    {
        $meta = $entity->getMetadata();
        if (null === $meta || [] === $meta) {
            return '<p class="text-body-secondary mb-0"><em>—</em></p>';
        }

        $blocks = [];
        if (isset($meta['intent']) && \is_string($meta['intent']) && '' !== $meta['intent']) {
            $blocks[] = '<div class="mb-3"><span class="text-body-secondary me-2">Intent</span>'
                .'<span class="badge text-bg-primary">'.self::h($meta['intent']).'</span></div>';
        }

        $rest = $meta;
        unset($rest['intent'], $rest['intent_metadata']);

        if ([] !== $rest) {
            $blocks[] = self::formatMetadataDl($rest);
        }

        if (isset($meta['intent_metadata']) && \is_array($meta['intent_metadata']) && [] !== $meta['intent_metadata']) {
            $blocks[] = '<div class="mt-3"><div class="text-body-secondary small mb-1">Intent metadata</div>'
                .self::wrapJsonPanel(self::encodeJsonPretty($meta['intent_metadata'])).'</div>';
        }

        if ([] === $blocks) {
            return self::wrapJsonPanel(self::encodeJsonPretty($meta));
        }

        return '<div class="rounded border bg-body-tertiary p-3 mb-0">'.implode('', $blocks).'</div>';
    }

    /**
     * @param array<string, mixed> $rows
     */
    private static function formatMetadataDl(array $rows): string
    {
        $html = '';
        foreach ($rows as $key => $value) {
            $html .= '<dt class="col-sm-3 text-body-secondary small">'.self::h($key).'</dt>'
                .'<dd class="col-sm-9 small mb-2">'.self::formatAuditScalarOrStructured($value).'</dd>';
        }

        return '<dl class="row mb-0">'.$html.'</dl>';
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function isOldNewChangeSetShape(array $changes): bool
    {
        return array_all($changes, fn ($delta): bool => !(!\is_array($delta) || !\array_key_exists('old', $delta) || !\array_key_exists('new', $delta)));
    }

    private static function formatAuditScalarOrStructured(mixed $value): string
    {
        if (null === $value) {
            return '<span class="text-muted">—</span>';
        }

        if (\is_bool($value)) {
            return $value
                ? '<span class="badge rounded-pill text-bg-success">true</span>'
                : '<span class="badge rounded-pill text-bg-secondary">false</span>';
        }

        if (\is_int($value) || \is_float($value)) {
            return '<code class="user-select-all">'.self::h((string) $value).'</code>';
        }

        if (\is_string($value)) {
            if ('' === $value) {
                return '<span class="text-muted">(empty string)</span>';
            }

            return '<span class="text-break">'.self::h($value).'</span>';
        }

        if (\is_array($value)) {
            return self::wrapJsonPanel(self::encodeJsonPretty($value), '12rem');
        }

        $encoded = json_encode($value);
        $text = \is_string($encoded) ? $encoded : '';

        return '<code>'.self::h($text).'</code>';
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encodeJsonPretty(array $value): string
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            return '(invalid JSON data)';
        }

        if (strlen($json) > self::JSON_PREVIEW_MAX) {
            return substr($json, 0, self::JSON_PREVIEW_MAX)."\n… (truncated)";
        }

        return $json;
    }

    private static function wrapJsonPanel(string $json, string $maxHeight = '28rem'): string
    {
        if ('(invalid JSON data)' === $json || '' === $json) {
            return '<p class="text-warning mb-0">'.self::h($json).'</p>';
        }

        return '<pre class="mb-0 small font-monospace rounded border bg-white p-3 overflow-auto shadow-sm user-select-all text-break" '
            .'style="max-height: '.$maxHeight.'; white-space: pre-wrap; word-break: break-word;">'.self::h($json).'</pre>';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function indexUrlSince(\DateTimeImmutable $from): string
    {
        return $this->indexUrlWithFilters([
            'occurredAt' => [
                'comparison' => ComparisonType::GTE,
                'value' => $from->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * @param array<string, array{comparison: string, value?: mixed, value2?: mixed}> $filters
     */
    private function indexUrlWithFilters(array $filters): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set(EA::FILTERS, $filters)
            ->generateUrl();
    }

    private static function summarizeChangesForIndex(AuditEntry $entity): string
    {
        $changes = $entity->getChanges();
        if ([] === $changes) {
            return '—';
        }

        /** @var list<string> $keys */
        $keys = array_keys($changes);
        if (!self::isOldNewChangeSetShape($changes)) {
            return sprintf('(%d keys)', \count($keys));
        }

        $n = \count($keys);
        $shown = array_slice($keys, 0, self::INDEX_CHANGES_PREVIEW_KEYS);
        $preview = implode(', ', $shown);

        return $n > self::INDEX_CHANGES_PREVIEW_KEYS
            ? sprintf('%s … · %d fields', $preview, $n)
            : sprintf('%s · %d %s', $preview, $n, 1 === $n ? 'field' : 'fields');
    }
}
