# Updating the live site

Run this after every code change. It is the same lines every time.

```bash
cd ~/domains/madeforu.co.in/public_html/temple

php artisan down
git checkout main          # once: older instructions left servers on a feature branch
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan app:deploy --force
php artisan up
```

That is the whole routine. **You never import a .sql file again.**

Merging a pull request on GitHub changes `main` on GitHub only. The server
gets it at `git pull origin main`; skip that line and every other step runs
against the old code.

### Is the new code actually running?

The version and commit show at the bottom of the admin sidebar and under
the sign-in form (for example `v0.8.0 · 0713cf5`), and `app:deploy` prints
them. Compare the commit with the latest one on GitHub's `main`:

| What you see | Cause | Fix |
| --- | --- | --- |
| Old commit in `app:deploy` output | The server has not pulled `main` | `git checkout main && git pull origin main`, then deploy again |
| New commit from `app:deploy`, old one in the panel | PHP OPcache in the web server still holds the old files | hPanel → Advanced → PHP Configuration → save (or switch PHP version and back) |
| No version at all in the panel | The server is older than this guide | Pull `main` |
| New pages missing only for some accounts | App control, Monetisation and Settings are for **Super Admin** accounts | Sign in as a super admin |

---

## Why there is no SQL to import

Laravel does not ship a database dump. Every table and column is defined by a
**migration** — a small PHP file in `database/migrations/` — and
`php artisan migrate` applies the ones that have not run yet.

The `migrations` table records what has already been applied, so running it
twice is safe: the second run finds nothing to do.

This is why a new feature never needs a hand-written `ALTER TABLE`, and why
you cannot get the order wrong.

## What `app:deploy` does

1. **Refuses to run on a stale autoloader.** `git pull` does not regenerate
   it, so a newly added helper would be missing and pages would die with
   `undefined function`. This is why `composer install` comes first.
2. **Runs pending migrations** — the new tables and columns.
3. **Seeds reference data that is missing**, and only when the table is empty.
4. **Rebuilds the caches**, so a stale config cache does not hide your changes,
   and clears Filament's cached list of admin pages, which otherwise hides
   every page added since `php artisan optimize` was last run.
5. **Creates `public/storage`** if it is not there.

Look before you leap:

```bash
php artisan app:deploy --check
```

That reports exactly what it would do and changes nothing. Without `--force`
it lists the pending migrations and asks before running them.

## What the Phase 3 release adds

Six new tables — `login_events`, `devotee_visits`, `visit_photos`,
`devotee_memories`, `yatras` + `yatra_stops`, and `translations`. They arrive
through migrations like everything else, so the routine above is unchanged and
there is still nothing to import.

Two things worth knowing about this one:

- **Sign-in history starts from the deploy.** `login_events` records attempts
  from the moment the table exists; nothing before it can be reconstructed, so
  the analytics screen will look sparse for its first week. That is correct,
  not broken.
- **`DemoDevoteeSeeder` is not reference data.** It invents devotees, visits,
  photos and trips so the analytics screen can be looked at on a laptop.
  `app:deploy` never runs it, and it refuses to run in production. An empty
  analytics screen is honest; one full of invented pilgrims is a screen
  someone will eventually quote a number from.

  ```bash
  php artisan db:seed --class=DemoDevoteeSeeder   # local only
  ```

## What this release adds

Four migrations. Two are plain additions — deity image and mantra columns,
temple mantra columns — and two need a word:

- **`devotional_media` becomes polymorphic.** It could only belong to a
  weekday; it can now belong to a weekday, a deity or a temple. Existing rows
  are **moved**, not recreated: they are already licensed and published, and
  recreating them would reset both. The old `devotional_day_id` column is
  dropped afterwards, along with the index that named it.

  If you have code or a seeder that still passes `devotional_day_id`, it will
  now throw rather than silently create media with no owner — Eloquent drops
  an unfillable key without a word, so those rows would have existed and
  never appeared anywhere. Create through the relation instead:
  `$day->media()->create([...])`.

- **`support_tickets` and `support_ticket_messages`** are new and start empty.

There is still nothing to import; `app:deploy` applies all four.

To see the support queue with something in it on a laptop:

```bash
php artisan db:seed --class=DemoSupportSeeder   # local only
```

Like `DemoDevoteeSeeder`, it refuses to run in production.

## If a migration fails half-way

MySQL does not roll back schema changes. If `app:deploy` dies during a
migration, the table is already altered and the migration is **not** recorded
— so the obvious retry can fail on the work that did land.

**Migrations in this project are written to be safe to re-run**: each step
checks whether it is still needed. So the first thing to try is simply:

```bash
php artisan app:deploy --force
```

If it still fails, send the error rather than editing the schema by hand.
`php artisan migrate:status` shows exactly how far it got.

### The 1553 error, specifically

If you hit this during the Phase 4 deploy:

```
SQLSTATE[HY000]: General error: 1553 Cannot drop index
'devotional_media_devotional_day_id_is_published_sort_order_index':
needed in a foreign key constraint
```

that was a real bug, fixed in the commit after it. Pull again and re-run
`php artisan app:deploy --force`; it picks up from wherever it stopped and
finishes. Nothing is lost — the failure happened before any data was
removed, and the songs and their licences are untouched.

## Reference data vs your data

A release sometimes ships **rows** as well as tables — the weekday-to-deity
mapping, for instance, arrives as an empty `devotional_days` table plus a
seeder that fills it. Migrating alone would leave daily devotion silently
blank, so `app:deploy` seeds these.

It only does so when **the table is empty**. Those seeders overwrite by key,
so re-running a populated table would quietly revert a mantra or accent colour
an editor had customised. Empty means there is nothing to lose.

Tables treated this way: `states`, `deities`, `temple_categories`,
`facilities`, `devotional_days`.

**Sample temples are never re-seeded.** Re-running that seeder in production
would reset the verification level of every record an editor had checked,
which is worse than the gap it would fill.

## When something goes wrong

| Symptom | Cause |
| --- | --- |
| `Base table or view not found` | Migrations not run. `php artisan app:deploy --force`. |
| `Call to undefined function setting()` | Stale autoloader. `composer install --no-dev --optimize-autoloader`. |
| A feature is there but empty | Reference data missing. `php artisan app:deploy --check` will name it. |
| A config change does nothing | Stale config cache. `app:deploy` clears it; otherwise `php artisan config:clear`. |

## Does this touch my data?

No. Migrations add tables and columns; they do not delete rows. The temples,
photos and accounts already in the database are left alone.

The one thing to keep in mind is that a migration changes **structure**, so
take a database backup from hPanel before a large update. It costs a minute
and makes any surprise reversible.
