# Install Or Update PullLens

PullLens ships with an installer script for both first-time installation and future updates.

## Requirements

- A Linux server with shell access
- Git
- Curl

If Docker is not installed, the installer will install it automatically using Docker's official install script.

## First Install

Clone the repository, enter the project directory, then run:

```sh
./install.sh
```

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

There are three ways to serve PullLens over HTTPS, and `./install.sh` asks which at the
end. It defaults to skipping, so nothing happens unless you choose it.

| | Use it when | Command |
| --- | --- | --- |
| **In the stack** | This server is PullLens's alone. Simplest. | `./install_tls.sh` |
| **Host proxy** | The server also serves other sites. | `./install_ssl.sh` |
| **Neither** | Something already terminates TLS in front of it. | set `APP_URL` |

All three need the same thing first: a domain whose DNS A record points at this server.

### Already behind Cloudflare, a load balancer or another proxy

Run **neither** script. A certificate request would fail, because Let's Encrypt's
challenge has to reach *this* server over port 80 and a proxy in front of it answers
instead. There is nothing to install: set `APP_URL` to the `https://` address your
visitors use and rerun `./install.sh`. `X-Forwarded-Proto` is already trusted, so
PullLens will generate `https://` links and keep the session cookie `Secure`.

This is also the right answer for a Cloudflare tunnel, a Kubernetes ingress, or an
Nginx you already run yourself.

### HTTPS inside the stack

```sh
./install_tls.sh
```

Or without the questions:

```sh
./install_tls.sh pulllens.example.com you@example.com
```

Nginx in the stack takes ports 80 and 443 directly, a `certbot` container issues the
certificate over the ACME webroot challenge, and renewal runs twice a day for as long
as the stack is up. Nothing is installed on the host.

What it checks before contacting Let's Encrypt, so a failure costs nothing:

- the domain is a real hostname, not a bare IP address or `localhost`, which Let's Encrypt will not issue for
- the domain resolves, and resolves to *this* server - if it resolves elsewhere, that usually means Cloudflare's proxy is on and you want the section above instead
- `APP_PORT` is still 80 - if it is not, `./install_ssl.sh` has already put a host proxy in front and that is the arrangement to keep

If issuance fails, nothing is changed and PullLens keeps serving over plain HTTP.

Port 80 stays open afterwards on purpose. It redirects to HTTPS, and it carries the
renewal challenge every 60 days - closing it breaks renewal on a site that otherwise
looks perfectly healthy.

```sh
docker compose logs certbot                     # renewal activity
docker compose exec certbot certbot certificates # what exists and when it expires
```

Re-running the script is safe: a certificate that is still valid is kept.

### HTTPS with Nginx on the host

```sh
./install_ssl.sh
```

Or without the questions:

```sh
./install_ssl.sh pulllens.example.com you@example.com
```

Choose this when the server has other sites on it, because it leaves the host in
charge of port 80 and proxies only your domain to PullLens.

### What it needs

- A **Linux host**. On macOS the script stops with a message and changes nothing, because there is no system Nginx for certbot to configure.
- **Root or sudo**, to install packages and write the Nginx configuration.
- A **domain name whose DNS A record already points at this server**.
- **Ports 80 and 443 free and reachable from the internet.** Let's Encrypt validates by fetching a file over port 80.

### What it does

1. Moves the PullLens container off port 80 onto an internal port, usually `8080`, and updates `APP_PORT` in `.env`.
2. Installs Nginx and certbot with the system package manager - apt, dnf, yum, zypper or pacman.
3. Writes `/etc/nginx/sites-available/pulllens.conf` (or `/etc/nginx/conf.d/pulllens.conf`) proxying your domain to the container, websockets included, so the realtime dashboard keeps working over `wss://`.
4. Requests the certificate with `certbot --nginx` and enables the HTTP to HTTPS redirect.
5. Enables the certbot renewal timer, so the certificate renews itself unattended.
6. Sets `APP_URL`, `ASSET_URL`, `SESSION_SECURE_COOKIE` and the public websocket settings, then restarts the containers so they pick up the new address. There is no rebuild: the compiled frontend contains no URL, and `asset()` resolves `APP_URL` in PHP on every request.

The script is safe to re-run. A certificate that is still valid is kept and the proxy configuration is rewritten from the same template.

### Afterwards

```sh
sudo certbot certificates      # what exists and when it expires
sudo certbot renew --dry-run   # proves renewal works before it matters
sudo nginx -t                  # checks the proxy configuration
```

The container still publishes its internal port on every interface, so `http://your-server:8080` reaches PullLens without TLS. Close that port at the firewall or in your cloud provider's security group.

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
