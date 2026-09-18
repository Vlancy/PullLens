# Docker Installation

This guide explains how to install and run PullLens with Docker Compose.

## Requirements

- Docker Engine
- Docker Compose plugin
- Git
- Internet access to pull container images and build dependencies

## Services

The Docker stack includes:

- Nginx web server
- Laravel PHP-FPM application container
- Laravel Horizon queue worker
- Laravel scheduler
- PostgreSQL database
- Valkey (Redis-compatible) cache, queue, and session store
- Soketi WebSocket server

## Stack Dependencies

The Docker stack builds and runs the required application dependencies for you:

- PHP 8.4+ for the Laravel runtime
- Node.js 24+ for frontend asset builds
- PostgreSQL 17+ for persistent application data
- Valkey 8+ as the open-source Redis-compatible cache, queue, and session backend
- Nginx Alpine as the HTTP entrypoint
- Soketi as the Laravel-compatible WebSocket server

Valkey is used instead of Redis because it is open source and Redis-compatible. Laravel still uses the standard Redis configuration names, so values such as `REDIS_HOST=redis` remain correct.

## Installation

1. Clone the repository.

```bash
git clone https://github.com/vlancy/pulllens.git
cd pulllens
```

2. Create the environment file.

```bash
cp .env.example .env
```

3. Update the required values in `.env`.

At minimum, review:

- `APP_URL`
- `APP_KEY`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `PUSHER_APP_ID`
- `PUSHER_APP_KEY`
- `PUSHER_APP_SECRET`

The Docker-specific values are listed at the end of `.env.example`.

4. Build and start the containers.

```bash
docker compose up -d --build
```

5. Generate the Laravel application key if `APP_KEY` is empty.

```bash
docker compose exec app php artisan key:generate
```

6. Run database migrations.

```bash
docker compose exec app php artisan migrate --force
```

7. Optimize the application.

```bash
docker compose exec app php artisan optimize
```

## Access

Open the application in your browser:

```text
http://localhost
```

If you change `APP_PORT` in `.env`, use that port instead.

## HTTPS

The stack speaks plain HTTP on `APP_PORT`; TLS is terminated in front of it. The supplied script sets that up on a Linux host:

```bash
./install_ssl.sh
```

It publishes the Nginx container on an internal port - `8080` if `APP_PORT` was 80 - installs Nginx and certbot on the host, proxies your domain to the container with websocket support, and issues a Let's Encrypt certificate that renews itself. See [INSTALL.md](INSTALL.md#https) for the full description.

To use your own proxy instead, forward to `127.0.0.1:${APP_PORT}` and pass the usual headers. `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port` and `X-Forwarded-For` are trusted by the application, so Laravel generates `https://` URLs without further configuration. Soketi listens separately on `SOKETI_FORWARD_PORT`, and its `/app/` and `/apps/` paths need proxying too if you want realtime updates over `wss://`.

## Useful Commands

Start the stack:

```bash
docker compose up -d
```

Stop the stack:

```bash
docker compose down
```

View logs:

```bash
docker compose logs -f
```

Run Artisan commands:

```bash
docker compose exec app php artisan about
```

Run Horizon manually if needed:

```bash
docker compose exec horizon php artisan horizon:status
```

## Persistent Data

PostgreSQL and Valkey data are stored in Docker volumes:

- `pulllens_pgsql`
- `pulllens_redis`

To remove containers without deleting data:

```bash
docker compose down
```

To remove containers and stored data:

```bash
docker compose down -v
```

Use `docker compose down -v` carefully because it deletes the database and Valkey volumes.
