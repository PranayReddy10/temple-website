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

A **500** next is progress: the request now reaches Laravel and the error is in
the application. Work through the three below in order.

### 1. "No application encryption key has been specified"

`APP_KEY` is empty in `.env`. Laravel uses it for session and cookie
encryption and refuses to serve anything without it.

**With SSH:**

```bash
cd ~/app
php artisan key:generate
php artisan config:clear
```

**Without SSH**, generate one yourself — never paste a key from a public
website, as that key would encrypt every session cookie you issue. Upload this
file as `public/genkey.php`, open it once in a browser, copy the line, then
**delete the file**:

```php
<?php
echo 'APP_KEY=base64:'.base64_encode(random_bytes(32));
```

Paste the result over the empty `APP_KEY=` line in `.env` using hPanel's File
Manager, then delete `public/genkey.php`. Anything left in `public/` is
reachable by anyone.

If you cached config earlier, the cache wins over `.env` — delete
`bootstrap/cache/config.php` through File Manager to clear it without SSH.

### 2. Turn debug off

If the 500 showed you a styled error page with stack traces, file paths and
request headers, `APP_DEBUG` is still `true`. That page is visible to every
visitor who triggers an error and can expose configuration values. In `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
```

Errors then log to `storage/logs/laravel.log` instead of the browser.

### 3. Behind Cloudflare? Trust the proxy

Cloudflare terminates TLS and forwards plain HTTP to the origin with
`X-Forwarded-Proto: https`. Untrusted, Laravel concludes the request is HTTP
and builds `http://` URLs on an `https://` site — broken assets, failed admin
logins, sometimes a redirect loop.

You are behind Cloudflare if responses carry a `cf-ray` header. Then set:

```dotenv
TRUSTED_PROXIES=*
```

`*` is appropriate when the origin is only reached through Cloudflare. If the
origin's own address is public, list the proxy IPs instead — a trusted
forwarded header lets anyone who can reach the origin directly spoof their IP
and the scheme.

### "These credentials do not match our records"

You reached the login page but cannot get in. Two causes, both common.

**You imported a .sql dump someone else generated.** The dump stores a password
*hash*, not the password. The plaintext was printed once to the console of
whoever generated the file and cannot be recovered from it. Importing the file
gives you an account you cannot sign in to.

**You ran `db:mysql-dump --with-admin` yourself and tried the password it
printed.** That command writes a *file*; it does not touch the database. The
password it printed belongs to the new file, not to the account already in your
database.

Either way, set a password you know:

```bash
php artisan admin:create
```

It lists the accounts that actually exist, then prompts for an email and a
password without echoing it. On an existing account it resets the password and
reactivates it; otherwise it creates one. Non-interactively:

```bash
php artisan admin:create you@example.com --password='a-long-password'
```

> Also check the email. A wrong address and a wrong password produce the same
> message, and the seeded account is `admin@example.com` unless `ADMIN_EMAIL`
> said otherwise. The table printed by `admin:create` shows what is really
> there.

### Then check it works

```
https://your-domain/up           # health, no database needed
https://your-domain/api/v1/temples
https://your-domain/admin
```

`/up` returning 200 while `/admin` fails means the framework is healthy and the
problem is the database or the app itself:

```bash
tail -50 ~/app/storage/logs/laravel.log
```

---

## Reporting it

If none of the above fixes it, the useful details are:

- What `ls -la ~/public_html` prints.
- What the document root is set to in hPanel.
- The last 50 lines of `storage/logs/laravel.log`.
- Whether `https://your-domain/up` also returns 403.

The last one is the most telling: if `/up` returns 403 as well, the request is
never reaching Laravel and the cause is in steps 1 to 3.
