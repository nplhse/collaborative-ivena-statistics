<?php

declare(strict_types=1);

/**
 * One-off helper: build translations/<domain>.de.xlf from EN file + reference map.
 * Usage: php bin/generate-de-domain-xlf.php EasyAdminBundle
 *        php bin/generate-de-domain-xlf.php validators
 */

$domain = $argv[1] ?? null;
if (!in_array($domain, ['EasyAdminBundle', 'validators'], true)) {
    fwrite(STDERR, "Usage: php bin/generate-de-domain-xlf.php <EasyAdminBundle|validators>\n");
    exit(1);
}

$root = dirname(__DIR__);
$enPath = sprintf('%s/translations/%s.en.xlf', $root, $domain);
$dePath = sprintf('%s/translations/%s.de.xlf', $root, $domain);

/** @var array<string, string> $overrides */
$overrides = match ($domain) {
    'EasyAdminBundle' => buildEasyAdminOverrides($root),
    'validators' => buildValidatorOverrides(),
};

/** @var array<string, string> $reference */
$reference = match ($domain) {
    'EasyAdminBundle' => flattenTranslations(include $root.'/vendor/easycorp/easyadmin-bundle/translations/EasyAdminBundle.de.php'),
    'validators' => loadSymfonyValidatorReference($root),
};

$enDom = new DOMDocument('1.0', 'utf-8');
$enDom->preserveWhiteSpace = false;
$enDom->formatOutput = true;
$enDom->load($enPath);

$ns = 'urn:oasis:names:tc:xliff:document:1.2';
$deDom = new DOMDocument('1.0', 'utf-8');
$deDom->preserveWhiteSpace = false;
$deDom->formatOutput = true;

$xliff = $deDom->createElementNS($ns, 'xliff');
$xliff->setAttribute('version', '1.2');
$deDom->appendChild($xliff);

$file = $deDom->createElementNS($ns, 'file');
$file->setAttribute('source-language', 'en');
$file->setAttribute('target-language', 'de');
$file->setAttribute('datatype', 'plaintext');
$file->setAttribute('original', 'file.ext');
$xliff->appendChild($file);

$header = $deDom->createElementNS($ns, 'header');
$tool = $deDom->createElementNS($ns, 'tool');
$tool->setAttribute('tool-id', 'symfony');
$tool->setAttribute('tool-name', 'Symfony');
$header->appendChild($tool);
$file->appendChild($header);

$body = $deDom->createElementNS($ns, 'body');
$file->appendChild($body);

$missing = [];

foreach ($enDom->getElementsByTagName('trans-unit') as $unit) {
    if (!$unit instanceof DOMElement) {
        continue;
    }

    $resname = $unit->getAttribute('resname');
    $sourceNode = $unit->getElementsByTagName('source')->item(0);
    $source = $sourceNode?->textContent ?? '';
    $enTargetNode = $unit->getElementsByTagName('target')->item(0);

    $translation = $overrides[$resname]
        ?? $reference[$resname]
        ?? $reference[$source]
        ?? null;

    if ($translation === null) {
        $missing[] = $resname;
        $translation = $enTargetNode?->textContent ?? $source;
    }

    $newUnit = $deDom->createElementNS($ns, 'trans-unit');
    $newUnit->setAttribute('id', $unit->getAttribute('id'));
    $newUnit->setAttribute('resname', $resname);

    $newSource = $deDom->createElementNS($ns, 'source');
    if ($sourceNode instanceof DOMElement && $sourceNode->childNodes->length > 0) {
        foreach ($sourceNode->childNodes as $child) {
            $newSource->appendChild($deDom->importNode($child, true));
        }
    } else {
        $newSource->appendChild($deDom->createTextNode($source));
    }
    $newUnit->appendChild($newSource);

    $newTarget = $deDom->createElementNS($ns, 'target');
    $enUsesCdata = $enTargetNode instanceof DOMElement
        && $enTargetNode->childNodes->length === 1
        && $enTargetNode->firstChild instanceof DOMCdataSection;

    if ($enUsesCdata) {
        $newTarget->appendChild($deDom->createCDATASection($translation));
    } elseif (str_contains($translation, '<') && str_contains($translation, '>')) {
        $fragment = $deDom->createDocumentFragment();
        $fragment->appendXML($translation);
        $newTarget->appendChild($fragment);
    } else {
        $newTarget->appendChild($deDom->createTextNode($translation));
    }
    $newUnit->appendChild($newTarget);

    $body->appendChild($newUnit);
}

$deDom->save($dePath);

if ($missing !== []) {
    fwrite(STDERR, sprintf("Warning: %d keys without reference translation in %s:\n", count($missing), $domain));
    foreach ($missing as $key) {
        fwrite(STDERR, "  - {$key}\n");
    }
}

echo sprintf("Wrote %s (%d trans-units)\n", $dePath, $body->getElementsByTagName('trans-unit')->length);

/** @return array<string, string> */
function flattenTranslations(array $nested, string $prefix = ''): array
{
    $flat = [];
    foreach ($nested as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $flat += flattenTranslations($value, $path);
        } else {
            $flat[$path] = (string) $value;
        }
    }

    return $flat;
}

/** @return array<string, string> */
function loadSymfonyValidatorReference(string $root): array
{
    $paths = [
        $root.'/vendor/symfony/validator/Resources/translations/validators.de.xlf',
        $root.'/vendor/symfony/form/Resources/translations/validators.de.xlf',
    ];

    $map = [];
    foreach ($paths as $path) {
        $dom = new DOMDocument();
        $dom->load($path);

        foreach ($dom->getElementsByTagName('trans-unit') as $unit) {
            if (!$unit instanceof DOMElement) {
                continue;
            }
            $source = $unit->getElementsByTagName('source')->item(0)?->textContent ?? '';
            $target = $unit->getElementsByTagName('target')->item(0)?->textContent ?? '';
            if ($source !== '') {
                $map[$source] = $target;
            }
        }
    }

    return $map;
}

