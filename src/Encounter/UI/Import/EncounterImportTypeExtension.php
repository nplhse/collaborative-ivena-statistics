<?php

declare(strict_types=1);

namespace App\Encounter\UI\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterImportReaderInterface;
use App\Encounter\Application\Contract\EncounterImportReportInterface;
use App\Encounter\Application\Contract\EncounterKeyStateReaderInterface;
use App\Encounter\Application\Contract\EncounterUploadHospitalsInterface;
use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Application\DTO\ImportTypeDetailSection;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Encounter imports on the generic import pages (#1048, `import.type_extension`).
 *
 * - Upload: type "Encounter data (beta)" for users with the beta role; hospitals with the encounter
 *   grant UPLOAD (or all for ROLE_ADMIN); schema and the required confirmation that the file was
 *   produced with the data preparation tool and holds no direct identifiers, stored with user and
 *   time on `encounter_import` (audited) together with the key generation at upload. Refused while
 *   the hospital's key change is in progress (ADR 027).
 * - List and detail: only for UPLOAD on the hospital (ADR 028); reprocess, download and delete as well.
 *   Reprocess and download only while the upload file is kept (ADR 026, #1049).
 * - Detail page section: counts, file rejection, until when the file is kept or when it was deleted,
 *   row rejects and suspected duplicates, never file content or cell values.
 *
 * @psalm-suppress UnusedClass Tagged via ImportTypeExtensionInterface.
 */
final readonly class EncounterImportTypeExtension implements ImportTypeExtensionInterface
{
    public const string FIELD_SCHEMA = 'encounterSchema';
    public const string FIELD_CONFIRMATION = 'encounterConfirmation';

    private const int LIST_LIMIT = 200;

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private RoleHierarchyInterface $roleHierarchy,
        private AccessDecisionManagerInterface $accessDecisionManager,
        private EncounterUploadHospitalsInterface $uploadHospitals,
        private EncounterDimensionRegistry $dimensions,
        private EncounterKeyStateReaderInterface $keyStates,
        private EncounterImportReaderInterface $encounterImports,
        private EncounterImportReportInterface $report,
        private ImportFileStorage $fileStorage,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        #[Autowire(param: 'app.encounter.source_file_retention_days')]
        private int $sourceFileRetentionDays,
    ) {
    }

    #[\Override]
    public static function importType(): ImportType
    {
        return ImportType::ENCOUNTER;
    }

    #[\Override]
    public function label(): string
    {
        return 'label.import.type.encounter';
    }

    #[\Override]
    public function isAvailableTo(User $user): bool
    {
        return $this->hasRole($user, UserRole::ENCOUNTER_BETA) && [] !== $this->uploadHospitalIds($user);
    }

    #[\Override]
    public function uploadHospitalIds(User $user): array
    {
        if (!$this->hasRole($user, UserRole::ENCOUNTER_BETA)) {
            return [];
        }

        return array_map(
            static fn (Hospital $hospital): int => (int) $hospital->getId(),
            $this->uploadHospitals->forUser($user, $this->hasRole($user, UserRole::ADMIN)),
        );
    }

    #[\Override]
    public function visibleHospitalIds(User $user): array
    {
        return $this->uploadHospitalIds($user);
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder): void
    {
        $schemas = $this->dimensions->schemas();
        $builder
            ->add(self::FIELD_SCHEMA, ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'encounter.import.field.schema',
                'help' => 'encounter.import.field.schema_help',
                'translation_domain' => 'encounter',
                'choices' => array_combine($schemas, $schemas),
                'choice_translation_domain' => false,
                'data' => $schemas[0] ?? null,
                'placeholder' => false,
            ])
            ->add(self::FIELD_CONFIRMATION, CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'encounter.import.field.confirmation',
                'translation_domain' => 'encounter',
                'help' => 'encounter.import.field.confirmation_help',
                'help_html' => true,
                'help_translation_parameters' => ['url' => $this->urlGenerator->generate('app_tools_data_prep')],
            ]);
    }

    #[\Override]
    public function formFields(): array
    {
        return [self::FIELD_SCHEMA, self::FIELD_CONFIRMATION];
    }

    #[\Override]
    public function validateUpload(FormInterface $form, Hospital $hospital, User $user): array
    {
        $errors = [];
        if (true !== $form->get(self::FIELD_CONFIRMATION)->getData()) {
            $errors[self::FIELD_CONFIRMATION] = $this->translator->trans('validation.encounter.import.confirmation_required', [], 'validators');
        }

        $schema = $form->get(self::FIELD_SCHEMA)->getData();
        if (!\is_string($schema) || !$this->dimensions->has($schema)) {
            $errors[self::FIELD_SCHEMA] = $this->translator->trans('validation.encounter.import.schema_required', [], 'validators');
        }

        if ($this->keyStates->currentState((int) $hospital->getId())['key_change_in_progress']) {
            $errors['hospital'] = $this->translator->trans('encounter.import.key_change_in_progress', [], 'encounter');
        }

        return $errors;
    }

    #[\Override]
    public function onImportCreated(Import $import, FormInterface $form, User $user): void
    {
        $hospitalId = (int) $import->getHospital()?->getId();
        /** @var User $confirmedBy */
        $confirmedBy = $this->entityManager->getReference(User::class, (int) $user->getId());

        $this->entityManager->persist(new EncounterImport(
            $import,
            (string) $form->get(self::FIELD_SCHEMA)->getData(),
            $this->keyStates->currentState($hospitalId)['generation'],
            $confirmedBy,
            $this->clock->now(),
        ));
    }

    #[\Override]
    public function vote(string $attribute, Import $import, TokenInterface $token): bool
    {
        $hospital = $import->getHospital();
        if (!$hospital instanceof Hospital || !$this->decide($token, EncounterAttribute::UPLOAD, $hospital)) {
            return false;
        }

        return match ($attribute) {
            'VIEW' => true,
            'DELETE' => $this->decide($token, EncounterAttribute::DELETE_IMPORT, $hospital),
            'REPROCESS' => $this->decide($token, EncounterAttribute::REPROCESS_SOURCE_FILE, $hospital)
                && $this->keepsSourceFile($import),
            'DOWNLOAD_SOURCE' => $this->decide($token, EncounterAttribute::DOWNLOAD_SOURCE_FILE, $hospital)
                && $this->keepsSourceFile($import),
            default => false,
        };
    }

    #[\Override]
    public function detailSection(Import $import): ?ImportTypeDetailSection
    {
        $importId = (int) $import->getId();
        $encounterImport = $this->encounterImports->findForImportId($importId);
        if (!$encounterImport instanceof EncounterImport) {
            return null;
        }

        return new ImportTypeDetailSection('@Encounter/imports/_import_section.html.twig', [
            'encounter_import' => $encounterImport,
            'source_file_retention_days' => $this->sourceFileRetentionDays,
            'source_file_kept_until' => $encounterImport->isSourceFileDeleted()
                ? null
                : $import->getCreatedAt()->modify(\sprintf('+%d days', $this->sourceFileRetentionDays)),
            'rejects' => $this->report->rejects($importId, self::LIST_LIMIT),
            'suspected_duplicates' => $this->report->suspectedDuplicates($importId, self::LIST_LIMIT),
            'list_limit' => self::LIST_LIMIT,
        ]);
    }

    /**
     * The upload file is neither deleted (identifying content, retention) nor missing on disk.
     */
    private function keepsSourceFile(Import $import): bool
    {
        return true !== $this->encounterImports->findForImportId((int) $import->getId())?->isSourceFileDeleted()
            && $this->fileStorage->exists($import->getFilePath());
    }

    private function decide(TokenInterface $token, string $attribute, Hospital $hospital): bool
    {
        return $this->accessDecisionManager->decide($token, [$attribute], $hospital);
    }

    private function hasRole(User $user, string $role): bool
    {
        return \in_array($role, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }
}
