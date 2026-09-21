# Deploying to shared Hostinger

This app is built to run on a **shared** Hostinger plan: PHP 8.2+ and MySQL 8,
no Node.js process, no root, no Docker. Nothing here needs an npm build — the
admin theme is plain CSS on purpose.

- **Laravel 12** requires PHP **8.2 or newer**. Set the PHP version in
  hPanel under *Advanced → PHP Configuration* before deploying.
- Required extensions (all standard on Hostinger): `pdo_mysql`, `mbstring`,
  `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl`.

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

## 7. Cache for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

Run all four again after **every** deploy that changes config, routes or views.
A stale config cache is the usual reason a `.env` change appears to do nothing.

## 8. Permissions

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
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

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
| "No application encryption key" | `php artisan key:generate` |
| Directory listing, or the raw project tree | Document root is not pointing at `public/`. See step 5. |
| `.env` downloads in a browser | The app is inside the web root. Stop, move it out, then **rotate the DB password and `APP_KEY`** — treat them as leaked. |
| Config change has no effect | `php artisan config:clear && php artisan config:cache` |
| `SQLSTATE[HY000] [1045]` | Wrong DB username or password, or the user was never granted access to the database. |
| `SQLSTATE[HY000] [2002]` | Wrong `DB_HOST`. Use the host shown in hPanel. |
| Admin panel unstyled | Confirm `public/css/temple-admin.css` deployed and `php artisan storage:link` ran. |
| PHP syntax errors on deploy | PHP version is below 8.2. Change it in hPanel. |

## When to leave shared hosting

Shared hosting is right for slices 1 to 4. Plan to move to a VPS when any of
these become true:

- Temple photo storage outgrows the plan's disk quota (slice 2 adds galleries).
- Queued image processing needs a long-running worker rather than cron.
- The API needs Redis for caching or rate limiting.
- Traffic makes shared CPU limits the bottleneck.

Because the Flutter app only ever talks to `/api/v1`, that move is a hosting
change, not an app release.
