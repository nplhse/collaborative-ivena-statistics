# Translations (i18n)

Symfony native translation with XLIFF files under `translations/`. No custom wrappers or registries.

## Domains

| Domain | Purpose | File pattern |
|---|---|---|
| `messages` | Generic UI (Save, Cancel, Yes, No) and cross-cutting entity labels (`label.hospital`, `field.gender`, …) | `messages+intl-icu.{en,de}.xlf` |
| `statistics` | Statistics UI, reports, explorer, benchmarking, case flow | `statistics+intl-icu.{en,de}.xlf` |
| `allocation` | Allocations, indications, hospitals, MCI, export | `allocation+intl-icu.{en,de}.xlf` |
| `import` | CSV import workflow | `import+intl-icu.{en,de}.xlf` |
| `user` | Auth, registration, settings, transactional emails | `user+intl-icu.{en,de}.xlf` |
| `content` | Public pages, blog, dashboard CMS content | `content+intl-icu.{en,de}.xlf` |
| `onboarding` | Participant onboarding checklist | `onboarding+intl-icu.{en,de}.xlf` |
| `feedback` | Feedback widget and notifications | `feedback+intl-icu.{en,de}.xlf` |
| `engagement` | Monthly submission reminders | `engagement+intl-icu.{en,de}.xlf` |
| `admin` | Custom admin dashboard (not EasyAdmin bundle defaults) | `admin+intl-icu.{en,de}.xlf` |
| `shared` | Cookies, locale switcher, global navigation links | `shared+intl-icu.{en,de}.xlf` |
| `validators` | Validation messages | `validators.{en,de}.xlf` |
| `errors` | Error pages | `errors+intl-icu.{en,de}.xlf` |
| `security` | Login/security errors | `security.{en,de}.xlf` |

Bundle overrides (`EasyAdminBundle`, `ResetPasswordBundle`, `VerifyEmailBundle`) stay as-is.

## Recommended conventions

These rules reflect how the codebase is structured after the domain split. They prevent the most common profiler noise (wrong domain, entity names treated as keys, auto-generated English form labels).

### Where keys belong

1. Add keys to the **bounded-context domain** that owns the feature — not reflexively to `messages`.
2. Use `messages` only for **generic actions** (`action.save`, `action.cancel`) and **cross-cutting entity labels** reused in allocation lists, export, explore, and admin (`label.department`, `field.urgency`, `label.all_indications`).
3. Use **feature-specific prefixes** inside a domain (`stats.*` in `statistics`, `flash.indication.*` in `allocation`, `onboarding.steps.*` in `onboarding`).
4. Add **EN and DE in the same change**; run `make lint-trans` before pushing.

### Always pass the domain explicitly

| Layer | Pattern |
|---|---|
| PHP | `$this->translator->trans('key', [], 'statistics', $locale)` |
| Twig | `{{ 'key'|trans({}, 'statistics') }}` |
| Forms | `'translation_domain' => 'allocation'` on the form type or field |
| Flash | `new TranslatableMessage('flash…', domain: 'allocation')` |
| DTOs (deferred) | `public TranslatableMessage $label` → `{{ dto.label|trans }}` in Twig |

Implicit domains (Symfony default `messages`) are only acceptable for truly generic keys. After the split, most missing-translation reports come from **forgotten domain arguments**.

### Forms

1. Set `translation_domain` to the domain where the **field labels and placeholders** live.
2. Set **`choice_translation_domain => false`** on every `ChoiceType` whose labels are **entity names**, **reference-data terms**, or **already translated in PHP** (e.g. indication names, department names, hospital names, SK urgency labels). This option is **not inherited** from the parent form — set it per choice field or use `PreTranslatedChoiceType`.
3. Provide **explicit `label` keys** for all fields; Symfony otherwise humanises property names (`dateFrom` → “Date from”, `isCPR` → “Is c p r”).
4. When label and help text live in **different domains**, use `TranslatableMessage` per option:

```php
use Symfony\Component\Translation\TranslatableMessage;

->add('includeIndicationRaw', CheckboxType::class, [
    'label' => new TranslatableMessage('field.includeIndicationRaw', domain: 'messages'),
    'help' => new TranslatableMessage('help.export.include_indication_raw', domain: 'allocation'),
])
```

5. For enum choices with translatable labels (e.g. `hospital.tier.*`), set `choice_translation_domain` to the domain that **owns the enum keys** (`allocation`), not `false`.

### Twig components and breadcrumbs

- Pass translation **keys** as `label` and set `label_domain` when not `messages`.
- Pass **plain text** (hospital names, pre-resolved scope labels) with `translatable: false`, or rely on `Breadcrumbs` auto-detection (non-key strings without `.` prefix).
- Accordion section titles in templates: translate in Twig with an explicit domain rather than relying on the form’s `translation_domain`.

### Do not translate (domain terms)

These are **displayed as-is** — never run them through the translator:

| Kind | Examples | How |
|---|---|---|
| Entity / reference names from DB | Indication names, departments, occasions, infections | `choice_translation_domain => false` |
| Hospital names | `Kiel Klinikum` | `choice_translation_domain => false` |
| SK urgency labels | `SK1`, `SK2`, `SK3` | `choice_translation_domain => false` |
| Product / format names | IVENA, CSV, PNG | Keys or plain text; see [glossary-i18n-de.md](../06-reference/glossary-i18n-de.md) |

UI **labels for** these concepts (`label.indication`, `field.urgency`) are translated; the **values** in dropdowns are not.

### Testing

For critical pages, add a functional test that enables the profiler and asserts no missing translations:

