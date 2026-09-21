# Temple Website — Backend API + Admin Panel

Laravel 11 backend, Filament v3 admin panel and public web for the temple
pilgrimage platform (working name: **Temple Passport** — not finalised).

The companion Flutter app lives in [`temple-app`](https://github.com/PranayReddy10/temple-app).

See **[ROADMAP.md](ROADMAP.md)** for the feature slices and delivery order.

## Stack

| Layer | Choice | Why |
| --- | --- | --- |
| Framework | Laravel 11 (PHP 8.2+) | Runs on shared Hostinger as-is |
| Admin | Filament v3 | CRUD, filters, media, roles built in |
| Database | MySQL 8 | Standard on every Hostinger plan |
| API | REST, versioned at `/api/v1` | Keeps the Flutter app decoupled |

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Admin panel: <http://localhost:8000/admin>

The seeder creates a super-admin. Credentials are printed to the console on
first seed — change the password immediately after first login.

## Deployment

Shared Hostinger setup is documented in
**[docs/DEPLOY_HOSTINGER.md](docs/DEPLOY_HOSTINGER.md)**, including the
`public/` document-root mapping that shared hosting requires.

## Branding

The product name is not final. It is read from `config/brand.php` via `.env`:

```dotenv
BRAND_NAME="Temple Passport"
BRAND_TAGLINE="Your digital pilgrimage companion"
```

Nothing hard-codes the name, so renaming later is a config change.
