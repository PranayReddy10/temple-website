# Deploying to shared Hostinger

This app is built to run on a **shared** Hostinger plan: PHP 8.2+ and MySQL 8,
no Node.js process, no root, no Docker. Nothing here needs an npm build — the
admin theme is plain CSS on purpose.

- **Laravel 12** requires PHP **8.2 or newer**. Set the PHP version in
  hPanel under *Advanced → PHP Configuration* before deploying.
- Required extensions (all standard on Hostinger): `pdo_mysql`, `mbstring`,
  `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl`.

`composer.json` pins `config.platform.php` to `8.2.0`. That makes Composer
resolve every dependency against PHP 8.2 regardless of which PHP the developer
runs locally, so `composer install` on an 8.2 or 8.3 Hostinger plan cannot hit
a package that secretly needs 8.4. Do not remove the pin without also raising
the minimum PHP version documented here.

---

## 1. Create the database

In hPanel → *Databases → Management*, create a database and a user, then grant
the user access to it. Hostinger prefixes both with your account ID, so the real
names look like `u123456789_temple` and `u123456789_admin`. Copy them exactly.

## 2. Get the code onto the server

SSH in (hPanel → *Advanced → SSH Access*) and clone into a folder **beside**
`public_html`, not inside it:

```bash
cd ~
git clone https://github.com/PranayReddy10/temple-website.git app
cd app
```

Keeping the application outside the web root means `.env`, `storage/` and
`vendor/` are not reachable over HTTP. This matters: `.env` holds the database
password and the app key.

## 3. Install dependencies

```bash
composer install --no-dev --optimize-autoloader
```

If Composer is not on the PATH, fetch it locally:

```bash
curl -sS https://getcomposer.org/installer | php
php composer.phar install --no-dev --optimize-autoloader
```

## 4. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-temp-domain.example

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=u123456789_temple
DB_USERNAME=u123456789_admin
DB_PASSWORD=the-password-you-set

BRAND_NAME="Temple Passport"

# Set this if the domain is proxied through Cloudflare, otherwise Laravel
# builds http:// URLs on an https:// site.
TRUSTED_PROXIES=*
```

`APP_DEBUG=false` is not optional in production. With it on, any error page
prints environment variables, including the database password, to the visitor.

> `DB_HOST` is usually `localhost` on Hostinger. If the connection is refused,
> check the host shown next to the database in hPanel.

## 5. Point the document root at `public/`

Laravel serves from `public/`, and only `public/` may be web-reachable.

**Preferred:** in hPanel → *Domains*, set the domain's document root to
`app/public`.

**If your plan will not let you change the document root**, replace
`public_html` with a symlink:

```bash
mv ~/public_html ~/public_html_backup
ln -s ~/app/public ~/public_html
```

Do not copy the contents of `public/` into `public_html` and leave the app
inside it — that publishes `.env` to the internet.

## 6. Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --force
```

The seeder creates the first super admin and **prints the password once**.
Copy it, sign in at `https://your-domain/admin`, and change it immediately.

To choose the password yourself, set `ADMIN_EMAIL` and `ADMIN_PASSWORD` in
`.env` before seeding, then delete both lines afterwards.

Re-running the seeder never overwrites an existing admin account, so it is safe
to run again after adding new states or deities.

### No SSH? Import the SQL instead

Laravel builds its tables from migrations, so the project has no `.sql` file
checked in and normally needs none. If your plan has no SSH access and
phpMyAdmin is the only way in, generate one **locally** and import it:

```bash
php artisan db:mysql-dump --with-admin
# writes database/dumps/temple-passport.sql and prints an admin password once
```

Then in hPanel → *phpMyAdmin*, select the (empty) database, open **Import**,
choose the file and run it. It creates all 22 tables, loads the reference data
(states, districts, deities, circuits, facilities) and the sample temples, and
records the migration history so a later `php artisan migrate` does not try to
recreate what is already there.

The file contains a password **hash**, not the password. The plaintext is
printed once to the console of whoever generates the file, so if someone else
made the dump for you, you cannot sign in with it. After importing, set a
password you know:

```bash
php artisan admin:create
```

Delete the .sql file afterwards and do not commit it — `database/dumps/` is
gitignored for that reason, because a hash is still a working credential.

Regenerate it whenever migrations change; it is produced from the migration
files themselves, so it cannot drift from them.

## 7. Media storage on DigitalOcean Spaces

Temple photos do **not** belong on the web host. A shared Hostinger plan has a
modest disk quota and no CDN, and a gallery across thousands of temples would
exhaust both. Spaces is S3-compatible, so Laravel's own `s3` driver talks to it
with no DigitalOcean-specific package.

1. In the DigitalOcean control panel, create a **Space** and choose the region
   closest to your users (`blr1` for India).
2. Enable the **CDN** on that Space.
3. Create a **Spaces access key** and copy the key and secret. The secret is
   shown once.
4. Set the file listing to **restricted**; individual uploads are made public by
   the app, so the bucket itself does not need to be browsable.
5. Fill in `.env`:

```dotenv
MEDIA_DISK=spaces
DO_SPACES_REGION=blr1
DO_SPACES_BUCKET=your-space-name
DO_SPACES_KEY=...
DO_SPACES_SECRET=...
DO_SPACES_ENDPOINT=https://blr1.digitaloceanspaces.com
DO_SPACES_CDN_ENDPOINT=https://your-space-name.blr1.cdn.digitaloceanspaces.com
```

Point `DO_SPACES_CDN_ENDPOINT` at the **CDN** hostname, not the origin.
Getting this wrong works — and quietly serves every image from the origin,
which is slower and costs more in bandwidth.

`MEDIA_DISK` defaults to the local `public` disk, so a fresh clone, the test
suite and CI all run with no DigitalOcean account at all. Only production needs
these values.

