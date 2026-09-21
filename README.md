# Temple Website — Backend API + Admin Panel

Laravel backend, Filament admin panel and public web for the temple pilgrimage
platform (working name: **Temple Passport** — not finalised).

The companion Flutter app lives in [`temple-app`](https://github.com/PranayReddy10/temple-app).

See **[ROADMAP.md](ROADMAP.md)** for the feature slices and delivery order.

## Stack

| Layer | Choice | Why |
| --- | --- | --- |
| Framework | **Laravel 12** (PHP 8.2+) | Runs on shared Hostinger as-is |
| Admin | **Filament v4** | CRUD, filters, roles built in |
| Database | MySQL 8 | Standard on every Hostinger plan |
| API | REST, versioned at `/api/v1` | Keeps the Flutter app decoupled |

> **Why Laravel 12 and not 11?** Laravel 11 is past security support. Two
> advisories — a CRLF injection in the default email rule and a signed-URL path
> confusion — were only fixed in the 12.x line. Laravel 12 needs PHP 8.2, which
> shared Hostinger has; Laravel 13 needs PHP 8.3, which is less reliably
> available. `composer audit` reports zero advisories on this tree.

**No build step.** The admin theme is plain CSS, not a Vite/Tailwind bundle, so
deploying needs Composer only — shared hosting has no Node toolchain.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or configure MySQL in .env
php artisan migrate --seed
php artisan serve
```

Admin panel: <http://localhost:8000/admin>

The seeder creates a super admin and **prints its password once**. Set
`ADMIN_EMAIL` / `ADMIN_PASSWORD` in `.env` first if you'd rather choose it.

```bash
php artisan test
```

## What slice 1 delivers

- Admin sign-in, with **super admin** and **editor** roles.
- **Temples**: searchable, filterable, paginated list; sectioned create/edit
  form covering identity, location, description, contact, provenance.
- Alternate and local names per temple, so "Tirupati" finds
  Sri Venkateswara Swamy Temple. Telugu, Hindi, Tamil and more.
- **Master data**: deities, pilgrimage circuits and temple types, all 28 states
  and 8 union territories, districts.
- **Draft → In Review → Published** workflow. Editors cannot publish; that is
  enforced in the model layer, not just hidden in the form.
- **Trust labelling** per §20 of the plan: unverified / community / verified /
  official, with source name, source URL and last-verified date. Claiming
  "verified" or "official" without a source is rejected.
- Dashboard leading on data quality: MVP progress, review queue, share of
  source-backed records, temples missing coordinates.
- 22 starter temples, seeded honestly as *community* level with no source — they
  are there to be verified, not to pad a count.

## Deployment

Shared Hostinger setup, including the `public/` document-root mapping and the
security traps to avoid, is in **[docs/DEPLOY_HOSTINGER.md](docs/DEPLOY_HOSTINGER.md)**.

## Branding

The product name is not final. It is read from `config/brand.php` via `.env`:

```dotenv
BRAND_NAME="Temple Passport"
BRAND_TAGLINE="Your digital pilgrimage companion"
```

It is resolved at runtime, so renaming is a `.env` edit plus
`php artisan config:clear` — no code change anywhere.
