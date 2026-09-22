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

### Phase 2 — The other two logins  ✅ **Complete**

| # | Slice | Scope | Status |
| --- | --- | --- | --- |
| 5 | **Admin settings + puja images** | Settings screen for brand name, tagline, contact, default language, feature flags and maintenance mode; image upload on each puja/seva | ✅ **Done** |
| 6 | **Temple authority portal** | Separate `/temple` panel. Claim-and-verify flow, `temple_user` scoping, temple team manages its own timings, photos, pujas and contact details. Nothing outside their own temples is reachable | ✅ **Done** |
| 7 | **Temple events & programs** | Festivals, programs, special pujas and announcements published by the temple, with image, date range and recurrence. Verified temples publish directly; unverified go to a review queue | ✅ **Done** |
| 8 | **Devotee accounts** | Signup, login, profile, saved temples. Separate `devotees` table and Sanctum token auth, exposed through `/api/v1/auth` and `/api/v1/me` | ✅ **Done** |
| 9 | **Daily devotional content** | Weekday-to-deity content: Monday Shiva, Tuesday Hanuman, and so on. Curated photos, videos and songs surfaced on the app home screen each day, with a per-day accent colour and the deity's temples | ✅ **Done** |

### Phase 3 — Flutter app

| # | Slice | Scope | Status |
| --- | --- | --- | --- |
| 10 | **App shell** | Temple design system in light and dark, 5-tab navigation (Home, Explore, Passport, Yatra, Profile), API client | ⬜ |
| 11 | **Explorer + temple profile** | Search by name/deity/city/state, nearby, filters, full temple profile screen | ⬜ |
| 12 | **Passport** | Visited/unvisited state, manual check-in, digital stamps, collections | ⬜ |
| 13 | **Photo Stamp** | Upload visit photo, generate temple-themed memory card, save original and stamp separately, share | ⬜ |
| 14 | **Favourites + basic Yatra planner** | Saved temples, multi-temple itinerary by days and route | ⬜ |
| 15 | **Languages: EN / TE / HI** | Localisation across app, admin and temple portal; alternate temple names and spellings | ⬜ |

### Later phases

Community submissions and moderation · GPS and QR visit verification ·
advanced Yatra planner · festival calendar and notifications · Family
Passport · certificates and achievements · offline trip packs · hotel and
travel partnerships · Temple Admin SaaS · official QR Passport network ·
authorized puja/seva/prasadam · AI assistant grounded in verified temple data.

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
