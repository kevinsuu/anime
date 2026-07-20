# Host nginx deployment

This public document describes only the nginx routes required by the anime
application. Keep the full inventory of co-located services, private host paths,
and unrelated domains in a private operations repository.

## Topology

The host's native nginx service owns ports 80 and 443, terminates TLS, and
proxies to containers bound on loopback. The application exposes only these
host ports:

| Service | Loopback port | Public route |
|---|---|---|
| backend | `127.0.0.1:8080` | `/api/`, `/storage/` |
| frontend | `127.0.0.1:3000` | `/` |

Binding to `127.0.0.1` prevents direct external access to the containers.
Do not use a host mapping such as `8080:8080`, which binds on every interface.

## Docker Compose requirement

Every service reached by nginx must bind explicitly to loopback:

```yaml
services:
  backend:
    ports:
      - "127.0.0.1:8080:8080"
```

The application Compose file must not start another service that owns host
ports 80 or 443.

## nginx configuration

Merge the following application-specific blocks into the host's private nginx
configuration. The exact config filename and any unrelated server blocks are
host implementation details and should not be committed here.

```nginx
upstream anime_backend {
    server 127.0.0.1:8080;
}

upstream anime_frontend {
    server 127.0.0.1:3000;
}

server {
    listen 80;
    listen [::]:80;
    server_name anime.kaistarstudio.me;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

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

    # Compress SSR HTML and API/text responses. Nuxt also ships precompressed
    # immutable JS/CSS assets, which nginx forwards using the browser's
    # Accept-Encoding header.
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_comp_level 5;
    gzip_types text/plain text/css application/json application/javascript application/xml image/svg+xml;

    location /api/ {
        proxy_pass http://anime_backend/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Laravel public disk (anime cover thumbnails)
    location /storage/ {
        proxy_pass http://anime_backend/storage/;
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

Check `nginx -v` before applying the example. Older versions require the
combined `listen 443 ssl http2;` form; newer versions may prefer a standalone
`http2 on;` directive.

After updating the private host configuration:

```bash
sudo nginx -t
sudo systemctl reload nginx
sudo certbot certonly --nginx -d anime.kaistarstudio.me
sudo certbot renew --dry-run
```

Certbot normally installs a systemd timer or cron job. Confirm the host's
renewal schedule without recording host-specific details in this repository.
