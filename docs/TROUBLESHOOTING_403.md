# "403 Forbidden" on a fresh Hostinger deploy

A 403 means the web server found the folder but refused to serve anything from
it. It is almost never a Laravel error — Laravel has not run yet. Work through
these in order; the first two account for the large majority of cases.

---

## 1. Is the document root pointing at `public/`?

**This is the usual cause.** Laravel serves from `public/`, and only `public/`
should be web-reachable. If the domain points at the repository root instead,
there is no `index.php` there, directory listing is disabled, and Apache
answers 403.

Check what the domain actually points at in hPanel → *Domains*. It must be the
`public` directory, for example `app/public` or `public_html/public` — never
the folder containing `artisan`, `.env` and `composer.json`.

```bash
ls ~/public_html
```

- See `index.php`, `.htaccess`, `favicon.ico`, `robots.txt` → correct.
- See `app/`, `artisan`, `composer.json`, `vendor/` → **wrong**, this is the
  project root. Repoint the document root at `public/`.

> While the project root is web-reachable, `.env` is downloadable over HTTP.
> Once you have fixed the root, treat the database password and `APP_KEY` as
> leaked and rotate both.

## 2. Did you use a symlink that Apache will not follow?

The deploy guide offers `ln -s ~/app/public ~/public_html`. Shared hosting
often refuses to follow symlinks — `FollowSymLinks` may be disabled, or the
target lies outside the permitted path — and the result is a 403.

```bash
ls -la ~ | grep public_html
```

If it shows `public_html -> /home/uXXXX/app/public`, the symlink is the prime
suspect. Two fixes, in order of preference:

1. **Repoint the document root in hPanel** to `app/public` and remove the
   symlink entirely. This is the clean solution.
2. If your plan will not allow that, deploy the project *inside* `public_html`
   and move the web files up:

```bash
# Project lives at ~/public_html/app, domain root stays ~/public_html
cd ~/public_html
mv app/public/* app/public/.htaccess ./
```

Then edit `index.php` in `public_html` so the two `require` paths point at
`app/` instead of `../`:

```php
require __DIR__.'/app/vendor/autoload.php';
$app = require_once __DIR__.'/app/bootstrap/app.php';
```

This keeps `.env` and `vendor/` out of the web root.

## 3. Permissions

```bash
find ~/app -type d -exec chmod 755 {} \;
find ~/app -type f -exec chmod 644 {} \;
chmod -R 775 ~/app/storage ~/app/bootstrap/cache
```

A directory without the execute bit cannot be traversed, which also shows as
403.

## 4. Is `.htaccess` present?

`public/.htaccess` ships with the project and is easy to lose, because some
FTP clients hide dotfiles.

```bash
ls -la ~/app/public/.htaccess
```

If it is missing, restore it from the repository.

## 5. Is the folder simply empty?

```bash
ls -la ~/public_html
```

An empty document root gives 403 rather than 404. If the clone or upload did
not land where you think it did, this is why.

---

## Once past the 403

A **500** next is progress: the web server is now running Laravel, and the
error is in the application. Read the real reason:

```bash
tail -50 ~/app/storage/logs/laravel.log
```

Common first-run causes: missing `APP_KEY` (`php artisan key:generate`),
wrong database credentials, or `storage/` not writable.

Verify the app is alive with the health endpoint, which needs no database:

```
https://your-domain/up
```

Then the admin panel at `/admin` and the API at `/api/v1/temples`.

---

## Reporting it

If none of the above fixes it, the useful details are:

- What `ls -la ~/public_html` prints.
- What the document root is set to in hPanel.
- The last 50 lines of `storage/logs/laravel.log`.
- Whether `https://your-domain/up` also returns 403.

The last one is the most telling: if `/up` returns 403 as well, the request is
never reaching Laravel and the cause is in steps 1 to 3.
