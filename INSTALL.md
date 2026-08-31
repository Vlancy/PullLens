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
