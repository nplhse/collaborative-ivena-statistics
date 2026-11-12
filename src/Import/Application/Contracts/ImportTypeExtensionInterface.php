<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\DTO\ImportTypeDetailSection;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * An import type that another context owns but that uses the generic import pages (extension point
 * `import.type_extension`): upload form, list, detail page, reprocess and delete. The extension decides
 * access, adds its form fields and its own state, and contributes a section to the detail page, so
 * `Import` does not depend on that context.
 *
 * Processing goes through the workflow dispatcher of the same type (`import.workflow_dispatcher`).
 */
#[AutoconfigureTag(self::TAG)]
interface ImportTypeExtensionInterface
{
    public const string TAG = 'import.type_extension';

    public static function importType(): ImportType;

    /**
     * Translation key (domain `import`) of the type in the upload form and on the detail page.
     */
    public function label(): string;

    /**
     * Whether the user may choose the type in the upload form at all.
     */
    public function isAvailableTo(User $user): bool;

    /**
     * Hospitals the user may upload this type for; offered in the upload form next to the hospitals
     * with the import permission.
     *
     * @return list<int>
     */
    public function uploadHospitalIds(User $user): array;

    /**
     * Hospitals whose imports of this type the user sees in the import list.
     *
     * @return list<int>
     */
    public function visibleHospitalIds(User $user): array;

    /**
     * Adds the type's own unmapped fields to the upload form.
     *
     * @param FormBuilderInterface<Import> $builder
     */
    public function buildForm(FormBuilderInterface $builder): void;

    /**
     * Names of the fields added by buildForm(), in display order.
     *
     * @return list<string>
     */
    public function formFields(): array;

    /**
     * Checks a submitted upload for this type before anything is stored.
     *
     * @param FormInterface<mixed> $form
     *
     * @return array<string, string> translated error message by form field name; empty when the upload may proceed
     */
    public function validateUpload(FormInterface $form, Hospital $hospital, User $user): array;

    /**
     * Persists the type's own state for a new import; the caller flushes it together with the import.
     *
     * @param FormInterface<mixed> $form
     */
    public function onImportCreated(Import $import, FormInterface $form, User $user): void;

    /**
     * Decides an ImportVoter attribute (VIEW, DELETE, DOWNLOAD_SOURCE, REPROCESS) for an import of this type.
     */
    public function vote(string $attribute, Import $import, TokenInterface $token): bool;

    /**
     * Section of the type on the import detail page, or null.
     */
    public function detailSection(Import $import): ?ImportTypeDetailSection;
}
