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
