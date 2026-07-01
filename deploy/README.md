# Deploying anime.kaistarstudio.me

## Overview

- `push` to any branch → CI only (backend tests, frontend build check, docker build check). No deploy.
- `git tag vX.Y.Z && git push --tags` → builds & pushes 3 images to GHCR, then SSHes into the
  deploy host and runs `docker compose pull && up -d`.

Images: `ghcr.io/kevinsuu/anime-{backend,scheduler,frontend}:vX.Y.Z` (and `:latest`).

## One-time host setup

1. Pick the target VM and confirm what's already listening on 80/443 (`docker ps`, `docker inspect
   nginx-proxy` if one exists). This repo's `deploy/nginx.conf` assumes it owns port 80/443 directly
   with its own certbot-managed certs — if the host already runs a shared `nginx-proxy` +
   `acme-companion` setup instead, skip `deploy/nginx.conf` and the `proxy`/certbot volumes in
   `docker-compose.yml`, and instead just add `VIRTUAL_HOST` / `LETSENCRYPT_HOST` env vars to the
   `frontend` and `backend` services and join the shared proxy network.

2. Create the deploy directory on the host and copy `deploy/docker-compose.yml` and
   `deploy/nginx.conf` (if using the standalone proxy path) there:

   ```
   ssh <user>@<host> mkdir -p ~/anime-deploy
   scp deploy/docker-compose.yml deploy/nginx.conf <user>@<host>:~/anime-deploy/
   ```

3. Copy `deploy/.env.production.example` to `~/anime-deploy/.env` on the host and fill in real
   values (DB password, JWT_SECRET via `openssl rand -base64 48`, Google OAuth credentials, APP_KEY
   via `php artisan key:generate --show` run locally).

4. GHCR images are private by default. Log in on the host once so `docker compose pull` works:

   ```
   docker login ghcr.io -u <github-username>
   ```

   (use a GitHub PAT with `read:packages` scope as the password)

5. First-run TLS cert (standalone proxy path only — skip if using a shared nginx-proxy):

   ```
   docker compose up -d proxy
   docker run --rm -v anime-deploy_certbot-etc:/etc/letsencrypt \
     -v anime-deploy_certbot-www:/var/www/certbot \
     certbot/certbot certonly --webroot -w /var/www/certbot \
     -d anime.kaistarstudio.me --email you@example.com --agree-tos --no-eff-email
   docker compose restart proxy
   ```

   Set up renewal via cron/systemd timer running `certbot renew` against the same volumes, or add a
   long-running `certbot/certbot` sidecar (see the existing `certbot` container pattern already used
   on the other host).

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
`scheduler`, `frontend`, `mysql`, and `proxy` (if standalone) all healthy.

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