/** @return array<string, string> */
function buildEasyAdminOverrides(string $root): array
{
    return [
        'action.new' => '%entity_label_singular% hinzufügen',
        'action.save' => 'Änderungen speichern',
        'action.detail' => 'Anzeigen',
        'action.edit' => 'Bearbeiten',
        'batch_action_modal.title' => 'Sie sind dabei, die Aktion „%action_name%“ auf %num_items% Element(e) anzuwenden.',
        'files' => 'Dateien',
        'filter.title' => 'Filter',
        'login_page.sign_in' => 'Anmelden',
        'login_page.remember_me' => 'Angemeldet bleiben',
        'page_title.exception' => 'Fehler|Fehler',
        'user.exit_impersonation' => 'Identitätswechsel beenden',
    ];
}

/** @return array<string, string> */
function buildValidatorOverrides(): array
{
    return [
        'No temporary folder was configured in php.ini.' => 'In der php.ini ist kein temporärer Ordner konfiguriert oder der konfigurierte Ordner existiert nicht.',
        'This is not a valid Business Identifier Code (BIC).' => 'Dieser Wert ist kein gültiger Business Identifier Code (BIC).',
        'This is not a valid IP address.' => 'Dieser Wert ist keine gültige IP-Adresse.',
        'This is not a valid International Bank Account Number (IBAN).' => 'Dieser Wert ist keine gültige International Bank Account Number (IBAN).',
        'This is not a valid UUID.' => 'Dieser Wert ist keine gültige UUID.',
        'Unknown transport' => 'Dieser Wert ist ein unbekanntes Transportmittel.',
        'arrivalAt cannot be before createdAt' => 'Die Ankunftszeit darf nicht vor der Erstellungszeit liegen.',
        'label.import.mimeTypes' => 'MIME-Typen',
        'page.validation.path_unique' => 'Dieser Pfad existiert bereits.',
        'page.validation.parent_slug_unique' => 'Dieser Slug existiert auf derselben Ebene bereits.',
        'validation.registration.accept_terms_required' => 'Sie müssen die Nutzungsbedingungen akzeptieren, um sich zu registrieren.',
        'page.validation.key_unique' => 'Dieser Seiten-Schlüssel ist bereits einer anderen Seite zugewiesen.',
        'page.validation.slug_format' => 'Der Slug darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.',
        'post.validation.slug_format' => 'Der Slug darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.',
        'post.validation.slug_unique' => 'Dieser Slug wird bereits von einem anderen Beitrag verwendet.',
        'page.validation.parent_not_self' => 'Eine Seite kann nicht ihr eigenes übergeordnetes Element sein.',
        'page.validation.no_cycles' => 'Zyklische Hierarchien sind nicht erlaubt.',
        'page.validation.content_must_be_array' => 'Der Inhalt muss eine Liste von Blöcken sein.',
        'page.validation.block_must_be_object' => 'muss ein Objekt sein.',
        'page.validation.block_type_required' => 'Feld „type“ ist erforderlich.',
        'page.validation.block_unknown_type' => 'unbekannter Blocktyp „{type}“.',
        'page.validation.block_data_must_be_object' => 'Feld „data“ muss ein Objekt sein.',
        'page.validation.block_enabled_must_be_bool' => 'Feld „enabled“ muss true oder false sein.',
        'page.validation.block_required_field' => 'data.{field} ist erforderlich.',
        'page.validation.image_src_or_media_required' => 'Wählen Sie ein Medienbild aus oder geben Sie eine Bild-URL an.',
        'page.validation.cta_media_required' => 'Wählen Sie eine PDF aus der Medienbibliothek für diesen Button aus.',
        'page.validation.headline_invalid_level' => 'Ungültige Überschriftenebene.',
        'page.validation.headline_invalid_align' => 'Ungültige Überschriftenausrichtung.',
        'page.validation.headline_invalid_spacing' => 'Ungültiger Abstandswert für {field}.',
        'page.validation.highlight_invalid_variant' => 'Ungültige Highlight-Variante.',
        'page.validation.highlight_invalid_icon_mode' => 'Ungültiger Highlight-Icon-Modus.',
        'page.validation.highlight_icon_required' => 'Ein benutzerdefiniertes Icon ist erforderlich, wenn der Icon-Modus „custom“ ist.',
        'page.validation.accordion_items_required' => 'Mindestens ein Akkordeon-Element ist erforderlich.',
        'page.validation.accordion_item_must_be_object' => 'Akkordeon-Element muss ein Objekt sein.',
        'page.validation.image_invalid_size' => 'Ungültige Bildgröße.',
        'page.validation.image_invalid_width_preset' => 'Ungültige Bildbreiten-Voreinstellung.',
        'page.validation.image_width_px_required' => 'Breite in Pixeln ist erforderlich, wenn der Breitenmodus „fixed pixels“ ist.',
        'page.validation.image_width_percent_invalid' => 'Breite in Prozent muss zwischen 1 und 100 liegen.',
        'page.validation.image_invalid_alignment' => 'Ungültige Bildausrichtung.',
        'page.validation.image_invalid_float' => 'Ungültige Bild-Umfluss-Option.',
        'page.validation.image_float_requires_non_full_width' => 'Textumfluss erfordert ein Bild ohne volle Breite.',
    ];
}
