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
| `featured` | boolean | Restricts to temples editors marked as famous |
| `lat`, `lng` | float | **Both required together.** Supplying one without the other is a 422 |
| `radius` | float | Kilometres, default 50, max 2000 |
| `sort` | string | `name`, `-name`, `recent`, `distance`, `featured` (famous first, then by name) |
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

Each deity carries `image_url` (null when none) and `image_credit`. Temple
summaries and details carry the deity's `image_url` too. Editors upload
images on the deity's page, or fetch public-domain paintings from Wikimedia
Commons with **Find images for all** / **Find a public-domain image**, or
`php artisan deities:fetch-images` (`--deity=shiva`, `--replace`,
`--dry-run`). Only files Commons marks public domain are taken; an editor's
own upload is never replaced.

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

`is_featured` (on list and detail) marks a famous temple. It is a curation
choice, not a trust claim: a featured temple can still be community level.
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

`PATCH /api/v1/me` also takes `gender`: `male`, `female`, `other`,
`prefer_not_to_say`, or `null`. The profile returns `gender` and
`gender_label`.

### Profile photo

```
POST   /api/v1/me/avatar      multipart, field `avatar`
DELETE /api/v1/me/avatar
```

JPEG, PNG or WebP up to 4 MB. Its own endpoint rather than a field on
`PATCH /api/v1/me`, because that one is JSON and a file is multipart — and
because a storage path is not something a client should be able to hand us as
a string.

Uploading replaces whatever was there and deletes the old file. Both verbs
return the whole profile, so `avatar_url` comes back in the same round trip.
`avatar_url` is `null` when there is none; the app draws initials in that
case, as the admin does.

### Passport

```
GET    /api/v1/me/passport                    stamps, counts, circuit progress
GET    /api/v1/me/visits
POST   /api/v1/temples/{slug}/visits
DELETE /api/v1/me/visits/{id}
```

`POST` body: `method` (`manual` | `gps` | `qr`), `visited_on` (not in the
future anywhere on Earth, so a device already on tomorrow is not refused),
`visited_at` (`HH:MM`), `latitude`, `longitude`, `note`, `is_public`, and for
a `qr` check-in `qr_code` (the scanned text).

A `gps` check-in must carry coordinates, and latitude and longitude must
arrive together. A `gps` or `qr` check-in within the configured radius
(`check_in_radius_metres`, default 500m) is **verified** and counts as a
stamp; `manual` never is, whatever coordinates it sends.

A `qr` check-in that sends `qr_code` is verified when, and only when, the code
is a genuine signed code for **this** temple, however far the phone's GPS
says it is. Without `qr_code` it falls back to the radius rule.

### Temple check-in codes

```
POST /api/v1/qr/verify     { "code": "<scanned text>" }   open, no token
```

Returns `valid`, `reason` and `temple` (`id`, `slug`, `name`, `city`, or
`null`). A code is `https://<site>/temples/<slug>/checkin?s=<signature>`,
signed with the application key, so a code printed by anyone else does not
verify. Opened in a phone camera, the same URL shows a page saying whether
it is genuine. Editors print a temple's code from its edit page in the admin
(**Check-in QR code** / **Download QR**) and check any code at
**Temples → Verify QR code**.

Rotating `APP_KEY` invalidates every printed code.

Recording a visit also closes the matching stop on any of the devotee's
upcoming trips.

`circuits` in the passport response gives `collected`, `recorded` (how many of
that circuit are in the database) and `total` (how many exist), so the app can
say "6 of 8 recorded, 12 in all" rather than sending someone hunting for
temples it cannot show them.

### Passport codes

A devotee's own passport QR carries `passport_url` from `GET /me`: a link
ending in a random 20-character code. It is never the account id.

```
GET  /api/v1/me/passport/qr          { data: { code, url } }        token
POST /api/v1/me/passport/qr/reset    { data: { code, url } }        token, 6/min
GET  /api/v1/passports/{code}        someone else's passport          open, 60/min
```

Resetting retires the old code at once: `GET /passports/{old}` is a 404.

`GET /passports/{code}` returns `name`, `avatar_url`, `home_state`,
`joined_at`, the counts (`stamps`, `temples_visited`, `visits_recorded`,
`states_covered`) and `visits[]` (temple, `visited_on`, `method`,
`is_verified`). Only visits the devotee left public are listed and counted.
No email, phone, date of birth, note or photo is ever included. A phone
camera without the app opens the same view at `/passport/{code}`.

