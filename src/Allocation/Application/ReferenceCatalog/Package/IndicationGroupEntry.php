<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class IndicationGroupEntry implements CatalogEntry
{
    /**
     * @param list<string> $members public ids of normalized indications
     */
    public function __construct(
        #[Assert\Uuid]
        public string $id,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Unique]
        #[Assert\All([new Assert\Uuid()])]
        public array $members = [],
        #[Assert\Length(max: 120)]
        public ?string $category = null,
        public ?string $description = null,
        #[SerializedName('sort_order')]
        public ?int $sortOrder = null,
    ) {
    }
}
