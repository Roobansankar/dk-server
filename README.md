# DK StyleHub — Backend API

Laravel 13 REST API for the DK StyleHub salon website. The single React app
in `frontend/` consumes this API for both the public site and the `/admin`
area — there is no separate admin frontend/dev server.

## Requirements

- PHP 8.3+
- Composer
- MySQL 8 (SQLite is used for the test suite)

## Setup

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate

# Create the database + user, then set DB_* in .env:
#   CREATE DATABASE dk_stylehub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE USER 'dk_stylehub'@'localhost' IDENTIFIED BY 'strong-password';
#   GRANT ALL PRIVILEGES ON dk_stylehub.* TO 'dk_stylehub'@'localhost';
# and switch DB_CONNECTION to `mysql`.

php artisan migrate --seed        # RolePermissionSeeder + SiteSettingSeeder always run;
                                  # DevSeeder (sample data) runs only outside production
php artisan storage:link
php artisan serve
```

### First superadmin

Set `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `.env` and run
`php artisan db:seed --class=AdminUserSeeder` (safe in production).
In local dev the `DevSeeder` also creates
`superadmin@dkstylehub.test` / `admin@dkstylehub.test` (password `password`).

## Auth

Token-based via Laravel Sanctum. `POST /api/auth/login` returns a bearer token;
send it as `Authorization: Bearer <token>`. Admin routes are additionally gated
by granular permissions (spatie/laravel-permission). A `superadmin` bypasses all
checks.

## Tests

```bash
php artisan test          # 48 feature tests, in-memory SQLite
./vendor/bin/pint         # code style
```

## Media

Uploads go through `App\Support\ImageUploader` on the `MEDIA_DISK` disk
(`public` locally). Set `MEDIA_DISK=s3` + `AWS_*` to move to cloud storage — no
code changes needed. API resources always return absolute `*_url` fields.
