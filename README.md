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

## Phase 3 — Passport, Photo Stamp, trips, languages

The backend and admin for the app features. **The Flutter app itself has not
been started**: `temple-app` holds a README and a roadmap and no code. These
are the endpoints it will be built against.

**Passport.** A visit is a row; a **stamp** is the existence of a *verified*
one, derived rather than stored, so revoking a verification revokes the stamp
instead of leaving an orphan in a collection. A GPS or QR check-in verifies
itself when it is close enough; a manual one never does, however good its
coordinates look. Recording a pilgrimage from before the app existed is the
point of a passport — but a collection that cannot tell evidence from
assertion is a list anyone can type in, and then "6 of 12 Jyotirlingas" means
nothing. Staff can verify a visit the device could not, and revoke one that
turns out to be false.

**Photo Stamp.** The devotee's photo and the generated memory card are kept as
separate files. The card can always be re-rendered; the photograph cannot.
Everything waits for a moderator, and **approval is not publication** — the
devotee's own choice has to agree too, and one scope enforces both so a caller
that checks only the status cannot leak a private photo.

**Memories** are a devotee's writing about a visit, private by default, and
private through an edit that omits the field. Staff see that a memory exists
and when; they do not see what a private one says.

**Yatra planner.** A plain itinerary, not a routing engine: ordering temples by
road distance needs a maps provider and a connection a devotee planning on a
train does not have. Recording a visit closes the planned stop for that temple,
which is what links the planner to the Passport.

**Favourites** (saved temples) came with Phase 2 and now feed the analytics:
saves are intent, planned stops are commitment, visits are what happened.

**Languages** are rows, not columns. `name_te`, `name_hi`, `name_ta`… means a
migration per language across every table, and India has more languages than
that survives. **Twelve are configured, three ship** (`config/locales.php`). A
missing translation falls back to English rather than to nothing, and an
unreviewed one is not served at all — a deity's name rendered wrongly in a
devotee's own language is worse than the English they can recognise. Translate
a temple under **Languages** on its edit page; the dress code and entry rules
matter most, because not understanding those means being turned away at the
gate.

## Devotee analytics

**Devotees → Analytics** in the admin panel.

Sign-ins are recorded as **events**, not just stamped on a column.
`last_login_at` can only ever hold the latest value: it cannot say how many
people signed in this week, and that cannot be reconstructed afterwards.
Failed attempts are kept too — a burst against one account is the first sign
of a credential-stuffing run and is invisible if only successes are stored.

What the screen answers:

- **Accounts**, and how many joined in the last 30 days.
- **Active today / this week / this month** — *distinct people who signed in*,
  never sign-in counts. A devotee who opens the app eight times in a day is
  one active user, and the number that says eight is the one that gets quoted.
- **Stickiness**: daily actives as a share of monthly. The hardest number to
  flatter, because acquiring more users cannot raise it.
- **Never signed in** — registered and never came back, usually a broken
  confirmation step rather than people changing their minds.
- **Sign-ups against active devotees** over 7/30/90 days. Both are people per
  day, so they share one axis honestly; raw sign-ins would flatten the sign-up
  line against zero, and giving it a second axis would let the two be scaled
  into any crossing you like.
- **Passport and trips**: visits recorded, stamps awarded, **trips being
  planned** and how many start within 90 days, photos waiting for moderation,
  memories written.
- **Languages devotees chose** — what to translate next, from what people
  actually set rather than from where their temples are.
- **Temples devotees engage with** — saved, planned and visited side by side.
  A temple with many saves and no visits is one people want to reach and
  cannot, which is a different problem from one nobody saves.

Every devotee account has a **profile page** (Devotees → Devotee Accounts →
view): identity, language, what the account has done, its recent sign-ins
including failures, and its Passport, trips, photos and memories as tabs. It
is **read-only** — a devotee's account is theirs, and a staff form that can
rewrite their name is one that eventually will. The only writes are suspend
and restore, which keep all their records.

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
