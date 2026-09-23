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

## Telangana temples

`TelanganaTempleSeeder` covers 45 temples across every region of Telangana —
Yadadri, Bhadrachalam, Vemulawada, Basara, Ramappa, the Thousand Pillar
Temple, Medaram, Chilkur Balaji and more — with 19 famous temples marked
**featured**, typical opening hours for the major shrines and their common
sevas.

It runs with `php artisan migrate --seed`. To load it into a live database,
where `app:deploy` deliberately never re-runs sample seeders:

```bash
php artisan temples:import-telangana
```

It is safe to repeat: verified and deleted temples are skipped, existing
records only have empty fields filled, and timings and pujas are only added to
temples that have none. Every record is **community** level, every puja has
"No published price", and every general timing says it is unconfirmed —
verify each against the temple before raising its trust level. No photographs
are imported; upload them with credit and licence through the admin.

The API takes `featured=1` and `sort=featured`; the admin has a **Famous
temple** toggle, column and filter.

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

The backend and admin for the app features. The Flutter client is in
[`temple-app`](https://github.com/PranayReddy10/temple-app) and was built in
parallel with this, so several of its screens work against the device rather
than these endpoints — see the sync column in
[ROADMAP.md](ROADMAP.md#phase-3--passport-trips-and-languages) for which are
still to be wired up.

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

## Images, mantras and songs

**Deities** carry an image, a mantra (script, transliteration and meaning)
and an accent colour. The mantra lives on the deity rather than on each
weekday, because it belongs to the deity: before that, the same Shiva mantra
had to be typed on every Shiva day and corrected in every one of them. A day
carries its own only where a tradition differs, and falls back otherwise.

**Temples** have a **cover image** on the form itself, not only in the gallery
relation manager — which only exists once the record is saved, so a temple
could be created, published and listed with no image at all. It writes the
primary `temple_photos` row rather than a second column that could disagree
with the gallery about which photo leads. Clearing it demotes that photo
rather than deleting it; the file is not always recoverable.

**Songs, chants and videos** hang off a weekday, a deity *or* a temple — one
table, because the rights rule is identical in all three cases and three
copies would be three places for it to drift. Precedence is most specific
first: a temple's own Suprabhatam before its deity's aarti. A song or video
still cannot be published without a licence recorded, whichever it belongs to.

**A mantra can be heard, not only read.** Each one can carry a recording —
paste a YouTube link or upload an MP3 from the mantra section itself, in one
step, rather than creating the media elsewhere and coming back. The recording
is an ordinary media row, so it carries its artist, credit, licence and
duration like everything else we play, and an unpublished or unlicensed one is
never served however it is attached.

The fallback runs field by field: a temple with its own verse but no recording
shows its verse and plays its deity's chant. Falling back wholesale would put
a chant of one verse under another.

The form tells you what the app will do with a link **before** you save it —
"YouTube — the app will embed this", "a direct audio file — the app will play
this in its own player", or "a YouTube link, but no video in it". A channel or
search URL cannot be played, and that is worth knowing now rather than when a
devotee taps it.

## Storage, and why images go blank

**Administration → Storage.**

"None of the images show" has half a dozen causes that are indistinguishable
from every other screen: the `public/storage` link was never created, a
redeploy lost it, the host forbids symlinks, the disk is set to Spaces with no
credentials, the credentials are wrong, or the files are not there. The page
checks each one separately and says which it is, then offers a button that
creates the link — the commonest cause, and needing SSH for it is why sites sit
with blank images for days.

Everything above that reads configuration. One check does not: **Check it now**
writes a real file, reads it back, deletes it, and then fetches a stored image
over HTTP the way a browser would. Configuration that looks right is exactly
the state somebody is stuck in when they arrive here.

**Switching to DigitalOcean Spaces is a radio button now**, not five .env
variables edited over SSH. Two rules hold it up, and each has a test:

- **Nothing switches until it is proven.** Saving Spaces runs a real write,
  read and delete against the credentials being entered, and refuses the
  switch if any of it fails — with a sentence you can act on ("the secret does
  not match the key", "there is no bucket by that name in this region") rather
  than the SDK's wall of XML. Credentials that do not work cannot be saved into
  service, because an upload failing server-side looks to the person uploading
  like a slow form.
- **A switch never moves a file.** Every row that holds a file also holds the
  disk it was written to, so photos uploaded before the change keep resolving
  from where they are, and switching back loses nothing. This is also why
  `MediaFileController` serves the local disk whether or not that is where new
  uploads go — gating it on the current disk sounded careful and would have
  404'd every older photo the moment somebody switched.

The secret is stored encrypted and never rendered again — not in the form, not
in the health checks, not in a page somebody is screen-sharing to ask for help.
Leaving it blank means "keep the one on file", so correcting a typo in the
bucket name does not wipe it. `.env` still works and still means something: a
setting overrides it, clearing the setting falls back to it, and a host that
would rather keep its secrets out of the database can set them in `.env` and
never open this screen.

Three more things underneath it, all of which were real faults rather than
precautions:

- **Files are served even when the link is missing.** `MediaFileController`
  answers `/storage/{path}` from PHP when Apache could not, with the same
  content type, cache headers and `Range` support the web server would have
  used. Slower, and every image becomes a PHP request — but the site does not
  go blank. Ranges matter more than they look: without them, seeking in a
  mantra recording re-downloads it, and Safari and iOS refuse to play audio or
  video at all.
- **What may be uploaded is defined once**, in `app/Support/UploadRules.php`.
  Every upload field reads its accepted types, its size limit and the sentence
  under the box from it, and the Storage page prints the same table — so the
  page cannot describe something the form does not enforce. A test fails if a
  field goes back to writing its own number.
- **PHP's own limit is shown beside each one.** Shared plans ship
  `upload_max_filesize` at 2 MB, and a form set to 50 MB on top of that fails
  before any application code runs: empty `$_FILES`, nothing in the log, an
  upload that appears to do nothing. Where the server is the smaller of the
  two, the table prints the server's number and says what the form allows.

**Devotees have a photo now.** `avatar_path` had been on the table since
devotees existed and the API returned `avatar_url` all along, but nothing could
write either — so every profile picture was an empty circle. `POST
/api/v1/me/avatar` sets one, and the admin shows it on the devotee list and
their page, falling back to initials drawn locally rather than fetched from a
third party.

## On a phone: installing the panels

Both panels are run from a phone far more than anyone plans for — a temple's
team checking tomorrow's events on the way home, an editor approving a photo on
a train. In a browser tab that costs a third of a small screen to chrome and a
hunt through open tabs to get back to.

**My profile → Use this on your phone** says how, per platform. Installed, the
panel opens from an icon, full height, already signed in.

Two installs, not one: the editorial panel and the temple portal are two jobs
done by two different people, so each has its own manifest, its own identity and
its own theme colour. A temple's team gets their own portal from their own icon
rather than a shortcut into a panel that answers them 403.

**iOS is the reason for most of this.** Safari fires no install prompt — the
event every other browser offers has never been implemented — so nothing
appears unless somebody already knows to look under Share → Add to Home Screen.
That is why the profile page spells it out, and why it says the part people get
stuck on: it has to be Safari, because Chrome and Firefox on an iPhone cannot
add anything to the home screen. Safari also only began reading the manifest's
display mode in 16.4, so the `apple-*` meta tags are not redundant with it —
they are what iOS actually reads for the title, the icon and the status bar.

The service worker is written to be dull on purpose, because the failure mode
of a service worker on an admin panel is not "no offline support" — it is
somebody seeing a page from before a deploy, saving a form against a CSRF token
that expired three days ago, and being unable to clear it by refreshing. So:

- **No document is ever cached.** A Filament page carries a CSRF token, a
  Livewire snapshot and whatever that one account may see. Navigations always go
  to the network; the cache is reached for only when there is no network, and
  only for the offline page.
- **Nothing but GET is touched.** Livewire's updates, every form and every
  upload are POSTs, and the worker declines them before doing anything else.
- **Static assets only, by path**, and they carry a version in their URLs. What
  that buys is the thing that matters on a slow connection: the panel opens
  without waiting on a megabyte of Filament's CSS.
- **A deploy retires the cache.** The cache name is built from a stamp over the
  deployed assets, so an old one is deleted on activate rather than left to
  serve last week's stylesheet.

The icons are drawn by `php artisan app:icons` rather than exported from a
design tool, so a change of brand colour is a config change and one command. The
mark is a gopuram — the one silhouette that still reads as "temple" at 32
pixels. `favicon.ico` is generated too: the one in the repository was zero
bytes, and the admin panel pointed its favicon at it through `asset()`, so it
was broken twice over.

## Support and reports

**Support → Support & Reports.** One queue for both, because they are the
same shape — somebody says something is wrong and waits for an answer — and
two queues would mean one of them going unread.

Reports are the half that matters. A listing with the wrong timings sends
devotees to a closed gate and nobody on the team will notice on their own.
So filing **does not need an account**: a report behind a sign-in wall is a
report most people will not file. Everyone gets a reference (`TP-XXXXXX`) to
quote, in an alphabet with no O/0 or I/1 because it gets read over the phone.

- **Inappropriate content is filed urgent automatically** — it is the one
  category that gets worse every hour it stays up.
- **Replies and internal notes** live in one chronology, because the order is
  the story. The reporter's copy comes from a separate relation, so a note
  cannot leak by something being eager-loaded on the wrong screen. Replies
  cannot be deleted; only notes can.
- **Replying sets "waiting for a reply"**, so an answered ticket stops looking
  identical to an untouched one. The reporter replying reopens it — a
  resolution they did not accept is not a resolution.
- The queue sorts **worst first, then oldest**, and opens on what nobody has
  picked up.

A report points at a record through an allow-list (temple, event, puja,
photo). Without one, a caller could aim a ticket at any model in the
application and the admin would render whatever came back.

## Getting around the admin

**Temples** can be grouped **state-wise or deity-wise** from the grouping
menu — "which Shiva temples do we have" and "what is missing in Telangana"
are the two questions that come up constantly, and a flat list of two
thousand rows answers neither. Tabs across the top carry counts: Published,
Waiting for review, Drafts, and **Needs work** (published but missing
coordinates, a photo or a description — listings a devotee can already reach
and be let down by).

Three lists that previously existed only inside a temple are now also in the
side menu, because each answers a question across all temples:

- **Puja & Sevas** — which sevas have no published price, which booking links
  have not been confirmed as official.
- **Temple Photos** — the photo library, with a badge counting the ones with
  no credit recorded.
- **Events & Programs** — submissions waiting for review.

Both views share one form and one set of actions, so the same decision cannot
behave differently depending on where you started.

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
