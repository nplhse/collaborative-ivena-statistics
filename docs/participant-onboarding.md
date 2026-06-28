# Participant onboarding (dashboard)

Alpha onboarding for users with `ROLE_PARTICIPANT`: a dashboard card guides new participants through five steps until all are completed.

## Steps

1. Request clinic access (feedback drawer)
2. Review own clinic
3. Start first import
4. Browse imported allocations in Explore (links to `/explore/allocation?hospitalFilter=my_hospitals`)
5. Open statistics overview

Step availability depends on hospital permissions and prior step completion. Completed steps are stored in `user_onboarding_step`.

## Commands

```bash
# Idempotent backfill for existing participants (clinic access + imports in last 6 months)
php bin/console app:onboarding:initialize

# Dry run
php bin/console app:onboarding:initialize --dry-run

# Single user
php bin/console app:onboarding:initialize --user-id=42
```

## Code locations

| Area | Path |
|---|---|
| Domain / application | `src/Onboarding/` |
| Dashboard card | `src/Content/UI/Twig/templates/dashboard/_onboarding_card.html.twig` |
| Complete step endpoint | `POST /onboarding/steps/{stepKey}/complete` |
| Init command | `src/Onboarding/UI/Console/Command/InitializeOnboardingCommand.php` |
