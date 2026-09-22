# Updating the live site

Run this after every code change. It is the same four lines every time.

```bash
cd ~/domains/madeforu.co.in/public_html/temple

php artisan down
git pull origin claude/compassionate-ritchie-g4t6db
composer install --no-dev --optimize-autoloader
php artisan app:deploy --force
php artisan up
```

That is the whole routine. **You never import a .sql file again.**

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
4. **Rebuilds the caches**, so a stale config cache does not hide your changes.
5. **Creates `public/storage`** if it is not there.

Look before you leap:

```bash
php artisan app:deploy --check
```

That reports exactly what it would do and changes nothing. Without `--force`
it lists the pending migrations and asks before running them.

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
