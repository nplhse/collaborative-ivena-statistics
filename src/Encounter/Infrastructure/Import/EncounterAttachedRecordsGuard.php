<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Import;

use App\Encounter\Infrastructure\Persistence\EncounterReferenceCatalog;
use App\Import\Application\Contracts\ImportDataRemoverInterface;
use App\Import\Application\Contracts\ImportDeletionRefusedException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Refuses to delete or clean up an import while records of other imports are attached to the encounters
 * it created (ADR 037): deleting an import removes only its own records. Runs as the first remover, before
 * anything is removed and before EncounterMatchDataRemover (priority 10) queues a matcher rerun.
 *
 * Until the encounter hub is split out of `encounter` (#1300), this concerns `ed_visit` imports whose
 * visits carry records of attaching schemas; the records' imports must be deleted first.
 *
 * @psalm-suppress UnusedClass Tagged via ImportDataRemoverInterface.
 */
#[AsTaggedItem(priority: 20)]
final readonly class EncounterAttachedRecordsGuard implements ImportDataRemoverInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EncounterReferenceCatalog $references,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'encounter_attached_records';
    }

    #[\Override]
    public function removeForImport(int $importId): int
    {
        $attached = $this->references->attachedToImport($importId);
        if ($attached > 0) {
            throw self::refusal($importId, $attached);
        }

        return 0;
    }

    /**
     * The refusal shown to the user; the same whether this guard or the database blocked the removal.
     */
    public static function refusal(int $importId, ?int $attached, ?\Throwable $previous = null): ImportDeletionRefusedException
    {
        return new ImportDeletionRefusedException(
            new TranslatableMessage('encounter.import.delete_refused.attached_records', domain: 'encounter'),
            sprintf('Import #%d has encounters with %s attached records of other imports.', $importId, null === $attached ? 'some' : (string) $attached),
            $previous,
        );
    }
}
