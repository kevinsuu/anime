# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commit messages

Follow `docs/commit-guidelines.md` for every commit: Chinese subject/body with an English Conventional Commits type prefix (`feat:`, `fix:`, `refactor:`, `chore:`, `docs:`, `test:`, `style:`, `perf:`). Split unrelated changes into separate commits. Never write real secrets, internal hostnames/IPs, or personal emails into a commit message or diff — this repo is public.

## Local development

The stack runs via Docker Compose from the repo root — **backend PHP/artisan/composer commands only work inside the container**, not on the host.

```bash
docker compose up --build          # start mysql, backend, scheduler, frontend, phpmyadmin
docker compose exec backend php artisan test              # run backend tests
docker compose exec backend php artisan test --filter=X   # run a single test
docker compose exec backend php artisan migrate
docker compose exec backend php artisan anime:scrape-acgsecrets [--all]
docker compose exec backend php artisan anime:import-acgsecrets
```

Frontend tests run outside Docker (Vitest):

```bash
cd frontend && npm run test
```

Service URLs: frontend `:3000`, backend `:8080`, phpMyAdmin `:8081`.

Environment variables live in a **single root `.env`** (copy from `.env.example`) — `docker-compose.yml` interpolates `${VAR}` into every service's `environment:` block. There is no `backend/.env`; editing that file has no effect on the running containers.

### Docker rebuild rules

- Backend `app/`, `routes/`, frontend `app/` (pages/components/composables/utils): hot-reloads, no restart needed.
- Backend `config/`: needs `docker compose restart backend` if config is cached.
- `database/migrations/`: needs `docker compose exec backend php artisan migrate`.
- `.env` or `docker-compose.yml` `environment:` changes: needs `docker compose restart <service>` (or `up -d --force-recreate` to be safe).
- `composer.json`/`package.json` changes: `docker compose up --build <service>`.
- `Dockerfile` changes: `docker compose up --build <service>`.

`DEV_AUTH_BYPASS=true` and the frontend dev-login button only work when `APP_ENV=local` — the backend hard-requires local env even if the flag is left on by mistake, so a misconfigured production `.env` can't open an auth backdoor.

## Architecture

### Data pipeline (this is the core domain logic)

Anime catalog data does **not** come from user input. It's scraped from acgsecrets.hk into git-tracked JSON snapshots (`backend/database/seed/acgsecrets/*.json`, one file per season), then imported into the DB:

```
ScrapeAcgSecrets (command) → AcgSecretsClient (HTTP) → AcgSecretsParser (HTML → structured records)
  → writes JSON to database/seed/acgsecrets/
  → ImportAcgSecrets (command) → AnimeImportService → upserts into anime + detail tables
```

`routes/console.php` schedules this weekly (Sunday 05:00) via the `scheduler` container running `php artisan schedule:work`. There is no manual "create anime" UI or endpoint — catalog growth happens exclusively through this scrape→JSON→import pipeline, so any anime metadata change should go through the parser/importer, not a one-off DB write.

### Backend structure

```
backend/app/
├── Http/Controllers/Api/   # AnimeController, AnimeListController, AuthController, CollectionController
├── Http/Middleware/        # AuthenticateJwt (aliased as 'jwt' in bootstrap/app.php)
├── Models/                 # Eloquent models, incl. Anime + its detail relations (cast/staff/themes/trailers/links)
└── Services/
    ├── AnimeCatalog/       # AcgSecretsClient, AcgSecretsParser, AnimeImportService, SeasonResolver
    ├── Auth/                # GoogleTokenVerifier, JwtService, RefreshTokenService
    └── Shared/              # SlugGenerator (public share links)
```

`routes/api.php` has no `/api` prefix (`apiPrefix: ''` in `bootstrap/app.php`) — in production this is fronted by nginx rewriting `/api/*` to the backend's root paths, since Laravel and the Nuxt frontend share one domain.

### Auth model

Google Identity Services issues an ID token client-side → `POST /auth/google` verifies it (`GoogleTokenVerifier`) and issues a short-lived JWT (1h) + a rotating refresh token (30d, hashed at rest in `refresh_tokens`, single-use — each `POST /auth/refresh` call revokes the used token and issues a new one). The frontend (`useApi.ts`) transparently retries a 401 once via refresh before surfacing an error, and de-dupes concurrent refresh calls into a single in-flight request.

### Frontend structure

Nuxt 4, `ssr: false` (pure SPA). Key composables:

- `useApi.ts` — wraps all backend calls, handles JWT attach + refresh-and-retry
- `useSession.ts` — **module-level singleton** reactive state (not per-call) so login state is consistent across every component; lazily hydrates from `localStorage` on first client-side use
- `useSeasonalCatalog.ts` — derives filter options (genre/source/actor) from the loaded anime list's `tags`/`cast`, not a hardcoded keyword list

`app/pages/index.vue` redirects to `/seasonal` (seasonal chart is the homepage). `app/middleware/auth.ts` guards `/list` and `/settings` via `definePageMeta({ middleware: 'auth' })`.

Catalog browsing (`/catalog`) defaults to the current year and paginates client-side at 40/page, caching per-year results for the session — the backend caps unscoped keyword search at 200 rows but returns the full year when `year` is passed without `season`.

Env vars are split by trust boundary: server-side config (`.env`) vs. `NUXT_PUBLIC_*` (baked into the client bundle at build time — never put real secrets there).

## Deployment

Production uses Docker images built by GitHub Actions and pushed to GHCR (`ghcr.io/kevinsuu/anime-{backend,scheduler,frontend}`), deployed via `deploy/docker-compose.yml` over SSH. See `deploy/README.md` for host setup and required GitHub secrets.

- Push to any branch → CI only (`~/.github/workflows/ci.yml`): backend tests, frontend build check, docker build dry-run. No deploy.
- Push a `vX.Y.Z` tag → `~/.github/workflows/deploy.yml` builds+pushes images and SSHes into the deploy host to `docker compose pull && up -d`.

`backend/Dockerfile.production` (PHP-FPM + nginx) and `backend/Dockerfile.scheduler` are separate from the dev `backend/Dockerfile` (`php artisan serve`, not suitable for concurrent production traffic). `backend/.dockerignore` matters here — without it, a stale `bootstrap/cache/packages.php` referencing dev-only packages (e.g. `laravel/pail`) leaks into the build context and crashes `composer dump-autoload --no-dev`.
