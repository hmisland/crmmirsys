# CRM Platform

A commercial, modular, multi-tenant, multilingual CRM platform. Starts on
plain shared cPanel hosting (PHP + MySQL + Apache) and is architected to
migrate gradually to React + FastAPI/Python + PostgreSQL without a
destructive rewrite.

See `docs/architecture.md` for the full approved Phase 0 architecture
(module map, RBAC/permission-scope model, multi-tenancy, Wallet/ledger
design, Currency Management, security, testing, and the phase-by-phase
roadmap), and `docs/phase1-foundation.md` for what Phase 1 (this
codebase's current state) actually implements.

## Current status

**Phase 11 — Localization.** Real translation infrastructure (English/
Persian/Arabic/Turkish) is fully built; only the nav and login/register
pages are actually converted so far — every other template still shows
English regardless of the selected language, converted module by module
in further deliveries. See `docs/phase11-localization.md` for the exact
scope, and `CHANGELOG.md` for the phase-by-phase history.

See `docs/conventions.md` for cross-cutting rules that apply everywhere
in the app, not just one phase (e.g. the date-field convention).

## Requirements

- PHP 8.1+
- MySQL/MariaDB (InnoDB, utf8mb4)
- Apache with `mod_rewrite`
- No Composer/Node/SSH required to *run* the application — see
  `docs/phase1-foundation.md` for why, and for local dev setup.

## Quick start (local development)

```
cp .env.example .env      # fill in real DB credentials
php -S localhost:8000 -t public
```

Visit `http://localhost:8000/`.

## License

Proprietary — not for redistribution.
