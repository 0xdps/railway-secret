# Rail Rotator

Rail Rotator is a lightweight PHP dashboard to manage and rotate Railway environment variables for global and service scopes.

## Features

- View global and per-service Railway variables
- Manual rotation and scheduled rotation (via `cron.php`)
- Configurable generation: length + encoding (`hex`, `base64`, `alphanumeric`)
- Encrypted history storage with AES-256-GCM
- Admin authentication with secure cookies
- CSRF protection for state-changing actions
- Login brute-force protection with separate SQLite rate-limit DB

## Tech Stack

- PHP 8.2
- SQLite
- Nginx + PHP-FPM (Docker image)
- Railway GraphQL API

## Required Environment Variables

Create `.env` from `.env.example` and set:

- `RAILWAY_TOKEN`
- `PROJECT_ID` or `RAILWAY_PROJECT_ID`
- `ENVIRONMENT_ID` or `RAILWAY_ENVIRONMENT_ID`
- `ADMIN_KEY`
- `SESSION_SECRET`
- `MASTER_KEY`

Recommended:

- `RAILWAY_ENVIRONMENT_NAME`
  - if set: strict production cookie mode (`Secure`, `SameSite=Strict`)
  - if not set: dev-friendly cookie mode (`SameSite=Lax`)
- `TRUSTED_PROXY_IPS`
  - comma-separated proxy IPs for trusted `X-Forwarded-For` handling

## Data Files

Under `storage/db/`:

- `secrets.sqlite` (managed secret config + encrypted history)
- `login_rate_limit.sqlite` (login protection state)

## Local Development

### Option A: PHP built-in server

```bash
php -S localhost:8080 -t public
```

Open: `http://localhost:8080`

### Option B: Docker

```bash
docker build -t rail-rotator .
docker run --rm -p 8080:80 --env-file .env rail-rotator
```

Open: `http://localhost:8080`

## Railway Deployment

1. Push this repository to GitHub.
2. Create a new Railway service from the repo.
3. Configure all required environment variables.
4. Add a Railway volume and mount it to `/var/www/html/storage`.
5. (Optional but recommended) Create a separate Cron service from same repo:
   - Command: `php /var/www/html/cron.php`
   - Mount the same volume to `/var/www/html/storage`

## Railway Template Publish Guide

Use this checklist to publish as a Railway template.

1. Ensure repository visibility is public.
2. Confirm `.env.example` contains all required keys (without secrets).
3. Confirm `README.md` has:
   - one-line purpose
   - required envs
   - deployment steps
4. In Railway dashboard:
   - Create a project from this repo once
   - Validate deploy works end-to-end
5. From Railway template flow:
   - Choose "Create Template"
   - Select this source repo
   - Add template metadata:
     - Name: `Rail Rotator`
     - Description: secret rotation dashboard for Railway variables
     - Category: DevOps / Security
   - Map required env vars with descriptions/defaults where safe
   - Mark volume requirement for `/var/www/html/storage`
6. Publish template and test with a fresh Railway account/workspace.
7. Version updates:
   - Keep template and repo in sync
   - Re-validate env variable list after changes

## Security Notes

- Never commit `.env`.
- Rotate secrets immediately if exposed.
- Use long random values for `ADMIN_KEY`, `SESSION_SECRET`, and `MASTER_KEY`.
- Set `TRUSTED_PROXY_IPS` in production.

## License

MIT (see `LICENSE`).
