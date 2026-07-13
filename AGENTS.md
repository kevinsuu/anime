# Repository Guidelines

## Project Structure & Module Organization

The Nuxt 4 SPA lives in `frontend/`. Under `frontend/app/`, file-based routes belong in `pages/`, reusable UI in `components/`, shared state and API access in `composables/`, and pure helpers in `utils/`. Tests are in `frontend/test/`; static files are in `frontend/public/`.

The Laravel 13 API is under `backend/app/`, organized into controllers, models, console commands, and services. Routes are in `backend/routes/`; migrations and tracked catalog snapshots are in `backend/database/`. PHPUnit suites are split between `backend/tests/Unit/` and `backend/tests/Feature/`. Deployment files live in `deploy/`, and design notes in `docs/`.

Catalog changes should follow the scrape -> JSON snapshot -> import pipeline. Do not make one-off database edits for anime metadata.

## Build, Test, and Development Commands

- `cp .env.example .env` creates the local configuration.
- `docker compose up --build` starts MySQL, Laravel, the scheduler, Nuxt, and phpMyAdmin.
- `docker compose exec backend php artisan migrate` applies database migrations.
- `docker compose exec backend php artisan test` runs all backend tests; add `--filter=TestName` for a focused run.
- `cd frontend && npm run test` runs Vitest once.
- `cd frontend && npm run build` performs the production Nuxt build check.

Run PHP, Artisan, Composer, and Pint inside the backend container; PHP is not expected on the host.

## Coding Style & Naming Conventions

Use four-space indentation and PSR-12/Laravel conventions for PHP; use two spaces, no semicolons, and TypeScript for Vue/Nuxt code. Use PascalCase for Vue components and PHP classes, `useXxx` for composables, and camelCase for functions and variables. Format PHP with `docker compose exec backend ./vendor/bin/pint`. No frontend linter is configured, so follow adjacent files.

## Testing Guidelines

Name frontend tests `*.test.ts`. Name backend classes `*Test.php`, place isolated logic in `Unit`, and HTTP/database behavior in `Feature`. Add regression coverage for bug fixes and focused tests for new behavior. No numeric coverage threshold is configured.

## Commit & Pull Request Guidelines

Use an English Conventional Commit type with a concise Chinese summary, for example `feat: 新增季度篩選功能`. Keep unrelated changes in separate commits; see `docs/commit-guidelines.md`. Pull requests should describe scope and tradeoffs, link relevant issues, list verification commands and results, flag migrations or configuration changes, and include screenshots for visible UI updates.

## Security & Configuration

Never commit `.env`, credentials, tokens, or private host details. Root `.env` is the single local environment source. Treat every `NUXT_PUBLIC_*` value as public because it is bundled into client code.
