# Public API v1

Read-only endpoints consumed by the Flutter app. Everything is public and
unauthenticated. User accounts, check-ins and stamps arrive with the Passport
slice and will be authenticated separately.

**Base path:** `/api/v1`
**Rate limit:** 60 requests per minute per IP.

## Why the version is in the path

The app ships to devices we cannot update on demand. A released client has to
keep working against a frozen contract while v2 evolves beside it. Breaking
changes go in `/api/v2`; `/api/v1` stays as it is.

## What is visible

Only temples with status `published` are ever returned, from any endpoint, by
any combination of parameters. Draft and in-review records are invisible here,
and requesting one by slug returns **404**, not 403 — a 403 would confirm that
a draft with that slug exists.

Soft-deleted temples are likewise unreachable.

---

## `GET /api/v1/temples`

Paginated list.

| Parameter | Type | Notes |
| --- | --- | --- |
| `q` | string | Matches temple name, city and **alternate/local names**, so `Tirupati` finds Sri Venkateswara Swamy Temple |
| `deity` | slug | e.g. `shiva` |
| `category` | slug | e.g. `jyotirlinga` |
| `state` | slug | e.g. `telangana` |
| `district` | slug | |
| `verified` | boolean | Restricts to `verified` and `official` records only |
| `lat`, `lng` | float | **Both required together.** Supplying one without the other is a 422 |
| `radius` | float | Kilometres, default 50, max 2000 |
| `sort` | string | `name`, `-name`, `recent`, `distance` |
| `per_page` | int | Default 20, max 50 |

When `lat`/`lng` are present, each result gains `distance_km` and results are
ordered nearest-first. Temples without coordinates are excluded from a
proximity search rather than sorted arbitrarily.

```
GET /api/v1/temples?lat=17.3850&lng=78.4867&radius=200
```

## `GET /api/v1/temples/{slug}`

Full profile: identity, alternate names, deity, categories, location, about,
visitor rules, contact, trust, timings, pujas, photos, upcoming closures and
facilities.

## `GET /api/v1/deities`
## `GET /api/v1/categories`
## `GET /api/v1/states`

`?with_districts=1` nests districts under each state.

## `GET /api/v1/facilities`

Counts on these listings include **published temples only**, so tapping through
never shows fewer temples than the badge promised.

---

## Two contracts the client must honour

These are not stylistic preferences. They come from sections 13 and 20 of the
project plan, and getting them wrong misleads a devotee.

### 1. An unpriced puja is not a free puja

```json
"fee": { "is_free": false, "amount": null, "label": "No published price" }
```

`amount: null` means *the temple publishes no price*. It does **not** mean free.
Render `label` rather than inventing text from `amount`. A devotee told
"Free" who then finds a charge at the counter was misled by us.

`is_free: true` is the only thing that means free.

### 2. Only `booking.is_official` may be presented as official

```json
"booking": {
  "url": "https://reseller.example",
  "is_official": false,
  "label": "Third-party link — not the official booking route"
}
```

A URL that merely looks official is not. `is_official` is the single field that
decides whether the app may present a link as the temple's own booking route,
and it is only ever true when an editor has explicitly confirmed it.

### Trust levels travel with every record

`trust.level` is one of `unverified`, `community`, `verified`, `official`.
These must stay visually distinct in the app — that is the whole point of
carrying them to the device. `trust.is_stale` marks a record not re-checked
within a year.

---

## Errors

Validation failures return **422** with Laravel's standard shape:

```json
{
  "message": "Both lat and lng are required for a nearby search.",
  "errors": { "lng": ["Both lat and lng are required for a nearby search."] }
}
```

Unknown or unpublished temples return **404**. Exceeding the rate limit returns
**429** with a `Retry-After` header.


---

## Languages

Every endpoint answers in one language, chosen in this order: `?lang=` (or
`?locale=`), then the signed-in devotee's stored preference, then
`Accept-Language`, then English. An unrecognised value falls back rather than
erroring.

