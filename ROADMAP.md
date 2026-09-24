# Product Roadmap — Working Name: "Temple Passport"

> **The product name is not finalised.** It is never hard-coded. The brand name,
> tagline and domain all come from `config/brand.php`, driven by `.env`
> (`BRAND_NAME`, `BRAND_TAGLINE`). Renaming the product later is a one-line
> `.env` change, not a find-and-replace across the codebase.

A digital pilgrimage companion for India: discover temples, plan yatras,
collect digital stamps, preserve pilgrimage memories.

**Product loop:** Discover → Learn → Plan → Visit → Check-in → Collect Stamp →
Save Photo → Share → Plan the next Yatra.

---

## Repository split

| Repo | Contains |
| --- | --- |
| `temple-website` | Laravel 12 REST API + Filament admin + temple portal + public web |
| `temple-app` | Flutter — Android, iOS and Flutter Web from one codebase |

The Flutter app talks to this repo only through versioned REST endpoints
(`/api/v1/...`). Nothing in the app depends on Laravel specifics, so the
backend can migrate to Node/VPS in a later phase without an app release.

## Why Laravel 12 + MySQL

Hosting is **shared Hostinger**, which runs PHP 8.2+ and MySQL 8 on every plan
but cannot run persistent Node.js processes (that needs a VPS). The project
plan lists Laravel as an accepted backend, so we take the option that deploys
to the hosting we actually have.

Laravel **12**, not 11: the 11.x line is past security support and carries two
unpatched advisories (CRLF injection in the default email rule, signed-URL path
confusion) that were fixed only in 12.x. Laravel 12 needs PHP 8.2, which shared
Hostinger has; Laravel 13 needs PHP 8.3, which is less reliably available.

## Storage

Temple photos live in **DigitalOcean Spaces**, not on the web host. Spaces is
S3-compatible, so Laravel's own `s3` driver reaches it with no
DigitalOcean-specific package, and images are delivered from its CDN.

This matters for the hosting plan: photo storage was the thing that would
otherwise have forced a move off shared Hostinger at slice 2. With media
elsewhere, shared hosting carries the project comfortably through slice 4.

`MEDIA_DISK` defaults to the local `public` disk, so a fresh clone, the test
suite and CI all run with no DigitalOcean account. Only production sets it to
`spaces`. Each photo row records the disk it was written to, so photos uploaded
before the switch keep resolving afterwards.

---

## Three audiences, three logins

The platform serves three groups with almost nothing in common. Each gets its
own entry point.

| Audience | Entry point | Authentication | Stored in |
| --- | --- | --- | --- |
| **Staff** — super admin, editors | `/admin` | Session (Filament) | `users` |
| **Temple authority** — trust, temple office | `/temple` | Session (Filament) | `users`, scoped to their temples |
| **Devotees** — app and web users | Flutter app, public web | API token (Sanctum) | `devotees` (separate table) |

**Staff and temple authorities share the `users` table.** Both are small,
known populations who manage content through a Filament panel. A
`temple_user` pivot decides which temples an authority may touch, and every
query in that panel is scoped through it. A temple admin who can edit a
temple they do not own is the failure mode to design against.

**Devotees get their own table, deliberately.** Three reasons:

1. **Scale.** Devotees are expected in the millions; staff in the hundreds.
2. **Different auth.** Devotees will sign in with phone OTP or a social
   provider; staff use passwords and, later, two-factor.
3. **Blast radius.** If both lived in one table, a single mass-assignment
   mistake could give a devotee account a staff role. Separate tables make
   that class of bug impossible rather than merely unlikely.

They also share almost no columns: a devotee has a Passport, stamps, visits
and memories; a staff user has a role and an audit trail.

---

## Delivery slices

Features ship one slice at a time. Each slice is independently testable and
leaves the system in a working state. **Nothing is built all at once.**

### Phase 1 — Backend and admin foundation

