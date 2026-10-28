# CloudPBX

A multi-tenant Cloud PBX management platform: Laravel 12 with Vue 3, Inertia and Tailwind as the
control plane, and Asterisk as the telephony engine.

- Architecture, tenancy model and phase plan: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Production deployment: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
- Original product brief: `I recommend building this as a Lara.txt`

## Local setup

Requirements: PHP 8.2+, Composer, Node 20+. Docker is optional and only needed for Postgres and Redis.

```bash
composer install
npm install
cp .env.example .env        # skip if .env exists
php artisan key:generate
php artisan migrate:fresh --seed
composer dev                # app server, queue worker, logs and Vite together
```

Open http://localhost:8000.

| Account | Email | Password |
|---|---|---|
| Platform super admin | admin@example.com | password |
| Demo organization owner | owner@example.com | password |

The seeder creates a **simulator** PBX server, so the dashboard works without Asterisk. The
simulator refuses to run when `APP_ENV=production`.

### Using PostgreSQL and Redis

```bash
docker compose up -d
```

Then set `DB_CONNECTION=pgsql` and the `DB_*` / `REDIS_*` values in `.env`. PHP needs the
`pdo_pgsql` extension.

### Connecting a real Asterisk server

Enable ARI in `http.conf` and `ari.conf` on a private interface. Then go to **Platform → PBX
Servers → Add server**, enter the ARI URL (for example `http://10.0.0.5:8088/ari`) and the
credentials, and click **Check health now**. The scheduler (`php artisan schedule:work` in dev)
re-checks every minute.

## Tests

```bash
php artisan test
```
