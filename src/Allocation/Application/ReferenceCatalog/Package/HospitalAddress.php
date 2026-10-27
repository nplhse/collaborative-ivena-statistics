<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class HospitalAddress
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $street,
        #[SerializedName('postal_code')]
        #[Assert\NotBlank]
        #[Assert\Length(max: 16)]
        public string $postalCode,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $city,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $state,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $country,
    ) {
    }
}
