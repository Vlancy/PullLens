# Docker Installation

This guide explains how to install and run PullLens with Docker Compose.

## Requirements

- Docker Engine
- Docker Compose plugin
- Git
- Internet access to Docker Hub, to pull the container images

## Images

The application is published as a single public image, [`vlancy/pulllens`](https://hub.docker.com/r/vlancy/pulllens),
built for `linux/amd64` and `linux/arm64`. Docker serves whichever matches the host.
Every release is tagged four ways - `1.0.0`, `1.0`, `1` and `latest` - so you can follow
a release line as closely or as loosely as you like.

Everything else in the stack is an upstream image: `nginx:alpine`, `postgres:17-alpine`,
`valkey/valkey:8-alpine` and `quay.io/soketi/soketi`.

You still need this repository checked out. `compose.yml`, the Nginx configuration and
the installer all live here, and they have to match the image they run - which is why
`install.sh` derives the image tag from the `VERSION` file rather than letting you set
it by hand. To run a particular release, check out its tag and rerun the installer.

## Services

The Docker stack includes:

- Nginx web server
- Laravel PHP-FPM application container
- Laravel Horizon queue worker
- Laravel scheduler
- PostgreSQL database
- Valkey (Redis-compatible) cache, queue, and session store
- Soketi WebSocket server

Plus one short-lived container, `assets`, which runs at every start: it copies the
compiled frontend out of the application image into the volume Nginx serves from, then
exits. Nginx waits for it to exit cleanly, so the web server can never come up pointing
at a half-written document root. Seeing `pulllens-assets` in `Exited (0)` is correct;
seeing any other exit code means Nginx has nothing to serve.

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

4. Fetch the images and start the containers.

```bash
docker compose pull
docker compose up -d
```

To build the application from this checkout instead of pulling it - which is what you
want if you are working on PullLens itself - use `./install.sh --from-source`.

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

The stack can terminate TLS itself:

```bash
./install.sh --https
```

Nginx takes ports 80 and 443, the `certbot` container issues a Let's Encrypt
certificate over the ACME webroot challenge and renews it twice a day, and the
generated server block is written to `docker/config/nginx/tls.conf` - untracked,
because it names your domain. The certificate lives in the `pulllens_certs` volume.
See [INSTALL.md](INSTALL.md#https) for the full description.

Do not run it if something already terminates TLS in front of the stack: the challenge
would never reach this server. Set `APP_URL` to the public `https://` address instead.

To use your own proxy, set `APP_PORT` to a free port, forward to `127.0.0.1:${APP_PORT}` and pass the usual headers. `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port` and `X-Forwarded-For` are trusted by the application, so Laravel generates `https://` URLs without further configuration. Soketi listens separately on `SOKETI_FORWARD_PORT`, and its `/app/` and `/apps/` paths need proxying too if you want realtime updates over `wss://`.

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

Check which release a running instance actually is - the image reports itself, so this
is the answer rather than whatever `.env` asked for:

```bash
docker compose exec app php artisan about
```

## Secret scanning

PullLens scans every pull request diff for leaked credentials with
[gitleaks](https://github.com/gitleaks/gitleaks) (free, MIT). The Docker image installs a
pinned, checksum-verified binary. To use another build, set `GITLEAKS_BINARY` in `.env`.
Without the binary, scans are skipped and logged.

Each repository can turn it off under *Settings → Repository → Security*.

Findings appear in four places: an inline comment on the PR, the **Security** page
(*Secrets* tab), the **PullLens / Secrets** check (fails when anything is
found), and a git note on the scanned commit. GitHub's web UI does not show notes;
read them with:

    git fetch origin refs/notes/gitleaks:refs/notes/gitleaks
    git log --notes=gitleaks

To allowlist test fixtures, commit a `.gitleaks.toml` or `.gitleaksignore` to the
**target** branch. Files added by the pull request itself are ignored for this, so
a pull request cannot allowlist its own secret. Inline `gitleaks:allow` comments are
also ignored, for the same reason; allowlist fixtures in `.gitleaks.toml` on the
target branch instead.

Limitation: only the pull request's final diff is scanned. A secret added and then
removed inside the same pull request stays in its commit history undetected.

Each scan works in a temporary workspace holding the pull request's raw added lines.
Leftovers from a killed or crashed scan are deleted at the start of the next scan, and
again every hour by the Laravel scheduler container, so a quiet install is never left
holding them.

## Vulnerability scanning

PullLens checks the dependency lockfiles and infrastructure files a pull request
changes with [Trivy](https://github.com/aquasecurity/trivy) (free, Apache-2.0) and
reports only the known vulnerabilities and insecure settings the pull request
**introduces**. The Docker image installs a pinned, checksum-verified Trivy; for a
manual install, put Trivy 0.74.0 on `PATH` or set `TRIVY_BINARY` in `.env`.

Scans run offline against a local vulnerability database in
`storage/app/trivy-cache` (override with `TRIVY_CACHE_DIR`). The installer downloads
it once and the scheduler refreshes it every six hours with
`php artisan pulllens:update-trivy-db`. If the database is missing or older than
`TRIVY_MAX_DB_AGE_HOURS` (72), scans are skipped and the **PullLens / Vulnerabilities**
check shows neutral with a hint to refresh it.

New high or critical problems fail the check; new medium ones leave it neutral, and
so does a scan that could not read some of the changed files (the check lists them)
or that stopped at its limit of 100 files. At most 20 inline comments are posted per
scan; the rest are listed in the summary review. All security results - secrets,
vulnerabilities and misconfigurations - are on the **Security** page. Each repository
can turn vulnerability scanning off under *Settings → Repository → Security*.

Each scan works in a temporary workspace holding whole copies of the files it checks.
Leftovers from a killed or crashed scan are swept by the same hourly command that
sweeps secret-scan workspaces (`php artisan pulllens:sweep-secret-scans`).

## Troubleshooting

**`toomanyrequests` when pulling.** Docker Hub rate-limits anonymous pulls per source
address, which an operator behind shared NAT can reach without doing anything unusual.
Signing in with any free Docker Hub account raises the limit:

```bash
docker login
```

**The page loads unstyled, or assets 404.** Nginx is serving an asset tree that does not
match the running application. Republish it:

```bash
docker compose up -d --force-recreate assets
```

`./install.sh` checks for this on every run by comparing the asset manifest inside the
application container with the one Nginx is serving.

**Local edits to the Nginx config.** `docker/config/nginx/default.conf` is mounted into
the container from this clone, so edits take effect on restart - but they will also make
`git pull` refuse to fast-forward, which stops the installer. Keep customisations in a
separate file in that directory rather than editing the tracked one.

## Persistent Data

PostgreSQL and Valkey data are stored in Docker volumes:

- `pulllens_pgsql`
- `pulllens_redis`
- `pulllens_storage` - uploads, logs, the framework caches and the vulnerability database

One more volume, `pulllens_public`, holds the compiled frontend that Nginx serves. It is
not state: the `assets` container rebuilds it from the application image on every start,
replacing rather than merging, so an upgrade cannot leave last release's asset filenames
behind. Deleting it is harmless. If the browser ever loads the page unstyled, republish
it with:

```bash
docker compose up -d --force-recreate assets
```

To remove containers without deleting data:

```bash
docker compose down
```

To remove containers and stored data:

```bash
docker compose down -v
```

Use `docker compose down -v` carefully because it deletes the database and Valkey volumes.
