<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

/**
 * Catalog-independent keys for the departments and specialities the generator writes (ADR 022).
 *
 * Patterns, profiles and the mapping file address a department by its key ("stroke-unit"), never by
 * a catalog name. `GeneratorCatalog` resolves a key against the installed catalog: through the
 * mapping file if it binds the key, else by the default label, which is the spelling of the
 * synthetic catalog. Keys are stable: rename a label, never a key; new departments get a new key.
 *
 * Lookups (assignment, occasion, infection, secondary transport) need no keys: the generator writes
 * the IVENA values themselves ("RD", "Häuslicher Einsatz"). Indications are keyed by PZC code.
 */
final class GeneratorKeys
{
    /**
     * Department key → default label (synthetic catalog) and the speciality key it is written with.
     *
     * @var array<string, array{label: string, speciality: string}>
     */
    public const array DEPARTMENTS = [
        'addiction-medicine' => ['label' => 'Suchtmedizin', 'speciality' => 'psychiatry'],
        'cardiology' => ['label' => 'Kardiologie', 'speciality' => 'internal-medicine'],
        // The general ENT department. The synthetic catalog has no unit of that name; its default
        // label is the existing unit "Rhinologie", so the synthetic catalog keeps its entries (#910).
        // A real catalog binds `ent` to its general ENT department in the mapping file.
        'ent' => ['label' => 'Rhinologie', 'speciality' => 'ent'],
        'epileptology' => ['label' => 'Epileptologie', 'speciality' => 'neurology'],
        'gastroenterology' => ['label' => 'Gastroenterologie', 'speciality' => 'internal-medicine'],
        'general-neurology' => ['label' => 'Allgemeine Neurologie', 'speciality' => 'neurology'],
        'general-paediatrics' => ['label' => 'Allgemeine Pädiatrie', 'speciality' => 'paediatrics'],
        'general-psychiatry' => ['label' => 'Allgemeine Psychiatrie', 'speciality' => 'psychiatry'],
        'general-surgery' => ['label' => 'Allgemeinchirurgie', 'speciality' => 'surgery'],
        'geriatric-psychiatry' => ['label' => 'Gerontopsychiatrie', 'speciality' => 'psychiatry'],
        'gynaecology' => ['label' => 'Gynäkologie', 'speciality' => 'gynaecology-obstetrics'],
        'infectious-diseases' => ['label' => 'Infektiologie', 'speciality' => 'internal-medicine'],
        'joint-replacement' => ['label' => 'Endoprothetik', 'speciality' => 'orthopaedics'],
        'neonatology' => ['label' => 'Neonatologie', 'speciality' => 'paediatrics'],
        'nephrology' => ['label' => 'Nephrologie', 'speciality' => 'internal-medicine'],
        'neurological-intensive-care' => ['label' => 'Neurologische Intensiv', 'speciality' => 'neurology'],
        'neurosurgery' => ['label' => 'Neurochirurgie', 'speciality' => 'surgery'],
        'obstetrics' => ['label' => 'Geburtshilfe', 'speciality' => 'gynaecology-obstetrics'],
        'ophthalmology' => ['label' => 'Allgemeine Augenheilkunde', 'speciality' => 'ophthalmology'],
        'paediatric-cardiology' => ['label' => 'Kinderkardiologie', 'speciality' => 'paediatrics'],
        'paediatric-gastroenterology' => ['label' => 'Kindergastroenterologie', 'speciality' => 'paediatrics'],
        'prenatal-medicine' => ['label' => 'Pränatalmedizin', 'speciality' => 'gynaecology-obstetrics'],
        'pulmonology' => ['label' => 'Pulmologie', 'speciality' => 'internal-medicine'],
        'spine-surgery' => ['label' => 'Wirbelsäulenchirurgie', 'speciality' => 'orthopaedics'],
        'stroke-unit' => ['label' => 'Schlaganfallmedizin', 'speciality' => 'neurology'],
        'trauma-surgery' => ['label' => 'Unfallchirurgie', 'speciality' => 'surgery'],
        'urology' => ['label' => 'Allgemeine Urologie', 'speciality' => 'urology'],
        'visceral-surgery' => ['label' => 'Viszeralchirurgie', 'speciality' => 'surgery'],
    ];

    /**
     * Speciality key (IVENA "Fachgebiet") → default label (synthetic catalog).
     *
     * @var array<string, string>
     */
    public const array SPECIALITIES = [
        'ent' => 'HNO (Hals-Nasen-Ohren)',
        'gynaecology-obstetrics' => 'Gynäkologie und Geburtshilfe',
        'internal-medicine' => 'Innere Medizin',
        'neurology' => 'Neurologie',
        'ophthalmology' => 'Augenheilkunde',
        'orthopaedics' => 'Orthopädie',
        'paediatrics' => 'Pädiatrie',
        'psychiatry' => 'Psychiatrie',
        'surgery' => 'Chirurgie',
        'urology' => 'Urologie',
    ];

    /**
     * The key of a department, given as key or as default label.
     *
     * @throws \InvalidArgumentException for a department without a key
     */
    public static function department(string $keyOrLabel): string
    {
        if (isset(self::DEPARTMENTS[$keyOrLabel])) {
            return $keyOrLabel;
        }
        foreach (self::DEPARTMENTS as $key => $department) {
            if ($department['label'] === $keyOrLabel) {
                return $key;
            }
        }

        throw new \InvalidArgumentException(sprintf('The generator has no department key for "%s"; add it to %s::DEPARTMENTS.', $keyOrLabel, self::class));
    }

    /**
     * The key of a speciality, given as key or as default label.
     *
     * @throws \InvalidArgumentException for a speciality without a key
     */
    public static function speciality(string $keyOrLabel): string
    {
        if (isset(self::SPECIALITIES[$keyOrLabel])) {
            return $keyOrLabel;
        }
        $key = array_search($keyOrLabel, self::SPECIALITIES, true);
        if (\is_string($key)) {
            return $key;
        }

        throw new \InvalidArgumentException(sprintf('The generator has no speciality key for "%s"; add it to %s::SPECIALITIES.', $keyOrLabel, self::class));
    }

    /**
     * The default label of a key, the name it has in the synthetic catalog.
     */
    public static function label(CatalogLookupType $type, string $key): string
    {
        return match ($type) {
            CatalogLookupType::Department => self::DEPARTMENTS[self::department($key)]['label'],
            CatalogLookupType::Speciality => self::SPECIALITIES[self::speciality($key)],
            default => $key,
        };
    }

    /**
     * The key in the generator's vocabulary: department and speciality keys, lookup values as they are.
     */
    public static function key(CatalogLookupType $type, string $keyOrLabel): string
    {
        return match ($type) {
            CatalogLookupType::Department => self::department($keyOrLabel),
            CatalogLookupType::Speciality => self::speciality($keyOrLabel),
            default => $keyOrLabel,
        };
    }
}
