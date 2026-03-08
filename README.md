# Railway Secrets

A self-hosted PHP dashboard to manage and rotate Railway environment variables. Works across global scope and per-service scope.

## What it does

- Lists global and per-service Railway environment variables
- Rotates secrets on demand from the dashboard or on a schedule via `cron.php`
- Generates new values with configurable length and encoding (`hex`, `base64`, `alphanumeric`)
- Stores the previous value after each rotation, encrypted with AES-256-GCM
- Groups services into named sets for organised workflows
- Protects the dashboard with session-based auth and CSRF tokens
- Throttles login attempts with a separate SQLite rate-limit database

## Stack

- PHP 8.2
- SQLite
- Nginx + PHP-FPM (Docker)
- Railway GraphQL API

## Environment variables

| Variable | Required | Description |
|---|---|---|
| `RAILWAY_TOKEN` | yes | Railway API token with project write access |
| `RAILWAY_PROJECT_ID` | yes | Project ID (auto-injected by Railway, or set manually) |
| `RAILWAY_ENVIRONMENT_ID` | yes | Environment ID (auto-injected by Railway, or set manually) |
| `ADMIN_KEY` | yes | Password for the dashboard login |
| `SESSION_SECRET` | yes | Signs and encrypts the session cookie |
| `MASTER_KEY` | yes | Encrypts the secret history database at rest |
| `TRUSTED_PROXY_IPS` | no | Comma-separated proxy IPs to trust for `X-Forwarded-For` |

> **Note:** When `RAILWAY_ENVIRONMENT_NAME` is set the session cookie uses `Secure` + `SameSite=Strict`. Without it the cookie uses `SameSite=Lax` (suitable for local development).

## Storage

The app writes two SQLite files under `storage/db/`:

- `secrets.sqlite` — rotation config and encrypted history
- `railway_cache.sqlite` — cached service/variable metadata (encrypted, no secret values)
- `login_rate_limit.sqlite` — login throttle state

Mount a Railway Volume to `/var/www/html/storage` so data persists across deploys. If you run a separate cron service, mount the **same volume** so both services share the same database.

## Local development

**PHP built-in server**

```bash
php -S localhost:8080 -t public
```

**Docker**

```bash
docker build -t railway-secrets .
docker run --rm -p 8080:80 --env-file .env railway-secrets
```

Open `http://localhost:8080` in both cases.

## Deploying to Railway

1. Push this repository to GitHub.
2. Create a Railway service from the repo.
3. Set all required environment variables in the service settings.
4. Create a Railway Volume and mount it to `/var/www/html/storage`.
5. Create a second service from the same repo for scheduled rotation:
   - Start command: `php /var/www/html/cron.php`
   - Set a cron schedule (e.g. `0 3 * * *` for daily at 03:00 UTC)
   - Mount the **same volume** to `/var/www/html/storage`

## Health check

```
GET /health
GET /healthz
```

```json
{"ok":true,"status":"healthy","timestamp":"..."}
```

## Security

- Use strong random values (32+ characters) for `ADMIN_KEY`, `SESSION_SECRET`, and `MASTER_KEY`.
- Set `TRUSTED_PROXY_IPS` in production environments behind a proxy.
- Never commit `.env` or expose any of the keys above.
- Rotate `SESSION_SECRET` if you suspect it has been leaked.
- The `RAILWAY_TOKEN` should have the minimum scope needed — project-level write, not account-level.

## License

MIT — see `LICENSE`.

