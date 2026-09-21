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
| `temple-website` | Laravel 11 REST API + Filament admin panel + public web |
| `temple-app` | Flutter — Android, iOS and Flutter Web from one codebase |

The Flutter app talks to this repo only through versioned REST endpoints
(`/api/v1/...`). Nothing in the app depends on Laravel specifics, so the
backend can migrate to Node/VPS in a later phase without an app release.

---

## Why Laravel 12 + MySQL

Hosting is **shared Hostinger**, which runs PHP 8.2+ and MySQL 8 on every plan
but cannot run persistent Node.js processes (that needs a VPS). The project
plan lists Laravel as an accepted backend, so we take the option that deploys
to the hosting we actually have.

Laravel **12**, not 11: the 11.x line is past security support and carries two
unpatched advisories (CRLF injection in the default email rule, signed-URL path
confusion) that were fixed only in 12.x. Laravel 12 needs PHP 8.2, which shared
Hostinger has; Laravel 13 needs PHP 8.3, which is less reliably available.

Geospatial "temples near me" uses MySQL spatial functions
(`ST_Distance_Sphere`) rather than PostGIS. This is comfortable well past
100,000 temple records — beyond the Phase 3 target.

---

## Delivery slices

Features ship one slice at a time. Each slice is independently testable and
leaves the system in a working state. **Nothing is built all at once.**

### Phase 1 — MVP foundation

| # | Slice | Scope | Status |
| --- | --- | --- | --- |
| 1 | **Admin auth + Temple CRUD** | Admin login, roles, temples table, deities, categories, states/districts, draft→published workflow, trust labelling, seed data | ✅ **Done** |
| 2 | **Temple media + timings** | Photo gallery on DigitalOcean Spaces with generated variants, opening/darshan/aarti timings, closure and special-hour overrides | ✅ **Done** |
| 3 | **Puja / Seva + facilities** | Published pujas with time, duration, eligibility, fee and official booking route; visitor rules; facilities including accessibility | ✅ **Done** |
| 4 | **Public REST API v1** | Read endpoints for the Flutter app: search, filter, nearby with real distance ordering, temple detail, deity/category/state/facility listings | ✅ **Done** |
| 5 | Flutter app shell | Temple-themed design system, 5-tab navigation (Home, Explore, Passport, Yatra, Profile), API client | ⬜ **Next** |
| 6 | Explorer + temple profile | Search by name/deity/city/state, nearby, filters, full temple profile screen | ⬜ |
| 7 | User accounts + Passport | Registration, visited/unvisited state, manual check-in, digital stamps, collections | ⬜ |
| 8 | Photo Stamp | Upload visit photo, generate temple-themed memory card, save original and stamp separately, share | ⬜ |
| 9 | Favourites + basic Yatra planner | Saved temples, multi-temple itinerary by days and route | ⬜ |
| 10 | Languages: EN / TE / HI | Localisation across app and admin, alternate temple names and spellings | ⬜ |

### Phase 2 — Scale and trust

Community submissions and moderation · GPS + QR visit verification ·
advanced Yatra planner · festival calendar and notifications · Family
Passport · certificates and achievements · offline trip packs · temple
authority verification · hotel and travel partnerships.

### Phase 3 — Full ecosystem

100,000+ temple records · Temple Admin SaaS · official QR Passport network ·
travel and accommodation integrations · authorized puja/seva/prasadam ·
AI assistant grounded in verified temple data · expanded Indian-language
support.

---

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
