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

Locked out, or working from a database someone else set up?

```bash
php artisan admin:create
```

It lists the accounts that exist, then creates one or resets an existing
password to something you choose.

```bash
php artisan test
```

## Planned logins

Three audiences, three entry points. Only the first exists today.

| Audience | Entry point | Auth | Stored in | Status |
| --- | --- | --- | --- | --- |
| Staff — super admin, editors | `/admin` | Session | `users` | ✅ Built |
| Temple authority — trust, temple office | `/temple` | Session | `users`, scoped by `temple_user` | ✅ Built |
| Devotees — app and web | Flutter app | Sanctum token | `devotees` (separate table) | ✅ Built |

Devotees get their own table on purpose: they are expected in the millions
against a few hundred staff, they will sign in by OTP or a social provider
rather than a password, and keeping them apart makes "a devotee account
acquires a staff role" impossible rather than merely unlikely.

See [ROADMAP.md](ROADMAP.md) for the full reasoning and slice order.

## What Phase 1 delivers so far

Slices 1 to 4 are complete. Phase 2 adds the temple and devotee logins, admin
settings, temple events and daily devotional content.

## Slice 1 — admin auth and temple CRUD

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
- **Light and dark themes**, both carrying the temple palette, with a
  Light / Dark / System switcher in the user menu.
- 22 starter temples, seeded honestly as *community* level with no source — they
  are there to be verified, not to pad a count.

## Slices 2–4 — media, pujas and the public API

**Photos** are stored in DigitalOcean Spaces and served from its CDN. Uploads
generate a 1200px medium and a 400px thumbnail (WebP where available). Each row
records its own disk, so photos survive a later storage migration; deleting a
photo deletes its files, so object storage does not fill with orphans.

**Timings** cover general hours, darshan and aarti, per-day or every day, plus
**closures** with date ranges — a full-day closure and merely changed hours are
different things to someone who has travelled.

**Pujas** carry time, duration, eligibility, published fee and booking route.
Two invariants are enforced in the model layer, not just the form:

- An unknown price reports as **"No published price"**, never as free.
- A booking link is only labelled **official** when an editor has explicitly
  confirmed it. A URL that looks official is not.

**Facilities** separate accessibility from general amenities, and each is
flagged verified or not — an unconfirmed claim of wheelchair access is worse
than no claim.

**The public API** is documented in [docs/API.md](docs/API.md). It serves only
published temples, supports search across alternate names, filters by deity,
category and state, and orders proximity results by real distance.

## Temple portal

Temple teams sign in at `/temple` with the **Temple Admin** role. They see only
temples their claim has been approved for, and manage what only they really
know: contact details, timings, photos, sevas, closures and visitor rules.
Name, deity and classification stay with editorial staff, as do trust level and
publishing.

### Granting a temple its access

A super admin grants it from either direction, and both lead to the same form:

- **Administration → Temple Trust Access** in the side menu — every temple's
  access in one list, with the pending ones badged. This is where approvals
  are worked as a queue.
- **Temple authority access** on a temple's own edit page, when the temple is
  what you are already looking at.

The account being granted access must hold the **Temple Admin** role, because
that role is what the portal's sign-in gate checks; a seat given to an editor
would grant nothing. If nobody is listed in the **Account** dropdown, that is
because no such account exists yet — use the **+** button beside the field to
create one without leaving the form. (`php artisan admin:create
office@example.com --role=temple_admin` does the same from the command line.)

A claim grants nothing until a super admin approves it. Creating one as staff
approves it in the same act, with your name recorded as the approver; revoking
takes effect immediately and keeps the record.

The boundary is enforced in the resource queries, not by hiding navigation:
a temple admin requesting another temple's URL gets a 404, and the role cannot
reach `/admin` at all. Fifteen tests cover it, and they were confirmed to fail
when the scoping is removed.

## The dashboard, and getting around

Every tile on the dashboard is a link into the records it counted, filtered
the same way — "Temples to review · 2" opens those two, not the whole list.
A tile the signed-in role may not open is left unlinked rather than sending
them to a 403. The day's deity panel links to the record that produced it,
and each pilgrimage circuit row opens the temples recorded for that circuit.

The side menu carries the two queues that used to be reachable only from
inside a temple record:

- **Temples → Events & Programs** — every temple's festivals and programs,
  with what a temple has submitted for review badged, so it can be approved
  or sent back without first guessing which temple it came from.
- **Administration → Temple Trust Access** — see above.

Both still exist inside a temple's own edit page, and share the same form and
actions, so the same decision behaves the same way from either direction.

There is no account card on the dashboard. Your own name, sign-in email and
password are on **My profile**, reached from the user menu in both panels,
alongside the role you hold, the panel it signs into and — for a temple
account — the temples it covers. Sign out is in that same user menu.

## Deployment

Shared Hostinger setup, including the `public/` document-root mapping and the
security traps to avoid, is in **[docs/DEPLOY_HOSTINGER.md](docs/DEPLOY_HOSTINGER.md)**.

After pulling new code — the same four lines every time, documented in
**[docs/UPDATING.md](docs/UPDATING.md)**:

```bash
php artisan down
git pull origin <branch>
composer install --no-dev --optimize-autoloader
php artisan app:deploy --force
php artisan up
```

**There is no .sql file to import.** Tables come from migrations, and
`app:deploy` applies the pending ones, seeds reference data a release
introduced (only into empty tables, so editor changes survive), rebuilds the
caches and refuses to run on a stale autoloader. `--check` reports what it
would do without changing anything.

Getting a **403** on a fresh deploy? See
**[docs/TROUBLESHOOTING_403.md](docs/TROUBLESHOOTING_403.md)** — it is nearly
always the document root, not Laravel.

### Importing without SSH

Tables come from migrations (`php artisan migrate`), so there is no `.sql` file
in the repository. If your hosting plan has no SSH and phpMyAdmin is the only
way in, generate an importable dump locally:

```bash
php artisan db:mysql-dump --with-admin
```

It renders MySQL DDL from the project's own migration files, includes the
reference and sample data, and records the migration history so a later
`php artisan migrate` does not try to recreate the tables. Verified by importing
into MariaDB 10.11, the engine shared hosts commonly run.

## Branding

The product name is not final. It is read from `config/brand.php` via `.env`:

```dotenv
BRAND_NAME="Temple Passport"
BRAND_TAGLINE="Your digital pilgrimage companion"
```

It is resolved at runtime, so renaming is a `.env` edit plus
`php artisan config:clear` — no code change anywhere.
