# PullLens

Self-hosted AI code review for every pull request. PullLens watches your repositories,
reviews incoming pull requests with the model you choose, and posts its findings back to
the provider — on your own infrastructure, with your own API keys.

[Source and documentation on GitHub](https://github.com/Vlancy/PullLens) ·
[Installation guide](https://vlancy.github.io/PullLens/guide/installation.html)

## Supported tags

| Tag | Meaning |
| --- | --- |
| `latest` | The newest stable release. Moves on every release. |
| `1.0.0` | One exact release. Never moves — what you want when you pin. |
| `1.0` | Newest patch on the 1.0 line. |
| `1` | Newest release on the 1.x line. |

Every tag is a multi-architecture manifest covering `linux/amd64` and `linux/arm64`, so
the same reference works on an ordinary cloud server and on ARM hardware.

## This image is not the whole application

PullLens runs as a stack: the application, an Nginx front end, PostgreSQL, Valkey, a
queue worker, a scheduler and a websocket server. This image is the application. The
Compose file that wires the rest together, the Nginx configuration and the installer all
live in the Git repository, so the supported way to run PullLens is to clone it and let
the installer pull this image:

```bash
curl -fsSL https://raw.githubusercontent.com/Vlancy/PullLens/main/bootstrap.sh | sh
```

That clones the repository and runs its installer, which sets up Docker if it is
missing, writes a `.env`, pulls this image, runs the migrations and prints your login
URL. Nothing is compiled on your server.

The long way is identical:

```bash
git clone https://github.com/Vlancy/PullLens.git
cd PullLens
./install.sh
```

## Running a specific release

The image tag is derived from the `VERSION` file in the checkout, because the Compose
file and the Nginx configuration ship there while the application ships here — the two
have to describe the same release. So pin by checking out a tag, not by editing `.env`:

```bash
git checkout v1.0.0
./install.sh
```

The same command is how you roll back: it moves the image, the Compose file and the
Nginx configuration together.

## What the image contains

PHP 8.4 on `php:8.4-fpm-bookworm`, the Laravel application, the compiled frontend, and
the user guide that the app serves at `/docs`. It listens on port 9000 as PHP-FPM; it
does not serve HTTP itself.

To check what a running container actually is:

```bash
docker compose exec app php artisan about
```

## Licence

MIT with the Commons Clause. You can self-host it, modify it and use it commercially
inside your own organisation; you cannot sell PullLens itself as a product or service.
