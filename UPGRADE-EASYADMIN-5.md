# EasyAdmin 4 → 5 Upgrade

## Summary

- Bumps `easycorp/easyadmin-bundle` from `4.29.13` to `^5.1.0` (Symfony 8.1, PHP 8.4).
- Pretty URLs were already enabled via `config/routes/easyadmin.yaml`.
- Dashboard already used `#[AdminDashboard]` and `MenuItem::linkTo()`.

## Breaking changes fixed

| Change | Location |
|--------|----------|
| `#[AdminRoute]` on custom CRUD action `sendMonthlyReminder` | `HospitalCrudController` |
| Legacy `crudAction` / `crudControllerFqcn` URL replaced with `MediaLibraryAdminUrlProvider` | `PageContentBlockDataFieldsConfigurator` |
| User detail URLs use pretty route `app_admin_dashboard_user_detail` | `AdminUserUrlGenerator` |
| Grant participant route migrated to `#[AdminRoute]` (`app_admin_dashboard_user_grant_participant`) | `GrantParticipantController`, `GrantParticipantUrlGenerator` |
| Unit test expectations updated for pretty media index URL | `MediaLibraryAdminUrlProviderTest` |

## Features adopted

| Feature | Rationale |
|---------|-----------|
| `Action::askConfirmation()` for hospital monthly reminder | Native EA5 confirm dialog instead of `data-ea-confirm` |
| `ActionGroup` for audit log time filters (24h / 7d / 30d) | Cleaner index toolbar via global action group |
| `#[AdminRoute]` for grant-participant flow | Consistent admin route generation under EasyAdmin 5 |

## Deliberately not adopted

- ImportReject legacy Twig templates and sidebar links (dead code, out of scope)
- Twig Components / admin UI redesign
- Custom icon set
- Additional `AssociationField::autocomplete()` fields
- Action grouping on audit log detail page (only two actions)
- `#[AdminRoute]` layout embedding for grant participant (redirect-only action, no template)

## Manual QA checklist

### Core flows

- [ ] `/admin` dashboard loads (KPI cards, chart, tiles)
- [ ] Sidebar: all CRUD sections reachable
- [ ] Per entity: index, detail, new, edit, delete (where allowed)

### Custom actions

- [ ] Hospital detail → send monthly reminder (confirm dialog + redirect)
- [ ] Grant participant via signed URL (`/admin/users/{id}/grant-participant`)
- [ ] User impersonate action
- [ ] Page/post “view public” action
- [ ] Indication group statistics link
- [ ] Audit log “Time range” group + detail filter actions

### Complex forms

- [ ] Page edit: content block reorder, media library link opens `/admin/media`
- [ ] Post edit: Trix editor + media link
- [ ] Media detail: snippet copy section

### Regression

- [ ] KPI dashboard card links (pretty URLs)
- [ ] Admin email after user registration (user detail absolute URL)

## Verification commands

```bash
composer validate --strict
composer install
bin/console cache:clear --env=dev
bin/console cache:clear --env=prod
bin/console cache:clear --env=test
vendor/bin/phpstan analyze
vendor/bin/rector --dry-run
vendor/bin/paratest --testsuite unit
vendor/bin/paratest --exclude-testsuite unit
```
