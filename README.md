# Laravel Docker Infrastructure (Stage 1)

Minimal Dockerized setup for a Laravel app with Nginx, Postgres, Redis, queue worker, scheduler, and Mailpit.  
Includes health endpoints, Makefile, and dev tools (Pint, PHPStan, PHPUnit).

This guide helps any developer spin up the project quickly, with good performance on Windows/macOS/Linux.

---

## Prerequisites

- Docker + Docker Compose
- Make (optional, but convenient)
- Free ports: **80** (Nginx), **5432** (Postgres), **6379** (Redis), **8025/1025** (Mailpit)

> If ports are in use, see **Ports Busy (override)** below.

---

## 1) Setup

```bash
git clone <repo> && cd <repo>
cp .env.example .env
```

Recommended `.env` (fast in Docker):

```
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=app
DB_USERNAME=app
DB_PASSWORD=app

REDIS_HOST=redis
REDIS_PORT=6379
# Redis client (choose one; Predis is easiest for dev):
REDIS_CLIENT=predis

CACHE_DRIVER=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

---

## 2) Start containers

```bash
make up      # or: docker compose up -d --build
```

---

## 3) One-time permissions/bootstrap (inside container)

```bash
docker compose exec -u root app sh -lc '
  install -d -m 0775 -o www-data -g www-data \
    /var/www/html/vendor \
    /var/www/html/storage \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/bootstrap/cache
'
```

---

## 4) Dependencies & app key

```bash
# Predis for Redis without rebuilding the image
docker compose exec app composer require predis/predis:^2.0 -W

docker compose exec app composer install -o --no-interaction
docker compose exec app php artisan key:generate
docker compose exec app php artisan storage:link || true
```

---

## 5) Migrations & seeders

```bash
docker compose exec app php artisan migrate

# Demo data (if included in the repo)
docker compose exec app php artisan db:seed --class=SampleExamSeeder

# Admin user for /admin (email: admin@example.com / password: password)
docker compose exec app php artisan db:seed --class=AdminUserSeeder
```

---

## 6) Dev optimizations (for snappy UX)

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app composer dump-autoload -o
docker compose exec app php artisan optimize
```

> If you edit routes/views often:  
> `php artisan route:clear && php artisan view:clear && php artisan optimize`

---

## 7) Verify

- App: http://localhost  
- Health: http://localhost/health  
- API exams list: `curl -s http://localhost/api/exams | jq .`  
- Front flow: http://localhost/exams  
- Admin (Nova): http://localhost:8080/nova  
- Mailpit UI: http://localhost:8025

---

## Useful Make targets

```bash
make up       # docker compose up -d --build
make down     # docker compose down -v (also removes volumes)
make migrate  # php artisan migrate
make seed     # php artisan db:seed
make test     # php artisan test
make cs       # pint
make stan     # phpstan
```

---

## Ports Busy (override without touching main compose)

Create `docker-compose.override.yml` next to the main file:

```yaml
services:
  postgres:
    ports: ["55432:5432"]
  redis:
    ports: ["56379:6379"]
  mailpit:
    ports:
      - "18025:8025"
      - "11025:1025"
```

Run:

```bash
docker compose up -d
```

> Keep **internal** ports in `.env` (`DB_PORT=5432`, `REDIS_PORT=6379`)—those are used inside the Docker network.

---

## Performance tips (Windows/macOS)

1) Ensure `vendor` and `storage` are **named volumes** in `docker-compose.yml` (avoids slow bind mounts).  
2) Use **Redis** for cache/sessions (see `.env` above).  
3) On Windows, keep the project on **WSL2 (ext4)** for 3–10× faster I/O than `C:\` bind mounts.

---

## Troubleshooting

- **`Class "Redis" not found`**  
  Use Predis:  
  `docker compose exec app composer require predis/predis:^2.0 -W`

- **`relation "cache" does not exist` during `cache:clear/optimize`**  
  You’re on database cache. Switch to Redis (`CACHE_STORE=redis`, `CACHE_DRIVER=redis`) and run:  
  `php artisan config:clear && php artisan cache:clear && php artisan optimize`  
  *(Alternatively create the table: `php artisan cache:table && php artisan migrate`.)*

- **Slow on Windows/macOS**  
  Confirm named volumes for `vendor` and `storage`, use Redis cache/sessions, prefer WSL2 filesystem.

- **`chmod: missing operand` when fixing permissions**  
  Use `find -exec` (safe when no files yet):
  ```bash
  docker compose exec -u root app sh -lc '
    mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache &&
    find storage bootstrap/cache -type d -exec chmod 775 {} \; &&
    find storage bootstrap/cache -type f -exec chmod 664 {} \;
  '
  ```

- **`502 Bad Gateway`**  
  Check logs: `docker compose logs nginx app | tail -n 100`. Usually `app` not up or missing `public/index.php`.

---

## Clean restart (when things drift)

```bash
make down
make up
# then repeat: sections 3 → 4 → 5 → 6
```

---

