# Luvhere CRM — backend (Laravel)

Back-office API for the Luvhere app. It is a **separate Laravel app that connects to the same
database as `kive-backend`**, so staff can moderate real users. The React UI lives in
`kive-crm-frontend`.

```
mobile app ──> kive-backend ──┐
                              ├── same MySQL DB (+ same S3 media disk, same queue)
CRM UI ──> kive-crm-backend ──┘
```

## Ownership of the schema
| Owner | What |
|---|---|
| `kive-backend` | users, photos, reports, … **and** the moderation columns the CRM needs (`users.status`, `photos.moderation_status`, `reports.resolved_*`) plus `app_settings`. Also enforces bans and reads the settings. |
| `kive-crm-backend` (this repo) | `admins`, `admin_audit_logs`, `broadcasts` |

So: **run `php artisan migrate` in `kive-backend` first**, then here. Never run `migrate:fresh` here
— it would wipe the app's tables.

## Setup
```bash
composer install
cp .env.example .env && php artisan key:generate
# point DB_* at the SAME database as kive-backend; copy MEDIA_DISK / AWS_* too
php artisan migrate
php artisan admin:create you@luvhere.app --name="Your Name" --role=super_admin
php artisan serve --port=8001
```
Broadcast pushes are queued on the shared `jobs` table; the queue worker running for
`kive-backend` (`php artisan queue:listen`) delivers them.

## API
All routes under `/api`, Sanctum bearer tokens (admins only). `POST /api/auth/login` to sign in.
See `routes/api.php` for the full list and role gates
(super_admin · admin · moderator · support).