Each photo row records the disk it was written to, so photos uploaded before
the switch keep resolving from local storage afterwards. Moving existing files
is a separate copy step, not something the switch does for you.

### Image variants

Uploads are resized to a 1200px medium and a 400px thumbnail, in WebP where the
server's GD build supports it and JPEG otherwise. This runs **synchronously**
during the admin upload, which is the right trade on shared hosting: there is no
long-running queue worker, and the cost falls on an editor rather than a
devotee. When volume grows, move `TemplePhotoProcessor` into a queued job.

## 8. Cache for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

Run all four again after **every** deploy that changes config, routes or views.
A stale config cache is the usual reason a `.env` change appears to do nothing.

`storage:link` can fail on a shared plan without it being your fault: some
hosts disable `symlink()`, and an account moved between servers can lose the
link. The site does not break — an uploaded file that Apache cannot find falls
through to `MediaFileController`, which serves it from PHP with the same
content type, cache headers and range support Apache would have used. It is
slower, and it means every image is a PHP request, so the link is still worth
having. **Administration → Storage** in the admin panel says which of the two
is happening, and offers a button that runs `storage:link` for you.

## 9. Permissions

```bash
chmod -R 775 storage bootstrap/cache
```

---

## Deploying an update

```bash
cd ~/app
php artisan down
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan app:deploy --force
php artisan up
```

The routine is written up on its own in **[UPDATING.md](UPDATING.md)**.

`app:deploy` runs the pending migrations, seeds reference data a release
introduced, rebuilds the caches, and checks the two things that fail silently
and confusingly:

- **A pending migration.** It shows up as `Base table or view not found` on a
  page that worked yesterday. New code expects tables the database does not
  have yet.
- **A stale autoloader.** `git pull` does not regenerate it, so a newly added
  helper is missing and pages die with `undefined function` even though the
  file is plainly in the repository. This is why `composer install` runs
  before `app:deploy`, and the command refuses to continue if it was skipped.

Use `php artisan app:deploy --check` to see what would happen without changing
anything. Without `--force` it lists the pending migrations and asks first.

> **`Table '...temple_user' doesn't exist`** or any other missing table means
> exactly this: the code was deployed but the migrations were not run.
> `php artisan app:deploy --force` fixes it, and existing data is untouched —
> migrations only add the new tables and columns.

## Scheduled tasks

Shared plans run cron from hPanel → *Advanced → Cron Jobs*. Add one entry,
every minute, and Laravel handles the rest of the scheduling itself:

```
* * * * * cd ~/app && php artisan schedule:run >> /dev/null 2>&1
```

Not needed for slice 1. It becomes necessary for stale-data flagging and
notifications later in the roadmap.

---

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| 500 with a blank page | Read `storage/logs/laravel.log`. Almost always a missing `APP_KEY` or wrong DB credentials. |
| `Base table or view not found` | Pending migrations. Run `php artisan app:deploy --force`. |
| `Call to undefined function setting()` | Stale autoloader. Run `composer install --no-dev --optimize-autoloader`. |
| "These credentials do not match our records" | The dump stores a hash, not a password. Run `php artisan admin:create` to set one you know. |
| "No application encryption key" | `php artisan key:generate`, then `php artisan config:clear`. No SSH? See [TROUBLESHOOTING_403.md](TROUBLESHOOTING_403.md). |
| Admin panel loads over https but assets or login fail | Behind Cloudflare without `TRUSTED_PROXIES=*`, so Laravel emits http:// URLs. |
| Directory listing, or the raw project tree | Document root is not pointing at `public/`. See step 5. |
| **403 Forbidden** | Almost always the document root or a symlink. Full diagnostic: [TROUBLESHOOTING_403.md](TROUBLESHOOTING_403.md). |
| `.env` downloads in a browser | The app is inside the web root. Stop, move it out, then **rotate the DB password and `APP_KEY`** — treat them as leaked. |
| Config change has no effect | `php artisan config:clear && php artisan config:cache` |
| `SQLSTATE[HY000] [1045]` | Wrong DB username or password, or the user was never granted access to the database. |
| `SQLSTATE[HY000] [2002]` | Wrong `DB_HOST`. Use the host shown in hPanel. |
| Admin panel unstyled | Confirm `public/css/temple-admin.css` deployed and `php artisan storage:link` ran. |
| Every uploaded image blank at once | Open **Administration → Storage**. It checks the `public/storage` link, whether the host allows symlinks, whether the folder is writable, and fetches a real image over the web to prove it. |
| An upload appears to do nothing | PHP's own `upload_max_filesize` / `post_max_size`, which shared plans ship at 2 MB. The upload fails before any of this application runs, so nothing reaches the log. The Storage screen shows the server's real limit beside each form's. Raise both in hPanel under PHP Configuration, or in a `.user.ini` at the site root. |
| A mantra plays on Android but not on iPhone | Almost always a server that will not answer a `Range` request. Ours does, through the link and through the fallback alike — but a CDN or proxy in front may not. |
| PHP syntax errors on deploy | PHP version is below 8.2. Change it in hPanel. |

## When to leave shared hosting

Moving photos to Spaces removes the reason that would otherwise have forced a
VPS at slice 2, so shared hosting now carries the project comfortably through
slice 4 and well beyond. Revisit when any of these become true:

- Image processing volume makes synchronous resizing on upload too slow for
  editors, and a real queue worker is needed.
- The API needs Redis for caching or rate limiting rather than the database.
- Traffic makes shared CPU limits the bottleneck.
- Full-text temple search outgrows MySQL and needs a dedicated search service.

Note that none of these is about storage any more.

Because the Flutter app only ever talks to `/api/v1`, that move is a hosting
change, not an app release.
