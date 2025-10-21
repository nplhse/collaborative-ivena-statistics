<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** @psalm-suppress MissingConstructor */
#[ORM\Embeddable]
class Address
{
    #[ORM\Column(type: Types::STRING)]
    private string $street;

    #[ORM\Column(type: Types::STRING)]
    private string $postalCode;

    #[ORM\Column(type: Types::STRING)]
    private string $city;

    #[ORM\Column(type: Types::STRING)]
    private string $state;

    #[ORM\Column(type: Types::STRING)]
    private string $country;

    public function getStreet(): string
    {
        return $this->street;
    }

    public function setStreet(string $street): self
    {
        $this->street = $street;

        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): self
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): self
    {
        $this->country = $country;

        return $this;
    }
}
