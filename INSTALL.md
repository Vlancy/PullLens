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
- Set `UID` and `GID` from the server user
- Build and start the Docker Compose stack
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

## HTTPS

PullLens ships a second script for certificates. It is optional and never runs on its own:

```sh
./install_ssl.sh
```

Or without the questions:

```sh
./install_ssl.sh pulllens.example.com you@example.com
```

At the end of `./install.sh` the same script is offered as a question - **Set up HTTPS now?** - which defaults to **no**. Press Enter to skip it; nothing about the installation changes.

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
6. Sets `APP_URL`, `ASSET_URL`, `SESSION_SECURE_COOKIE` and the public websocket settings, then rebuilds the stack so the frontend is built against the new address.

The script is safe to re-run. A certificate that is still valid is kept and the proxy configuration is rewritten from the same template.

### Afterwards

```sh
sudo certbot certificates      # what exists and when it expires
sudo certbot renew --dry-run   # proves renewal works before it matters
sudo nginx -t                  # checks the proxy configuration
```

The container still publishes its internal port on every interface, so `http://your-server:8080` reaches PullLens without TLS. Close that port at the firewall or in your cloud provider's security group.

### Already have a proxy

If a load balancer, a Cloudflare tunnel or an existing Nginx already terminates TLS, skip the script. Point that proxy at the container's `APP_PORT`, set `APP_URL` to the `https://` address, and rerun `./install.sh`. `X-Forwarded-Proto` is already trusted.

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