A visit whose `method.value` is `staff` was marked at the temple counter by
the temple's own staff after scanning this code; it is verified. The app
cannot send `method: staff` itself (422).

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

Memory photos use the same endpoint with `kind=memory` and a `visit_id`
(required). A visit keeps three; the fourth is a 422. They are always
private whatever `is_public` says, never enter moderation, and come back
from `GET /me/photos` with `kind: "memory"`. Every photo now carries `kind`
(`stamp` or `memory`).

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


## Support and reports

Filing needs no account. A report behind a sign-in wall is a report most
people will not file, and the listing with the wrong timings goes on sending
devotees to a closed gate.

```
GET  /api/v1/support/options          categories, their descriptions, reportable types
POST /api/v1/support                  10/min
```

`POST` body: `subject`, `body`, `category`, and `name` (required only when
nobody is signed in) plus `email`. To make it a **report**, add `about_type`
(`temple` | `event` | `puja` | `photo`) and `about_id`; the type is an
allow-list, so a ticket cannot be aimed at an arbitrary model.

The response carries a `reference` (`TP-XXXXXX`) to quote. A ticket in the
`inappropriate_content` category is filed **urgent** automatically.

A signed-in devotee's own tickets:

```
GET  /api/v1/me/support
GET  /api/v1/me/support/{reference}
POST /api/v1/me/support/{reference}/replies      20/min
```

`messages` contains **only replies** — internal staff notes are on a separate
relation and cannot reach this response. Replying reopens a resolved ticket,
because a resolution the reporter did not accept is not one.

## Mantras and devotional media

`GET /api/v1/temples/{slug}`:

```json
"mantra": {
  "text": "ॐ नमः शिवाय",
  "transliteration": "Om Namah Shivaya",
  "meaning": "Salutations to Shiva.",
  "is_own": false,
  "audio": { ...a media object, or null... }
},
"devotional_media": [ ... ]
```

Each field falls back to the temple's **deity** independently — a temple with
its own verse but no recording shows its verse and plays its deity's chant.
Falling back wholesale would put a chant of one verse under another.

`is_own` says whether the verse shown is the temple's own. The app should
render the two differently: "the mantra of this temple" and "the mantra of its
deity" are not the same claim about the place a devotee is standing in.

`mantra` is `null` when there is no mantra anywhere, and `audio` is `null`
when there is no recording — which is the normal case. Render the text alone
rather than an empty player.

> **Renamed:** `is_temple_specific` became `is_own` when the same shape started
> serving days and deities as well as temples, where "temple specific" is
> meaningless. It shipped one release earlier and no client reads it.

The same object is on `GET /api/v1/days/{weekday}` and `/today` as
`mantra_audio`. The flat `mantra` and `mantra_transliteration` fields stay
there alongside it for the app already reading them; they go in v2.

### Knowing how to play it

Every media object — the mantra's recording and everything in
`devotional_media` — carries a `playback` block:

```json
"playback": {
  "kind": "youtube",
  "is_playable": false,
  "needs_embed": true,
  "embed_url": "https://www.youtube.com/embed/dQw4w9WgXcQ",
  "youtube_id": "dQw4w9WgXcQ"
}
```

`kind` is one of `audio`, `video`, `youtube`, `vimeo`, `link`:

| kind | what to do |
| --- | --- |
| `audio`, `video` | put `url` straight into a player — `is_playable` is true |
| `youtube`, `vimeo` | embed `embed_url`; if it is `null`, open `url` instead |
| `link` | nothing to play; open `url` in a browser |

`source_type` only says whether we host the file, which is not enough to
choose a player: a YouTube page inside an `<audio>` element plays nothing.
The classification is done server-side so a released build does not have to
pattern-match URLs it was compiled before seeing.

`embed_url` is `null` for a YouTube channel or search link — there is no
video in it. That is the signal to fall back to opening `url`.

`devotional_media` is the temple's own media followed by its deity's — most
specific first. The licence rule is unchanged: a song or video with no
recorded licence is never served, whatever it hangs off.

`GET /api/v1/today` and `/days/{weekday}` now include the deity's
`image_url`, `mantra` and `mantra_meaning`, and a day's `mantra` falls back
to its deity's the same way.

## App control

```
GET /api/v1/app/config?platform=android|ios|web&version=0.6.0      open
```

Read by the app on every launch and on return from the background. Every
value is set in the admin panel under **App** and **Monetisation**.

