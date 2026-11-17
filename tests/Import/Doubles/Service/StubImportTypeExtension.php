<?php

declare(strict_types=1);

namespace App\Tests\Import\Doubles\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Application\DTO\ImportTypeDetailSection;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Encounter-typed extension with fixed availability and upload hospitals; only the access methods matter.
 */
final readonly class StubImportTypeExtension implements ImportTypeExtensionInterface
{
    /**
     * @param list<int> $uploadHospitalIds
     */
    public function __construct(
        private bool $available,
        private array $uploadHospitalIds,
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
        return $this->available;
    }

    #[\Override]
    public function uploadHospitalIds(User $user): array
    {
        return $this->uploadHospitalIds;
    }

    #[\Override]
    public function visibleHospitalIds(User $user): array
    {
        return $this->uploadHospitalIds;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder): void
    {
    }

    #[\Override]
    public function formFields(): array
    {
        return [];
    }

    #[\Override]
    public function validateUpload(FormInterface $form, Hospital $hospital, User $user): array
    {
        return [];
    }

    #[\Override]
    public function onImportCreated(Import $import, FormInterface $form, User $user): void
    {
    }

    #[\Override]
    public function vote(string $attribute, Import $import, TokenInterface $token): bool
    {
        return false;
    }

    #[\Override]
    public function detailSection(Import $import): ?ImportTypeDetailSection
    {
        return null;
    }
}
