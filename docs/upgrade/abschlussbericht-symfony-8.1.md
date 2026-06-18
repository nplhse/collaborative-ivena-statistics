# Abschlussbericht: Upgrade Symfony 7.4 → 8.1

## Durchgeführte Änderungen

### Phase 3 – Doctrine

| Paket | Vorher | Nachher |
|-------|--------|---------|
| doctrine/doctrine-bundle | 2.18.3 | 3.2.4 |
| doctrine/doctrine-migrations-bundle | 3.7.0 | 4.0.0 |
| doctrine/dbal | 4.4.3 | 4.4.3 |
| doctrine/orm | 3.6.7 | 3.6.7 |
| twig/twig | ^2.12\|^3.27 | ^3.27 |

**Konfiguration** ([`config/packages/doctrine.yaml`](config/packages/doctrine.yaml)):
- Native Lazy Objects (Doctrine Bundle 3.x Standard, kein `enable_lazy_ghost_objects` mehr)
- Entfernt: `use_savepoints`, `auto_generate_proxy_classes`, `proxy_dir`, `controller_resolver`, `report_fields_where_declared`

**Behobene Deprecations:**
- `Doctrine\ORM\Proxy\Autoloader` (Messenger/Console) – durch Doctrine Bundle 3.x behoben

### Phase 4 – Symfony 8.1

| Paket | Vorher | Nachher |
|-------|--------|---------|
| symfony/* (Kernkomponenten) | 7.4.* | 8.1.* |
| symfony/monolog-bundle | 3.11.2 | 4.0.2 |
| symfony/ux-* + stimulus-bundle | 2.36 | 2.36 (beibehalten wegen EasyAdmin 4.x) |

**Code-Anpassungen:**
- [`User.php`](src/User/Domain/Entity/User.php): `eraseCredentials()` entfernt (Symfony 8 BC)
- Security Voters: optionaler `?Vote $vote`-Parameter ([`AllocationVoter`](src/Allocation/Infrastructure/Security/Voter/AllocationVoter.php), [`HospitalVoter`](src/Allocation/Infrastructure/Security/Voter/HospitalVoter.php), [`ImportVoter`](src/Import/Infrastructure/Security/Voter/ImportVoter.php))
- [`UserAccountStatusChecker`](src/User/Infrastructure/Security/UserAccountStatusChecker.php): `?TokenInterface $token` in `checkPreAuth`/`checkPostAuth`
- [`ImportCompletedSubscriber`](src/Import/Infrastructure/EventSubscriber/ImportCompletedSubscriber.php): doppelte `EventSubscriberInterface`-Registrierung entfernt
- [`security.yaml`](config/packages/security.yaml): Legacy-`secure_area`-Firewall entfernt
- [`sentry.yaml`](config/packages/sentry.yaml): veraltete `FatalErrorException` entfernt
- [`HospitalAccessGrantType`](src/Allocation/UI/Form/HospitalAccessGrantType.php): `null`-Choices in Symfony-8-Formularverarbeitung abgefangen

**Foundry-Migration (Symfony 8 Pflicht):**
- 23 Factories: `PersistentProxyObjectFactory` → `PersistentObjectFactory`
- Rector `FOUNDRY_2_7` auf 92 Dateien angewendet
- [`zenstruck_foundry.yaml`](config/packages/zenstruck_foundry.yaml): `enable_auto_refresh_with_lazy_objects: true`
- Functional Tests: `refresh()` vor `save()` nach HTTP-Requests

## Verifikation

| Prüfung | Ergebnis |
|---------|----------|
| `composer validate` | OK |
| `composer audit` | Keine Advisories |
| `bin/console lint:container` | OK |
| `bin/console lint:yaml --parse-tags config/` | OK |
| `bin/console about` | Symfony 8.1.0 |
| Messenger Consumer (Deprecation-Helper) | Keine Deprecations |
| PHPUnit unit (774) | OK |
| PHPUnit integration (407) | OK |
| PHPUnit functional (366) | OK |

## Verbleibende Risiken

1. **EasyAdmin 4.x** – Bugfix-Only; UX-Bundles bleiben auf 2.x bis EasyAdmin 5.x-Upgrade
2. **PHPUnit `ignoreIndirectDeprecations=true`** – Vendor-Deprecations werden in CI weiterhin unterdrückt; perspektivisch auf `false` setzen
3. **Test-Cache** – Nach Major-Upgrades `var/cache/test` löschen oder `cache:warmup --env=test` ausführen (stale VarExporter-Hydrator-Caches)
4. **Symfony 8.1 kein LTS** – Wartung bis 01/2027; späteres Upgrade auf 8.4 LTS planen

## Empfehlungen

- CI-Schritt: `php bin/console cache:warmup --env=test` vor ParaTest
- Dependabot: Constraints in `composer.json` nutzen explizite `8.1.*` für Symfony, Caret für unabhängige Bundles
- Nächstes Upgrade: EasyAdmin 5.x + UX-Bundles 3.x als separates PR
- Deprecation-CI-Job mit `SYMFONY_DEPRECATIONS_HELPER=max[total]=0` für Console/Messenger-Pfade