The response carries `Content-Language` and `Vary: Accept-Language` — without
the latter, a cache in front of the API serves the Telugu response to the next
devotee who asked for Tamil. Each payload also repeats the language it was
served in, because a client that cannot tell has no way to decide whether to
render its own fallback.

`Accept-Language` is parsed for its highest-quality supported tag, not the
first one: a device sending `ta;q=0.5, en;q=1.0` gets English.

```
GET /api/v1/languages
GET /api/v1/temples?lang=te
GET /api/v1/temples/{slug}       Accept-Language: hi-IN,en;q=0.8
```

A field with no translation falls back to its English value, so a partly
translated temple renders as a usable card rather than half-blank. **Only
reviewed translations are served.**

## Devotee endpoints

All of these need `Authorization: Bearer <token>` on the `devotee` guard, and
all are scoped to the signed-in devotee in the query — never by an id supplied
by the caller. Asking for something that is not yours returns **404, not 403**:
confirming that an id exists says something about another devotee's pilgrimage.

Optional headers, recorded against each sign-in for the analytics screen:
`X-Platform` (e.g. `android`), `X-App-Version`.

### Passport

```
GET    /api/v1/me/passport                    stamps, counts, circuit progress
GET    /api/v1/me/visits
POST   /api/v1/temples/{slug}/visits
DELETE /api/v1/me/visits/{id}
```

`POST` body: `method` (`manual` | `gps` | `qr`), `visited_on` (not in the
future), `visited_at` (`HH:MM`), `latitude`, `longitude`, `note`, `is_public`.

A `gps` check-in must carry coordinates, and latitude and longitude must
arrive together. A `gps` or `qr` check-in within the configured radius
(`check_in_radius_metres`, default 500m) is **verified** and counts as a
stamp; `manual` never is, whatever coordinates it sends.

Recording a visit also closes the matching stop on any of the devotee's
upcoming trips.

`circuits` in the passport response gives `collected`, `recorded` (how many of
that circuit are in the database) and `total` (how many exist), so the app can
say "6 of 8 recorded, 12 in all" rather than sending someone hunting for
temples it cannot show them.

### Photo Stamp

```
GET    /api/v1/me/photos
POST   /api/v1/temples/{slug}/photos      multipart; 20/min
DELETE /api/v1/me/photos/{id}
```

Fields: `photo` (required image), `stamp` (the generated card, optional),
`visit_id`, `caption`, `is_public`. Both files are kept separately.

Every upload starts as `pending`. The response returns the moderation status
and any `moderation_note`, because silence reads as failure and a devotee who
sees nothing happen will upload it again. `is_visible_to_others` is true only
when the photo is approved **and** the devotee chose to share it.

### Memories

```
GET    /api/v1/me/memories
POST   /api/v1/me/memories
PATCH  /api/v1/me/memories/{id}
DELETE /api/v1/me/memories/{id}
```

Fields: `title`, `body`, `happened_on` (not in the future), `temple_id`,
`devotee_visit_id` (must be the devotee's own), `is_private`.

`is_private` defaults to true, and a `PATCH` that omits it leaves the current
value alone — someone fixing a typo is not consenting to publish their prayers.

### Yatra planner

```
GET    /api/v1/me/yatras
POST   /api/v1/me/yatras
GET    /api/v1/me/yatras/{id}
PATCH  /api/v1/me/yatras/{id}
DELETE /api/v1/me/yatras/{id}
PUT    /api/v1/me/yatras/{id}/temples/{slug}     add or move a stop
DELETE /api/v1/me/yatras/{id}/temples/{slug}
```

Fields: `title`, `description`, `status` (`planning` | `confirmed` |
`in_progress` | `completed` | `abandoned`), `starts_on`, `ends_on` (not before
the start), `party_size`, `is_public`.

Adding a stop takes `day_number`, `planned_on` and `note`. It is idempotent —
a retried request on a flaky connection must not fail on the unique index —
and a new stop is appended to the end of its day rather than colliding at zero.