| # | Slice | Scope | Status |
| --- | --- | --- | --- |
| 1 | **Admin auth + Temple CRUD** | Admin login, roles, temples table, deities, categories, states/districts, draft→published workflow, trust labelling, seed data | ✅ **Done** |
| 2 | **Temple media + timings** | Photo gallery on DigitalOcean Spaces with generated variants, opening/darshan/aarti timings, closure and special-hour overrides | ✅ **Done** |
| 3 | **Puja / Seva + facilities** | Published pujas with time, duration, eligibility, fee and official booking route; visitor rules; facilities including accessibility | ✅ **Done** |
| 4 | **Public REST API v1** | Read endpoints for the app: search, filter, nearby with real distance ordering, temple detail, deity/category/state/facility listings | ✅ **Done** |
| — | Admin dark / light theme | Temple palette in both schemes, with a Light / Dark / System switcher in the user menu | ✅ **Done** — shipped with slice 1 |
| — | Admin dashboard & temple styling | Today's deity panel with its mantra, a work queue of what needs a person, pilgrimage-circuit completeness, and gopuram-derived styling across both schemes | ✅ **Done** |

### Phase 2 — The other two logins  ✅ **Complete**

| # | Slice | Scope | Status |
| --- | --- | --- | --- |
| 5 | **Admin settings + puja images** | Settings screen for brand name, tagline, contact, default language, feature flags and maintenance mode; image upload on each puja/seva | ✅ **Done** |
| 6 | **Temple authority portal** | Separate `/temple` panel. Claim-and-verify flow, `temple_user` scoping, temple team manages its own timings, photos, pujas and contact details. Nothing outside their own temples is reachable | ✅ **Done** |
| 7 | **Temple events & programs** | Festivals, programs, special pujas and announcements published by the temple, with image, date range and recurrence. Verified temples publish directly; unverified go to a review queue | ✅ **Done** |
| 8 | **Devotee accounts** | Signup, login, profile, saved temples. Separate `devotees` table and Sanctum token auth, exposed through `/api/v1/auth` and `/api/v1/me` | ✅ **Done** |
| 9 | **Daily devotional content** | Weekday-to-deity content: Monday Shiva, Tuesday Hanuman, and so on. Curated photos, videos and songs surfaced on the app home screen each day, with a per-day accent colour and the deity's temples | ✅ **Done** |

### Phase 2.1 — Making it usable  ✅ **Complete**

Not new features; the seams between the ones already built.

| Slice | Scope | Status |
| --- | --- | --- |
| **Granting temple access** | The Temple Admin account can be created from the grant form itself, so a first-time super admin is no longer shown a required dropdown with nothing in it and no way forward | ✅ **Done** |
| **Temple Trust Access in the side menu** | The same grants, and the pending ones badged, as a queue in **Administration** rather than only inside a temple record. Same form and actions from either direction | ✅ **Done** |
| **Events & Programs in the side menu** | Submissions from every temple in one review queue, so approving one does not start with guessing which temple sent it | ✅ **Done** |
| **A clickable dashboard** | Every tile opens the records it counted, filtered the same way; a tile the role may not open is unlinked rather than a 403. Deity panel and circuit rows link through too | ✅ **Done** |
| **My profile** | A real account page in both panels — name, email, password, plus the role, the panel it signs into and the temples it covers. The dashboard's sign-out card is gone; sign out stays in the user menu | ✅ **Done** |

### Phase 3 — Passport, trips and languages  ✅ **Both halves shipped**

