<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Curation\Import\CurationConflict;
use App\Encounter\Application\Curation\Import\CurationImportApplier;
use App\Encounter\Application\Curation\Import\CurationImportPlan;
use App\Encounter\Application\Curation\Import\CurationImportPlanner;
use App\Encounter\Application\Curation\Package\AreaRuleData;
use App\Encounter\Application\Curation\Package\CurationPackageReader;
use App\Encounter\UI\Console\CurationViolationTable;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Imports a curation package (#1198): adds missing entries, aliases, assignments, problem areas, PZC codes and rules,
 * skips identical rows, and reports every difference as a conflict. Any conflict stops the import before anything is
 * written; nothing on this instance is changed, moved or deleted. When areas were added to, the seed draft is rebuilt;
 * a person activates it on the plausibility page.
 */
#[AsCommand(
    name: 'app:encounter:curation:import',
    description: 'Add the curated MTS catalog and plausibility problem areas of a package to this instance; conflicts stop the import (#1198).',
)]
final readonly class ImportCurationPackageCommand
{
    public function __construct(
        private CurationPackageReader $reader,
        private CurationImportPlanner $planner,
        private CurationImportApplier $applier,
        private AuditContext $auditContext,
        private EntityManagerInterface $entityManager,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Path to the curation package (JSON)')]
        string $source = '',
        #[Option(description: 'Show what the import would add and the conflicts without writing', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Username or email recorded as actor in the audit log')]
        string $user = 'admin',
    ): int {
        if ('' === trim($source)) {
            $io->error('Pass the package file with --source=.');

            return Command::INVALID;
        }

        $actor = $this->resolveUser($user);
        if (!$actor instanceof User) {
            $io->error(\sprintf('No user found for "%s". Pass an existing username or email with --user=.', $user));

            return Command::FAILURE;
        }

        $path = Path::isAbsolute(trim($source)) ? trim($source) : Path::join($this->projectDir, trim($source));
        $io->title('Import encounter curation package');
        $io->text(\sprintf('Source: %s%s', $path, $dryRun ? ' (dry run)' : ''));

        $result = $this->reader->read($path);
        if (!$result->isValid()) {
            $io->error(\sprintf('%s is not a valid curation package (%d problem(s)). Nothing was imported.', $path, \count($result->violations)));
            CurationViolationTable::render($io, $result->violations);

            return Command::FAILURE;
        }

        $package = $result->package;
        $io->text(\sprintf('Exported %s from %s, checksum %s', $package->meta->exportedAt, $package->meta->sourceEnvironment ?? 'unknown environment', $package->meta->checksum->value));

        $plan = $this->planner->plan($package);
        $this->renderPlan($io, $plan);

        if ($plan->hasConflicts()) {
            $io->section('Conflicts');
            $io->table(['Path', 'Conflict', 'Package', 'This instance', 'Resolution'], array_map(
                static fn (CurationConflict $conflict): array => [$conflict->path, $conflict->message, $conflict->package, $conflict->target, $conflict->resolution ?? ''],
                $plan->conflicts,
            ));
            $io->error(\sprintf(
                '%d conflict(s). %s Resolve them through the curation, then run the import again.',
                \count($plan->conflicts),
                $dryRun ? 'The import would stop before writing.' : 'Nothing was imported.',
            ));

            return Command::FAILURE;
        }

        if (0 === $plan->additionCount()) {
            $io->success('Nothing to add: this instance already has every row of the package.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->success(\sprintf('Dry run: %d row(s) would be added. Run again without --dry-run to import.', $plan->additionCount()));

            return Command::SUCCESS;
        }

        $this->auditContext->setActorUserId($actor->getId());
        try {
            $this->applier->apply($plan, $package);
        } finally {
            $this->auditContext->setActorUserId(null);
        }

        $io->success(\sprintf('Added %d row(s).%s', $plan->additionCount(), $plan->changesAreas() ? ' Review and activate the seed draft under /tools/encounter-plausibility.' : ''));

        return Command::SUCCESS;
    }

    private function renderPlan(SymfonyStyle $io, CurationImportPlan $plan): void
    {
        $io->table(['Section', 'New', 'Unchanged', 'Conflict'], array_map(
            static fn (string $section, array $count): array => [$section, (string) $count['new'], (string) $count['unchanged'], (string) $count['conflict']],
            array_keys($plan->counts),
            array_values($plan->counts),
        ));

        $additions = [];
        foreach ($plan->newDiagrams as $entry) {
            $additions[] = \sprintf('diagram %s "%s"', $entry->code, $entry->germanName);
        }

        foreach ($plan->newIndicators as $entry) {
            $additions[] = \sprintf('indicator %s "%s"', $entry->code, $entry->germanName);
        }

        foreach ($plan->newAliases as $alias) {
            $additions[] = \sprintf('%s alias %s → %s', $alias->kind, $alias->code, $alias->targetCode);
        }

        foreach ($plan->newAssignments as $assignment) {
            $additions[] = \sprintf('assignment %s / %s', $assignment->diagram, $assignment->indicator);
        }

        foreach ($plan->newAreas as $area) {
            $additions[] = \sprintf('problem area %s "%s": PZC %s; rules %s', $area->code, $area->germanName, $this->pzcList($area->pzc), $this->ruleList($area->diagrams));
        }

        foreach ($plan->extensions as $extension) {
            $additions[] = \sprintf('problem area %s: add PZC %s; add rules %s', $extension->code, $this->pzcList($extension->pzc), $this->ruleList($extension->rules));
        }

        if ([] !== $additions) {
            $io->section('Additions');
            $io->listing($additions);
        }

        if ($plan->changesAreas()) {
            $io->note(\sprintf(
                '%s Active, retired and learned snapshots stay as they are; a person activates the draft under /tools/encounter-plausibility.',
                null === $plan->seedDraftId ? 'A new seed draft will be created from the problem areas.' : \sprintf('The seed draft %d will be rebuilt from the problem areas.', $plan->seedDraftId),
            ));
        }
    }

    /**
     * @param list<int> $pzc
     */
    private function pzcList(array $pzc): string
    {
        return [] === $pzc ? '–' : implode(', ', array_map(static fn (int $code): string => \sprintf('%03d', $code), $pzc));
    }

    /**
     * @param list<AreaRuleData> $rules
     */
    private function ruleList(array $rules): string
    {
        return [] === $rules ? '–' : implode(', ', array_map(static fn (AreaRuleData $rule): string => \sprintf('%s +%d', $rule->code, $rule->points), $rules));
    }

    private function resolveUser(string $identifier): ?User
    {
        $identifier = '' === trim($identifier) ? 'admin' : trim($identifier);
        $repository = $this->entityManager->getRepository(User::class);

        return $repository->findOneBy(['username' => $identifier]) ?? $repository->findOneBy(['email' => mb_strtolower($identifier)]);
    }
}
