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
| `SESSION_COOKIE_SECURE` | no | `true` or `false`. When unset, the session cookie is `Secure` only if `RAILWAY_ENVIRONMENT_NAME` is set |
| `TRUSTED_PROXY_IPS` | no | Comma-separated proxy IPs allowed to supply `X-Forwarded-For` |
| `MESAHUB_ADMIN_TOKEN` | no | Admin token for embedded mesahub. Persisted in the data volume when unset |
| `ROTATION_WEBHOOK_URL` | no | POST target for rotation failure and partial-apply events |
| `DRY_RUN` | no | `true` makes cron report due secrets without writing them |

> **Note:** The session cookie is always `HttpOnly` and `SameSite=Strict`. It is also `Secure` when `SESSION_COOKIE_SECURE=true`, or when that variable is unset and `RAILWAY_ENVIRONMENT_NAME` is present.

## Storage

Rotation config, encrypted history, the login throttle, and session records live in [mesahub](https://github.com/0xdps/mesahub-core), a SQLite database reached over HTTP.

`MESAHUB_URL=mh://local/railway_secrets` starts the bundled mesahub server inside this container and stores the database under `/data`. Mount a Railway Volume at `/data`.

`MESAHUB_URL=mh://api_key@your-host/railway_secrets` uses an external mesahub instance instead.

The embedded admin token is reused from `/data/mesahub-admin.token` across restarts. Set `MESAHUB_ADMIN_TOKEN` to choose it yourself.

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

Deploy instantly from the Railway template marketplace:

**https://railway.com/deploy/railway-secrets**

Available in the **Automation** category — search for **Railway Secrets**.

Or deploy manually from source:

1. Push this repository to GitHub.
2. Create a Railway service from the repo.
3. Set all required environment variables in the service settings.
4. Create a Railway Volume and mount it at `/data`.

Scheduled rotation runs automatically inside the container — `crond` starts with the service and executes `cron.php` every minute. The script handles its own per-secret interval logic, so no separate cron service is needed.

## Health check

```
GET /health
GET /healthz
GET /ready
```

`/health` and `/healthz` only mean the process is up. `/ready` is the deploy check: required environment variables are set and mesahub accepts a query. It does not call the Railway API on every probe.

```json
{"ok":true,"status":"healthy","timestamp":"..."}
```

## Security

- Use strong random values (32+ characters) for `ADMIN_KEY`, `SESSION_SECRET`, and `MASTER_KEY`.
- Set `TRUSTED_PROXY_IPS` to the reverse proxies you actually run in front of nginx. Client `X-Forwarded-For` is ignored unless the TCP peer is on that list. With the list empty, login attempts share the proxy's address.
- Set `SESSION_COOKIE_SECURE=true` on any HTTPS deploy. On Railway this defaults on because `RAILWAY_ENVIRONMENT_NAME` is set. Local HTTP should set it to `false`.
- Revealing, copying, or rolling back a secret asks for the admin key again. That confirmation lasts 5 minutes.
- Logout revokes the server-side session. Existing cookies from before this change are rejected; sign in again.
- Never commit `.env` or expose any of the keys above.
- The `RAILWAY_TOKEN` should have the minimum scope needed — project-level write, not account-level.
- Optional `ROTATION_WEBHOOK_URL` receives failure and partial-rotation events. The body does not include secret values.

## License

MIT — see `LICENSE`.

