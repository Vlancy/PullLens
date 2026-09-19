# Install Or Update PullLens

PullLens ships with an installer script for both first-time installation and future updates.

## Requirements

- A Linux server with shell access
- Git
- Curl

If Docker is not installed, the installer will install it automatically using Docker's official install script.

## First Install

One command on a fresh server:

```sh
curl -fsSL https://raw.githubusercontent.com/Vlancy/PullLens/main/bootstrap.sh | sh
```

The bootstrap installs Git if it is missing, clones PullLens into `./PullLens`, and
runs the installer. Arguments pass through:

```sh
curl -fsSL https://raw.githubusercontent.com/Vlancy/PullLens/main/bootstrap.sh | sh -s -- --from-source
```

Three environment variables change where it puts things: `PULLLENS_DIR` (default
`PullLens`), `PULLLENS_BRANCH` (default `main`) and `PULLLENS_REPO`.

If you would rather read the script first - a reasonable instinct for anything piped
into a shell - download it, read it, then run it:

```sh
curl -fsSL -o bootstrap.sh https://raw.githubusercontent.com/Vlancy/PullLens/main/bootstrap.sh
less bootstrap.sh
sh bootstrap.sh
```

Or skip the bootstrap: clone the repository, enter the project directory, and run
`./install.sh` yourself. The bootstrap does nothing else.

The installer will:

- Install Docker if it is missing
- Create `.env` from `.env.example` if needed
- Generate `APP_KEY` if needed
- Ask for your website base URL if `APP_URL` is still `http://localhost`
- Replace `change-me` secrets in `.env`
- Pin the image to the release this checkout describes
- Pull the published `vlancy/pulllens` image and start the Docker Compose stack
- Run migrations and seeders
- Create the Laravel storage link
- Clear and rebuild Laravel optimized caches
- Verify containers, Laravel, frontend assets, and the HTTP health endpoint
- Offer to set up HTTPS with a free Let's Encrypt certificate, which you can skip

## Updating

To update an existing installation, run the same command:

```sh
./install.sh
```

You can also run:

```sh
./update.sh
```

`update.sh` calls `install.sh`, so both commands are safe for updates. Existing `.env` secrets are preserved.

An update pulls the new image before it stops anything, so if Docker Hub is unreachable
the running stack is left alone and nothing is half-changed.

## Versions And Rolling Back

The `VERSION` file in this repository is the source of truth. `install.sh` reads it and
pins the image to match, because `compose.yml` and the Nginx configuration ship in this
clone while the application ships in the image - the two have to describe the same
release, and letting you set the tag by hand in `.env` is how they would come apart.

To run or return to a particular release, check out its tag and reinstall:

```sh
git checkout v1.0.0
./install.sh
```

That moves the image, the compose file and the Nginx configuration together, which is
what makes it a rollback rather than a partial one.

## Building From Source

Contributors working on PullLens itself can build the image from the checkout instead
of pulling it:

```sh
./install.sh --from-source
```

The locally built image is tagged `pulllens:source`, so it can never shadow or be
overwritten by a published tag. Everyone else should pull - it is faster, and it is the
image that was actually tested.

Note that the git clone is required either way. `compose.yml`, the Nginx configuration,
`install.sh` and `VERSION` all live here; the published image contains the application,
not the deployment.

## HTTPS

PullLens can get its own certificate, and `./install.sh` asks at the end of an install
whether it should. It defaults to no, so nothing happens unless you choose it. To add
one to an instance that is already running:

```sh
./install.sh --https
```

Or without the questions:

```sh
./install.sh --https pulllens.example.com you@example.com
```

`--https` skips straight to the certificate step; it does not pull, migrate or reseed.

### When not to use it

If anything already terminates HTTPS in front of PullLens - Cloudflare's proxy, a load
balancer, a Kubernetes ingress, a company gateway, or another web server on this host -
**do not run it**. A certificate request would fail: Let's Encrypt proves you own the
domain by fetching a file from *this* server over port 80, and whatever sits in front
answers instead.

There is nothing to install in that case. Set `APP_URL` to the `https://` address your
visitors use and rerun `./install.sh`. `X-Forwarded-Proto` is already trusted, so
PullLens will generate `https://` links and keep the session cookie `Secure`.

### What it does

Nginx in the stack takes ports 80 and 443 directly, a `certbot` container issues the
certificate over the ACME webroot challenge, and renewal runs twice a day for as long
as the stack is up. Nothing is installed on the host and no host web server is
involved. `APP_URL`, `ASSET_URL` and `SESSION_SECURE_COOKIE` are moved to the
`https://` address for you.

### What it checks first

So that a failure costs nothing:

- the domain is a real hostname, not a bare IP address or `localhost`, which Let's Encrypt will not issue for
- the domain resolves, and resolves to *this* server - if it resolves elsewhere, that usually means a proxy such as Cloudflare is in front, and the section above applies
- `APP_PORT` is still 80 - anything else means something on this host already owns the port PullLens needs

If issuance fails, nothing is changed and PullLens keeps serving over plain HTTP.

### Afterwards

Port 80 stays open on purpose. It redirects to HTTPS, and it carries the renewal
challenge every 60 days - closing it breaks renewal on a site that otherwise looks
perfectly healthy.

```sh
docker compose logs certbot                       # renewal activity
docker compose exec certbot certbot certificates  # what exists and when it expires
```

Re-running is safe: a certificate that is still valid is kept.

### Running your own proxy instead

Nothing stops you putting Nginx, Caddy or HAProxy on the host in front of the stack.
Set `APP_PORT` to a free port so your proxy can have 80 and 443, point it at that port,
forward `X-Forwarded-Proto`, set `APP_URL` to the `https://` address, and rerun
`./install.sh`. PullLens does not manage that proxy or its certificate.

## Login

When installation finishes successfully, the script prints:

- App URL
- Login URL
- Initial user email and password, only on first install

If users already exist, the script will not print the initial password and will tell you to use your existing admin account.

The initial account is created only when the users table is empty. This prevents the default admin account from being recreated during future updates.

## Configuration

Edit `.env` to set your public application URL and service credentials:
If `APP_URL` is still `http://localhost`, the installer will ask for your website base URL. Press Enter to skip, or enter a full URL such as `https://example.com`.

```env
APP_URL=https://your-domain.example
ASSET_URL="${APP_URL}"
```

### Private instance

By default `/` serves the public landing page and `/docs` serves the user guide. On an internal deployment, set:

```env
HOMEPAGE_LOGIN=true
```

`/` then serves the login screen and `/docs` stops responding, so nothing about the instance is readable before sign-in. The guide is still available from the repository at `docs/guide/index.html`.

To keep the landing page but take the login button off it:

```env
HIDE_LOGIN=true
```

`/login` still works for anyone who has the address; the page simply stops pointing at it.

Then rerun:

```sh
./install.sh
```

## Troubleshooting

Show container status:

```sh
docker compose ps
```

Show application logs:

```sh
docker compose logs app
```

Show Nginx logs:

```sh
docker compose logs nginx
```
