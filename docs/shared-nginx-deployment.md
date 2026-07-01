# Shared nginx deployment (kaistarstudio.me host)

This doc is duplicated verbatim across the projects that share one deploy host
(anime, DiscordAIBot, RecordSystem) so each repo is self-contained when cloned
onto a fresh machine. If you edit this file, copy the same changes to the other
two repos' `docs/shared-nginx-deployment.md`.

## Why: one native nginx, not per-project proxy containers

Each project used to run its own dockerized nginx (or `nginx-proxy` +
`acme-companion`) to own ports 80/443 directly. On a host running multiple
projects, only one process can bind 80/443 — every extra proxy container fights
the others for the port and loses on restart, which is what originally broke
this setup (a project's `docker compose up` failed with `address already in
use`, and separately a `docker-compose.yml` unhealthy-dependency chain failed
when MySQL couldn't start under the container runtime's ioctl restrictions —
unrelated bug, fixed by adding `cap_add: [SYS_NICE]` to the mysql service).

The fix: the host's **native nginx (systemd service) is the only thing that
binds 80/443**. Every project's docker-compose only exposes its service(s) on
`127.0.0.1:<port>` (loopback only, not reachable from outside the host).
Native nginx has one server block per domain that proxies to the right
loopback port and terminates TLS via `certbot --nginx`.

Adding a new project to this host means: pick free loopback ports, bind the
project's containers to them, add one more `server_name` block to the shared
nginx config, and issue a cert. No changes to any other project's containers.

## Current port assignments on this host

| Project | Service | Loopback port | Domain |
|---|---|---|---|
| anime | backend | `127.0.0.1:8080` | anime.kaistarstudio.me (`/api/`) |
| anime | frontend | `127.0.0.1:3000` | anime.kaistarstudio.me (`/`) |
| DiscordAIBot | multi-bot | `127.0.0.1:5000` | discordbot.kaistarstudio.me |
| RecordSystem | frontend | `127.0.0.1:8002` | record-system.kaistarstudio.me |

When adding a project, pick an unused port from this table's gaps and update
this table in all three copies of this doc.

## Per-project docker-compose requirement

Each service that needs to be reachable from nginx must bind explicitly to
loopback, e.g.:

```yaml
services:
  backend:
    ports:
      - "127.0.0.1:8080:8080"
```

Do **not** use `ports: - "8080:8080"` (binds `0.0.0.0`, bypasses nginx and
exposes the port publicly) and do **not** run a `proxy`/`nginx-proxy`/`certbot`
service in the project's own compose file — that's the host's job now.

## Host nginx config

Config lives at `/etc/nginx/sites-available/kaistarstudio-sites.conf`, symlinked
into `/etc/nginx/sites-enabled/`. One `upstream` + `server` block per domain,
sharing a single HTTP→HTTPS redirect block:

```nginx
upstream record_system {
    server 127.0.0.1:8002;
}

upstream discord_bot {
    server 127.0.0.1:5000;
}

upstream anime_backend {
    server 127.0.0.1:8080;
}

upstream anime_frontend {
    server 127.0.0.1:3000;
}

# HTTP -> HTTPS redirect (shared across all domains, incl. ACME challenge)
server {
    listen 80;
    listen [::]:80;
    server_name record-system.kaistarstudio.me discordbot.kaistarstudio.me anime.kaistarstudio.me;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

# record-system
server {
    listen 443 ssl http2;
    server_name record-system.kaistarstudio.me;

    ssl_certificate /etc/letsencrypt/live/record-system.kaistarstudio.me/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/record-system.kaistarstudio.me/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        proxy_pass http://record_system;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}

# discord bot
server {
    listen 443 ssl http2;
    server_name discordbot.kaistarstudio.me;

    ssl_certificate /etc/letsencrypt/live/discordbot.kaistarstudio.me/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/discordbot.kaistarstudio.me/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location /linebot/callback {
        proxy_pass http://discord_bot/callback;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Line-Signature $http_x_line_signature;
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }

    location /health {
        proxy_pass http://discord_bot/health;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        access_log off;
    }

    location / {
        proxy_pass http://discord_bot;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# anime
server {
    listen 443 ssl http2;
    server_name anime.kaistarstudio.me;

    ssl_certificate /etc/letsencrypt/live/anime.kaistarstudio.me/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/anime.kaistarstudio.me/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location /api/ {
        proxy_pass http://anime_backend/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location / {
        proxy_pass http://anime_frontend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Note: `listen 443 ssl http2;` (combined form) — not the newer standalone
`http2 on;` directive — because the host's nginx version predates 1.25.1.
Check with `nginx -v` before copying snippets from elsewhere; the standalone
form fails with `unknown directive "http2"` on older builds.

## Adding a new domain to this host

1. Pick a free loopback port (see table above), bind the new project's
   container(s) to `127.0.0.1:<port>`.
2. Add an `upstream` block and a `server { listen 443 ssl http2; ... }` block
   to `/etc/nginx/sites-available/kaistarstudio-sites.conf`, and add the new
   domain to the shared `server_name` list in the port-80 redirect block (needed
   so certbot's HTTP-01 challenge can be routed before the cert exists).
3. `sudo nginx -t` — if it fails because the new domain's cert doesn't exist
   yet, temporarily comment out just the new 443 server block, re-test, and
   `sudo systemctl reload nginx` so the other domains keep working while you
   get the cert.
4. `sudo certbot certonly --nginx -d <new-domain>` — requires DNS already
   pointing at this host and port 80 reachable from the internet.
5. Uncomment the new 443 server block, `sudo nginx -t && sudo systemctl reload nginx`.

## Renewal

Automatic. `certbot` installs a systemd timer (or cron job) on first install
that runs `certbot renew` twice daily; it only re-issues certs within 30 days
of expiry and reuses each cert's recorded plugin (`--nginx` here), reloading
nginx itself. Verify the timer exists with:

```
systemctl list-timers | grep certbot
```

Confirm the whole flow works without actually renewing:

```
sudo certbot renew --dry-run
```
