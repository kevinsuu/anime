# Deploying anime.kaistarstudio.me

## Overview

- `push` to any branch → CI only (backend tests, frontend build check, docker build check). No deploy.
- `git tag vX.Y.Z && git push --tags` → builds & pushes 3 images to GHCR, then SSHes into the
  deploy host and runs `docker compose pull && up -d`.

Images: `ghcr.io/kevinsuu/anime-{backend,scheduler,frontend}:vX.Y.Z` (and `:latest`).

## One-time host setup

This repo's `deploy/docker-compose.yml` does **not** run its own nginx/certbot — the host's native
nginx (systemd service) is the single reverse proxy for every project on that machine (anime,
DiscordAIBot, RecordSystem, ...). Each project's containers only bind to `127.0.0.1:<port>`; nginx
on the host terminates TLS and proxies to those loopback ports. See
[`../docs/shared-nginx-deployment.md`](../docs/shared-nginx-deployment.md) for the full rationale
and the shared nginx server-block template — that doc is the single source of truth shared across
all three projects deployed on this host; keep this section in sync with it.

1. Create the deploy directory on the host and copy `deploy/docker-compose.yml` there:

   ```
   ssh <user>@<host> mkdir -p ~/anime-deploy
   scp deploy/docker-compose.yml <user>@<host>:~/anime-deploy/
   ```

2. Copy `deploy/.env.production.example` to `~/anime-deploy/.env` on the host and fill in real
   values (DB password, JWT_SECRET via `openssl rand -base64 48`, Google OAuth credentials, APP_KEY
   via `php artisan key:generate --show` run locally).

3. GHCR images are private by default. Log in on the host once so `docker compose pull` works:

   ```
   docker login ghcr.io -u <github-username>
   ```

   (use a GitHub PAT with `read:packages` scope as the password)

4. `docker compose up -d` — `backend` binds `127.0.0.1:8080`, `frontend` binds `127.0.0.1:3000`.

5. Add (or update) the `anime.kaistarstudio.me` server block in the host's shared nginx config per
   [`../docs/shared-nginx-deployment.md`](../docs/shared-nginx-deployment.md), then issue the cert:

   ```
   sudo certbot certonly --nginx -d anime.kaistarstudio.me
   sudo nginx -t && sudo systemctl reload nginx
   ```

   Renewal is automatic — certbot installs a systemd timer/cron job on first install
   (`systemctl list-timers | grep certbot` to confirm) and `certbot renew` re-runs the same
   `--nginx` plugin, so no manual steps are needed afterward.

6. DNS: point `anime.kaistarstudio.me` A record at the host's public IP.

7. Google Cloud Console: add `https://anime.kaistarstudio.me` to the OAuth client's Authorized
   JavaScript origins.

## GitHub repo secrets (Settings → Secrets and variables → Actions)

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | host IP/domain |
| `DEPLOY_USER` | SSH user |
| `DEPLOY_SSH_KEY` | private key with access to that user (add the matching public key to the host's `~/.ssh/authorized_keys`) |
| `DEPLOY_PATH` | absolute path to the deploy dir on the host, e.g. `/home/<user>/anime-deploy` |
| `NUXT_PUBLIC_GOOGLE_CLIENT_ID` | same Google OAuth client ID as in the host's `.env` |

`GITHUB_TOKEN` for pushing to GHCR is provided automatically by Actions — no setup needed, but make
sure the repo's package visibility/permissions allow the workflow to publish (Settings → Actions →
General → Workflow permissions → Read and write).

## Shipping a release

```
git tag v1.0.0
git push --tags
```

Watch the Actions run. On success, `docker compose ps` on the host should show `backend`,
`scheduler`, `frontend`, and `mysql` all healthy. The backend entrypoint runs
`php artisan migrate --force` before starting PHP-FPM and nginx, so migrations have a single
execution owner and no manual DB setup step is needed after the first deploy.

## Rollback

Edit `.env` on the host, point the three `*_IMAGE` vars at a previous tag, then:

```
docker compose pull
docker compose up -d
```

## First deploy only: seeding real data

The scheduler runs `anime:scrape-acgsecrets` weekly (Sunday 05:00) which imports automatically, but
that means an empty catalog until the first Sunday. To seed immediately after the first deploy:

```
docker compose exec backend php artisan anime:scrape-acgsecrets --all
docker compose exec backend php artisan anime:import-acgsecrets
```

## Personal watched list (seed/mylist)

`backend/database/seed/mylist/` holds personal seed data in the same JSON structure as
acgsecrets; `anime:import-acgsecrets` imports both directories. If `MYLIST_OWNER_EMAIL`
is set in the host `.env`, the import also marks every entry of
`seed/mylist/watched.json` as watched for that user (the account is pre-created and
claimed automatically on that user's first Google login). Add to the deploy host `.env`:

```
MYLIST_OWNER_EMAIL=you@example.com
```
