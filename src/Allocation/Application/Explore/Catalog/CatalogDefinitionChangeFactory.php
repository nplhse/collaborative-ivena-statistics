<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogDefinitionChange;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Shared\Infrastructure\Audit\Repository\AuditEntryRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CatalogDefinitionChangeFactory
{
    public function __construct(
        private AuditEntryRepository $auditEntryRepository,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<CatalogDefinitionChange>
     */
    public function forIndicationNormalized(IndicationNormalized $indication, int $limit = 8): array
    {
        $id = $indication->getId();
        if (null === $id) {
            return [];
        }

        $entries = $this->auditEntryRepository->findRecentForEntity(IndicationNormalized::class, (string) $id, $limit);
        $changes = [];
        foreach ($entries as $entry) {
            $actor = $entry->getActor();
            $changes[] = new CatalogDefinitionChange(
                occurredAt: $entry->getOccurredAt(),
                action: $entry->getAction(),
                actorLabel: null !== $actor ? (string) $actor : null,
                summary: $this->summarize($entry->getChanges()),
            );
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    private function summarize(array $changeSet): string
    {
        $fields = [];
        foreach (array_keys($changeSet) as $field) {
            $fields[] = match ($field) {
                'name' => $this->translator->trans('label.name', [], 'messages'),
                'note' => $this->translator->trans('catalog.field.note', [], 'allocation'),
                'code' => $this->translator->trans('catalog.field.code', [], 'allocation'),
                default => $field,
            };
        }

        if ([] === $fields) {
            return $this->translator->trans('catalog.definition_change.generic', [], 'allocation');
        }

        return $this->translator->trans('catalog.definition_change.fields', [
            'fields' => implode(', ', $fields),
        ], 'allocation');
    }
}
