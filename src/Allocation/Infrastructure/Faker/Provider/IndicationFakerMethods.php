<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

/**
 * Added this interface to fix an issue with PHPStan that could not recognize
 * the indication() methods from the Indication faker provider. To make things
 * work we assign self::faker() just for this one case to a $faker variable and
 * manually assign this interface alongside the usual generator.
 */
interface IndicationFakerMethods
{
    /**
     * An official PZC indication.
     *
     * @return array{code: string, name: string}
     */
    public function indication(): array;

    /**
     * @return non-empty-string
     */
    public function indicationCode(): string;

    /**
     * @return non-empty-string
     */
    public function indicationName(): string;

    public function indicationCategory(string $code): string;

    /**
     * A generated raw label for the code; never its official wording.
     */
    public function rawIndicationLabel(string $code): string;

    /**
     * A raw label the catalog cannot map: only a code, or a code outside the PZC list.
     *
     * @return array{code: string, name: string}
     */
    public function unmappedRawIndication(): array;
}