Every slice has a **backend half** (schema, API, admin) in `temple-website`
and a **Flutter half** in [`temple-app`](https://github.com/PranayReddy10/temple-app).
They were built in parallel, so a few of the app's features work locally
against endpoints that now exist and it has yet to sync to — the last column
says which.

| # | Slice | Scope | Backend | App | Synced |
| --- | --- | --- | --- | --- | --- |
| 10 | **App shell** | Temple design system in light and dark, tinted per weekday deity, temple-door transitions, 5-tab navigation, API client with offline fallback | n/a | ✅ | n/a |
| 11 | **Explorer + temple profile** | Search by name/deity/city/state, nearby, filters, lamp map, day pages, full temple profile | ✅ | ✅ | ✅ `GET /temples`, `/days` |
| 12 | **Passport** | Visits, check-in, stamps, circuit collections, achievements | ✅ | ✅ | ⬜ app keeps visits on the device; `/me/visits` and `/me/passport` are waiting |
| 13 | **Photo Stamp** | Visit photo, temple-themed memory card, original kept untouched, moderation | ✅ | ✅ | ⬜ card composed on the device; `/temples/{slug}/photos` is waiting |
| 14 | **Favourites + Yatra planner** | Saved temples, itinerary by days, reorder, Yatra mode, route in Maps | ✅ | ✅ | 🟡 favourites sync to `/me/saved-temples`; trips are local, `/me/yatras` is waiting |
| 15 | **Languages: EN / TE / HI** | Twelve configured, three shipping; app interface strings with bundled Indic fonts; translated temple fields with English fallback, reviewed-only serving | ✅ | ✅ | 🟡 app strings are bundled; `?lang=` on the API and `GET /languages` are waiting |
| 16 | **User memories** | A devotee's own writing about a visit, private by default | ✅ | ⬜ | ⬜ `/me/memories` |
| 17 | **Devotee analytics** | Sign-in events for both guards, active-user windows, trips being planned, per-account profile | ✅ | n/a | n/a |

The app works with **no backend at all**: when the API is unreachable it falls
back to the bundled sample records and says so on screen. That is what made
building both halves at once possible, and it is also why the sync column
above is the remaining work rather than a defect — a device that was offline
at the temple gate still has to be able to record the visit.

### Phase 4 — Detail, and being told when we are wrong  ✅ **Complete**

| Slice | Scope | Status |
| --- | --- | --- |
| **Deity images and mantras** | Image, mantra with transliteration and meaning, accent colour — on the deity, where they belong, with weekdays falling back to them | ✅ |
| **Temple cover image** | Set while creating a temple rather than only afterwards; writes the primary photo row rather than a second column | ✅ |
| **Temple mantras and songs** | Per-temple verse and recordings, falling back to the deity's; one polymorphic media table for weekdays, deities and temples | ✅ |
| **Side-menu lists** | Puja & Sevas and Temple Photos alongside Events, each sharing one form with the version inside a temple | ✅ |
| **State-wise and god-wise** | Grouping on the temple list, plus tabs with counts including "needs work" | ✅ |
| **Support and reports** | One queue, filing without an account, references, internal notes kept apart from replies, allow-listed report subjects | ✅ |

### Phase 4.1 — Passport QR and the temple counter  ✅ **Complete**

| Slice | Scope | Status |
| --- | --- | --- |
| **Temple QR printing for temple staff** | The temple portal shows each temple's signed check-in code, prints it as an A4 poster and downloads the SVG. Staff can print any temple's; a temple admin only their own, anything else is a 404 | ✅ |
| **Devotee passport codes** | A random, resettable code per devotee (never the id). `GET /passports/{code}` and `/passport/{code}` show the name, photo and public visits only — never contact details, notes or private visits | ✅ |
| **Marking a visit at the counter** | Temple portal → Scan passport → Mark visited today. Verified, method `staff`, `verified_by` recorded; one per temple per day; only temples the account manages. The app cannot claim `staff` itself | ✅ |
| **Admin passport scan** | Scan a devotee's code and open their record | ✅ |
| **Memory photos** | Up to three private photos per visit alongside the passport photo (`kind = memory`), outside moderation | ✅ |

### Phase 4.2 — App control, sign-in, notifications and money  ✅ **Complete**

| Slice | Scope | Status |
| --- | --- | --- |
| **App control** | Maintenance mode and a per-platform update popup (latest / minimum version, store links), read by the app on every launch from `GET /app/config` | ✅ |
| **Google and Apple sign-in** | Identity tokens verified against the providers' keys; account linking by verified email; each method switchable, client ids set in the admin panel | ✅ |
| **Notifications** | Compose, schedule and send from the admin panel to everyone, a platform, a temple's followers, a home state or one devotee; in-app inbox with read state; Firebase push over topics, configured without a build | ✅ |
| **Plans and payments** | Plans with benefits (no ads, more memory photos, gold passport); Razorpay, PhonePe, Cashfree and PayU checkout, server-confirmed, webhook-safe; payments list, refunds, granted plans | ✅ |
| **Ads** | AdMob or AppLovin MAX (Meta via mediation), native placements on temple, explore, home and day pages, test mode, off for no-ads plans | ✅ |

See `docs/MONETISATION.md` for the store rules and the next phase.

### Phase 5 — What devotees add  ⬜ **Next**

| Slice | Scope | Status |
| --- | --- | --- |
| **Likes and follows** | Follow a temple; a like as the lightest signal of interest, distinct from saving and from planning a trip | ⬜ |
| **Reviews and ratings** | A devotee's account of a visit, moderated like photos are. The hard part is not the schema — it is that a place of worship is not a restaurant, and the product has to decide what it is asking people to rate | ⬜ |
| **Devotee photos on a temple** | Approved Photo Stamps promoted into a temple's own gallery, credited to the devotee, with the temple able to object | ⬜ |
| **Notifications** | Festival and event reminders for followed temples; the first thing here that can annoy people, so it starts opt-in and per-temple | ⬜ |

The open question for reviews, worth settling before any of it is built: a
one-to-five star average is how restaurants are ranked, and applying it to
temples would produce a leaderboard of places of worship. Rating the *visit*
— queue length, accessibility, facilities, how accurate our listing turned
out to be — says something useful without ranking the sacred.

### Later phases

Community submissions and moderation · GPS and QR visit verification ·
advanced Yatra planner · festival calendar and notifications · Family
Passport · certificates and achievements · offline trip packs · hotel and
travel partnerships · Temple Admin SaaS · official QR Passport network ·
authorized puja/seva/prasadam · AI assistant grounded in verified temple data.

---

## Notes on the Phase 3 slices

### Slice 12 — what makes a stamp

A visit is a row; a stamp is the existence of a **verified** visit, derived
rather than stored, so revoking a verification revokes the stamp with it
instead of leaving an orphan in a collection.

The decision that carries the whole feature is that a manual check-in never
verifies itself, however good the coordinates it sends. Recording a pilgrimage
from before the app existed is exactly what a passport is for, so manual entry
has to exist — but a collection that treats a claim and a GPS fix identically
is a list anyone can type in, and then "6 of 12 Jyotirlingas" means nothing.
Staff can verify a visit the device could not, and revoke one that was false.

The GPS radius is generous (500m, configurable). Large complexes cover hundreds
of metres and phone GPS is poor between tall gopurams; the failure that matters
is telling a devotee standing in the queue that they are not at the temple.

### Slice 13 — the original is the irreplaceable half

The generated card can always be re-rendered from the photo. The photo cannot
be recovered from the card, so both are stored and the original is never
overwritten.

Moderation is pending by default and cannot be otherwise: this is user-supplied
imagery attached by name to real places of worship. Approval is still not
publication — the devotee's own `is_public` has to agree, and both conditions
live in one scope so a caller that checks only the status cannot leak a private
photo.

### Slice 14 — a planner, not a router

Ordering temples by road distance needs a maps provider, a budget and an
internet connection that a devotee planning on a train does not have. What they
do have is a list they can reorder themselves, which works offline. Recording a
visit closes the planned stop for that temple, which is the link back to the
Passport.

### Slice 15 — rows, not columns

`name_te`, `name_hi`, `name_ta`… is a migration per language across every
translatable table, and India has more languages than that approach survives.
A row per value adds a language by inserting rows. A JSON column would answer
"which temples have no Telugu description" only by reading every row, and that
is the question the admin actually asks.

Twelve languages are configured; three ship. A language offered in the picker
and then mostly blank reads as neglect rather than as progress, so availability
is a separate flag from what the schema can hold.

Two serving rules: a missing translation falls back to English rather than to
nothing, because a listing that renders half-blank looks broken rather than
untranslated; and an unreviewed translation is not served at all, because a
deity's name rendered wrongly in someone's own language is worse than the
English they can at least recognise.

### Slice 17 — why sign-ins are events

`last_login_at` can only ever hold the latest value. It cannot say how many
people signed in this week, whether a returning devotee is a daily user or a
once-a-year one, or that one account has failed twenty attempts from three
addresses — and none of that can be reconstructed after the fact. So the event
is written at the time, for both guards, successes and failures alike.

"Active users" means distinct people who signed in, never sign-ins. A devotee
who opens the app eight times in a day is one active user, and the metric that
says eight is the one that will be quoted to somebody.

---

## Notes on the Phase 2 slices

### Slice 6 — Temple authority portal

Section 19 of the project plan. A temple claims its profile, the claim is
verified by staff, and only then can the temple team edit anything.

The security requirement is narrow and absolute: **a temple admin must not be
able to read or write any temple outside their own.** That is enforced by
scoping every query in the panel through `temple_user`, not by hiding
navigation links.

Temple-edited fields stay separated from editorial ones. A temple correcting
its own darshan timings should not be able to overwrite a sourced history
section, and changes they make are recorded so staff can review them.

Granting that access is itself a staff workflow, and it has two natural
starting points: the temple you are looking at, and the queue of claims
waiting on you. Both exist, and both drive the same form — a grant reached
from the side menu and one reached from inside a temple must not be able to
behave differently. The account the seat is given to must hold the Temple
Admin role, since that role is what the portal's sign-in gate checks, so the
form creates the account itself rather than sending you elsewhere to make one
and come back.

### Slice 7 — Events and programs

Temple-published content raises a moderation question that needs an answer
before the feature ships: a verified temple publishing to thousands of devotees
without review is the point of the feature, but an unverified one doing the
same is a spam vector. The proposal is that verification level decides —
`official` and `verified` temples publish directly, everyone else queues for
review.

### Slice 9 — Daily devotional content

The traditional weekday associations are the backbone:

| Day | Commonly associated with |
| --- | --- |
| Monday | Shiva |
| Tuesday | Hanuman, Ganesha |
| Wednesday | Krishna, Vithoba |
| Thursday | Vishnu, Dattatreya, Guru |
| Friday | Devi, Lakshmi |
| Saturday | Shani, Venkateswara, Hanuman |
| Sunday | Surya |

Regional traditions differ, so the mapping is data in a table rather than
constants in code, and more than one deity per day is allowed.

> **Songs and videos are copyrighted, and this is the one slice with legal
> exposure.** A devotional recording is owned by its performer or label even
> when the composition is centuries old. The schema therefore requires a
> licence and a source on every media row, the same way temple facts require a
> source. Three workable routes: license recordings directly, use
> public-domain or Creative Commons recordings with attribution, or embed
> official YouTube uploads rather than hosting audio. Hosting ripped audio is
> not one of them.

---

## Data quality is the product

The database is the core asset. Per the project plan, temple information is
**sourced and maintained**, not scraped from random websites:

- Official temple and government sources take priority.
- Every important field records its **source** and **last-verified date**.
- Content is labelled **official**, **verified**, **community** or **sponsored** —
  these are never blurred.
- An unofficial payment or booking route is **never** presented as official.
- Community submissions are moderated; edit history is retained.
- Stale timings are flagged for review.

These rules are enforced in the schema from slice 1, not bolted on later.