| Key | Meaning |
| --- | --- |
| `maintenance.enabled`, `title`, `message`, `until` | Cover the app with a notice |
| `update.available`, `update.required`, `latest_version`, `min_version`, `store_url`, `title`, `message` | Below `min_version` the app is blocked until updated; below `latest_version` an update is offered once |
| `auth.password`, `auth.google.{enabled, server_client_id, ios_client_id}`, `auth.apple.enabled` | Which sign-in buttons to show |
| `push.enabled`, `push.firebase` | Public Firebase ids for this platform (`project_id`, `api_key`, `app_id`, `messaging_sender_id`, `ios_bundle_id`). The app starts Firebase from these, so no google-services file is built in |
| `ads.enabled`, `network` (`admob` \| `applovin_max`), `test_mode`, `units.{native, banner}`, `list_interval`, `placements.{temple_detail, explore, home, day_page}` | False for a devotee whose plan removes ads (send the token) and always on the web |
| `payments.enabled`, `available_elsewhere`, `gateways[]`, `default_gateway` | Whether plans can be bought on this platform |

## Google and Apple sign-in

```
POST /api/v1/auth/google   { "id_token": "…" }                                  10/min
POST /api/v1/auth/apple    { "identity_token": "…", "nonce": "raw", "name": "…" } 10/min
```

The token is verified here against the provider's published keys, issuer and
the client ids set under **App → Sign-in methods**. An account is matched by
the provider's subject id, then by a *verified* email (which links the
provider to that account), otherwise created. The response is the same as
`/auth/login`, plus `created`. `403` when the method is switched off; `422`
for a token that is expired, forged or issued to another app.

`GET /me` now also returns `sign_in_methods`, `home_state_id`,
`entitlements` (`no_ads`, `memory_photos_per_visit`, `premium_passport`) and
`subscription` (`plan`, `ends_at`) while one is active.

## Notifications

```
GET  /api/v1/notifications?platform=android        open; a token adds personal ones and is_read
POST /api/v1/devices          { token, platform, app_version?, locale? }   open, 20/min
POST /api/v1/devices/forget   { token }
POST /api/v1/me/notifications/{id}/read
POST /api/v1/me/notifications/read-all   { platform? }
```

Sent from **App → Notifications** to everyone, one platform, followers of a
temple, a home state or one devotee. Push goes through Firebase topics the
app subscribes to — `all`, `android`/`ios`, `temple-{id}`, `state-{id}` — or
to one devotee's registered tokens. A push carries `notification_id`,
`link_type` (`none`, `temple`, `day`, `screen`, `url`) and `link_value`.

## Plans and payments

```
GET  /api/v1/plans?platform=android                  open
GET  /api/v1/me/subscription
POST /api/v1/me/checkout   { plan, gateway?, platform, mode? }   10/min
GET  /api/v1/me/payments/{id}                        asks the gateway if still open
POST /api/v1/me/payments/{id}/confirm  { razorpay_* }     20/min
POST /api/v1/payments/webhook/{razorpay|phonepe|cashfree|payu}
```

`checkout` answers with a signed `checkout_url` (30 minutes) and a `done_url`.
The app opens the first in its in-app browser; the page runs the gateway's own
checkout, the gateway returns to `/pay/{id}/return/{gateway}`, the server
confirms with the gateway and lands on `done_url`, where the browser closes.
The app then polls `me/payments/{id}`.

With `mode: "sdk"`, Razorpay, Cashfree and PhonePe also answer with `sdk`: what the
gateway's native SDK needs to open its own payment sheet in the app (Razorpay:
`key`, `order_id`, `amount_paise`, `prefill`; Cashfree: `session_id`,
`order_id`, `environment`; PhonePe: `merchant_id`, `order_id`, `token`,
`environment`, from PhonePe's SDK-order API, which needs the merchant id set
in Settings → Payments). When a native gateway cannot start, `sdk` is null
and `sdk_error` says why, and the app shows that instead of a web page. The
app hands the SDK's result to `confirm`:
Razorpay's is checked by its signature (and must be for this payment's
order), Cashfree's and PhonePe's by asking the gateway. `sdk` is null for
PayU, which pays at `checkout_url`; a Cashfree order started
by the SDK is reused by the web page rather than created twice.

The price is always the plan's, set on
the server; a plan switches on only when the gateway itself confirms, once,
however many times the return and the webhook arrive.
