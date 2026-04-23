# Pragmatisches Page-CMS

## Überblick

Das Page-CMS besteht bewusst nur aus einer Entity `Page` plus wenigen Services:

- Hierarchie über `parent`/`children`
- URL-Auflösung über persistierten Vollpfad `path`
- Status (`draft`, `published`) und Sichtbarkeit (`public`, `authenticated`)
- JSON-basierter linearer Block-Content
- einfache EasyAdmin-Bearbeitung über FormTypes

## Architektur

- `App\Content\Domain\Entity\Page`
  - zentrale Datenstruktur inklusive Validierung für Status, Visibility und Hierarchie
- `App\Content\Application\Page\PagePathResolver`
  - normalisiert Slugs und berechnet den vollständigen Pfad
- `App\Content\Infrastructure\Doctrine\PagePathSubscriber`
  - pflegt `path` automatisch bei Persist/Update und propagiert Änderungen auf Kinder
- `App\Content\Application\Page\PageContentValidator`
  - validiert Blockschema und Pflichtfelder je Typ
- `App\Content\Application\Page\PageContentSanitizer`
  - sanitizt `richtext`-HTML mit Symfony HtmlSanitizer
- `App\Content\Application\Page\PageAccessChecker`
  - kleine Zugriffskontrolle für Frontend-Auslieferung

## Frontend

- Catch-all-Route in `PageController` löst Seiten über den vollen `path` auf.
- Nur `published` wird ausgeliefert.
- `authenticated` setzt eingeloggte Benutzer voraus.
- Block-Rendering erfolgt linear in:
  - `@Content/page/show.html.twig`
  - `@Content/page/blocks/*.html.twig`

## EasyAdmin

- `PageCrudController` ist in das Dashboard-Menü integriert.
- Das Feld `content` nutzt `PageContentType` + `PageContentBlockType`:
  - Typ-Auswahl (`richtext`, `image`, `cta`, `quote`)
  - `enabled`
  - typabhängige Datenfelder

## Erweiterungspunkte

- Neuer Blocktyp:
  1. Typ in `PageContentBlockType` ergänzen.
  2. Pflichtfelder in `PageContentValidator` ergänzen.
  3. Twig-Partial unter `templates/page/blocks/` ergänzen.
- Erweiterte Zugriffsregeln:
  - `PageAccessChecker` erweitern oder bei Bedarf auf Voter umstellen.