```php
use App\Tests\Support\Translation\AssertsNoMissingTranslations;

final class MyControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;

    public function testPageHasNoMissingTranslationsInGerman(): void
    {
        $client = self::createClient();
        $client->enableProfiler();
        $client->request('GET', '/my-route', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        $this->assertNoMissingTranslations($client->getProfile());
    }
}
```

## Domain decision matrix

Use this table when adding a new key. When in doubt, prefer the **feature domain** over `messages`.

| If the key relates to… | Domain | Typical prefixes / examples |
|---|---|---|
| Statistics, explorer, benchmarking, case flow, KPIs | `statistics` | `stats.*`, `statistics.*` |
| Allocations, hospitals (participant UI), indications, MCI, export | `allocation` | `flash.indication.*`, `help.export.*`, `allocations.field.*`, `hospital.tier.*`, `hospital.location.*`, `title.allocation.*`, `title.hospital*` |
| CSV import, import status, import admin | `import` | `flash.import.*`, `title.import.*`, `label.import.*` |
| Login, registration, password, settings, user emails | `user` | `flash.user.*`, `title.settings*`, `email.*` (user context) |
| Blog, public pages, dashboard CMS snippets | `content` | `blog.*`, `dashboard.*`, `public.*`, `title.blog` |
| Onboarding checklist | `onboarding` | `onboarding.steps.*` |
| Feedback widget | `feedback` | `feedback.*` |
| Monthly submission reminders | `engagement` | `monthly_reminder.*`, `engagement.*` |
| Custom admin dashboard (non–EasyAdmin) | `admin` | `kpi.*`, `admin.*` |
| Cookies, locale switcher, global nav links | `shared` | `cookie.*`, `locale.*`, `link.*`, `menu.*` |
| Validation messages (Symfony Validator) | `validators` | constraint messages |
| HTTP error pages | `errors` | `error.*` |
| Authentication / security errors | `security` | Symfony Security catalogue |
| Generic actions, shared entity labels used in many modules | `messages` | `action.*`, `label.hospital`, `label.department`, `field.gender`, `label.all_*` |

### Mixed-domain screens (common cases)

| Screen / element | Label domain | Value / help domain | Notes |
|---|---|---|---|
| Export form | `messages` (field labels) | `allocation` (help texts) | Use `TranslatableMessage` for mixed fields |
| Export clinical checkboxes | `allocation` | — | `allocations.field.isVentilated`, etc. |
| Export period accordion title | `import` | — | `label.export.period` |
| Export clinical accordion title | `statistics` | — | `stats.analysis_explorer.dimension_group.clinical_care` |
| Hospital edit enums (tier, location, size) | `allocation` | — | `choice_translation_domain => 'allocation'` |
| Hospital edit relations (state, dispatch area) | — | — | Entity names: `choice_translation_domain => false` |
| Allocation list filter drawer | `messages` / `statistics` | — | Entity option **values** rendered as `{{ entity.name }}` in Twig, not via form translator |
| Breadcrumbs | auto or explicit `label_domain` | — | Keys like `title.blog` → `content`; plain hospital names → `translatable: false` |

### Prefix → domain quick reference

| Key starts with… | Domain |
|---|---|
| `stats.` / `statistics.` | `statistics` |
| `flash.indication.` / `help.export.` / `allocations.field.` / `hospital.tier.` / `hospital.location.` / `hospital.size.` | `allocation` |
| `flash.import.` / `title.import.` | `import` |
| `onboarding.` | `onboarding` |
| `feedback.` | `feedback` |
| `monthly_reminder.` / `engagement.` | `engagement` |
| `kpi.` / `admin.` (custom dashboard) | `admin` |
| `cookie.` / `locale.` / `link.` / `menu.` | `shared` |
| `blog.` / `dashboard.` / `public.` / `title.blog` | `content` |
| `action.` / `label.` / `field.` / `help.` (cross-cutting) | `messages` (unless feature-specific help, e.g. `help.export.*` → `allocation`) |
| `error.` (pages) | `errors` |

When a prefix could belong to two domains, choose the domain of the **controller / template / form type** that renders it.

## Rules for new translations

1. Add keys to the **bounded-context domain** that owns the feature (see matrix above).
2. Use `messages` only for generic actions and labels shared across multiple contexts.
3. Always pass the domain explicitly in PHP, Twig, and forms.
4. Use `TranslatableMessage` when a DTO or flash carries a key for deferred rendering in Twig.
5. Add EN and DE in the same change; run `make lint-trans` before pushing.

## Usage examples

### PHP

```php
$this->translator->trans('stats.filter.period.all', [], 'statistics', $locale);
```

### Twig

```twig
{{ 'stats.data_quality.title'|trans({}, 'statistics') }}
```

### Forms

```php
'translation_domain' => 'allocation',
'choice_translation_domain' => false, // per ChoiceType when labels are entity names
```

### Flash messages

```php
use Symfony\Component\Translation\TranslatableMessage;

$this->addFlash('success', new TranslatableMessage('flash.indication.review.approved', domain: 'allocation'));
```

### DTOs (deferred rendering)

```php
public TranslatableMessage $label,
```

```twig
{{ card.label|trans }}
```

## Workflow

```bash
make trans-all    # extract all domains (EN)
make trans-de-all # scaffold missing DE units
make lint-trans   # lint EN + DE catalogues
```

See also [glossary-i18n-de.md](glossary-i18n-de.md) for EN↔DE terminology and [../03-development/testing.md](../03-development/testing.md) for CI checks.
