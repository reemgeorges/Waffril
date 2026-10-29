# Laravel Base — Claude Project Instructions

This is an existing Laravel 12 / PHP 8.2+ API starter. Preserve its API response envelope, conventions, and public contracts unless a tested migration plan explicitly changes them. Use `laravel-architect` agent for architectural refactors and module generation.

## Mandatory workflow
1. Inspect the actual source, consumers, migration state, test suite and git status before editing.
2. Create `docs/starter-roadmap.md` and a prioritized, evidence-based audit. **Then implement the changes**, not just a proposal.
3. Work in small verified changes; avoid broad rewrites. Never overwrite user changes.
4. No destructive database operation, force delete, live data seeding or real provider dispatch without explicit authorization. Never display or commit credentials; ignore `.env`.
5. For changes to existing functions, show complete revised functions/files in the change summary, and report touched file paths.
6. Run relevant targeted tests, Pint, and route checks when possible. State exactly what ran and what could not run.
7. Generated public endpoints require explicit opt-in; secure routes by default.

## Existing areas to examine
- `app/Console/Commands/CodeAfterMigrate/` and `CodeBasics/`: scaffold generators, route generation and auth middleware registration.
- `bootstrap/app.php` and `routes/api/v1/`: Laravel 12 middleware and route registration.
- `app/Services/Basic/BasicCrudService.php`: existing activate/restore and deactivate/delete semantics are coupled; `delete()` calls `forceDelete()`.
- Existing auth services and OTP helper, users/admins models, tests, seeders, policies and migrations.

## Target architecture
- Declarative, idempotent `make:module` generator (model, migration, validated requests, controller, service, resource, routes, policy, middleware, factory, optional seeder, tests, docs); support `--dry-run`, and protect preexisting files.
- Configurable multi-model Sanctum auth scaffolding. Registration opt-in per auth module; configurable guards/login identifiers. Keep account active, verified, and soft-deleted states distinct.
- Purpose-bound OTP with strong random generation, hashed storage, expiry, cooldown, throttling, attempt ceilings, atomic single-use consumption, channel provider adapters and fake testing driver. Password reset OTP cannot authenticate login.
- Separate activate/deactivate (`is_active`) from soft-delete/restore (`deleted_at`), with separately authorized permanent deletion. Do not silently break callers relying on legacy behavior: inventory and migrate compatibility.
- Route-specific, configurable authentication / permission middleware. Register aliases in Laravel 12 `bootstrap/app.php` if needed; verify route:list.
- Deterministic seeding: system, development and optional module seeders. Never execute production seeders as a side effect of scaffolding.
- OpenAPI/Postman generated from implemented contracts; drift checks.
- Private template repository / versioned reusable generator package / safe fresh-project installer avoiding project-by-project manual copying.
- Add self-tests for generation, idempotence, auth, OTP, authorization, soft-delete, route protection and doc output.

## Quality gates
No fabricated test results or unimplemented command examples presented as ready. No hard-coded OTP bypasses, credentials, public mutation routes, implicit production schema changes or broad `--force` calls. Report implementation progress and remaining failures.
