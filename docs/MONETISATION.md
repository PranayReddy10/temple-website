# Payments, subscriptions and ads — how they work and what comes next

## What is live now

| Piece | Where it is managed |
| --- | --- |
| Plans (price, length, what they include) | Admin → Monetisation → Plans |
| Gateways: Razorpay, PhonePe, Cashfree, PayU | Admin → Monetisation → Payment gateways (keys stored encrypted) |
| Every payment, "check with gateway", "mark refunded" | Admin → Monetisation → Payments |
| Who holds a plan, granting one free, cancelling | Admin → Monetisation → Subscribers |
| Ads: network, units, placements, test mode | Admin → Monetisation → Ads |

What a plan can include today (each is a switch or number on the plan):

- **No ads** — every ad slot in the app disappears.
- **Memory photos per visit** — the free app keeps 3 with each visit; a plan can allow up to 50.
- **Gold edition passport** — a black-and-gold cover on the passport book.

### The payment flow

1. The app calls `POST /me/checkout` with the plan. The server creates a
   `payments` row priced from the plan (never from the app).
2. The app opens the signed checkout link in its in-app browser. UPI links on
   that page open the phone's UPI apps (GPay, PhonePe, Paytm).
3. The gateway sends the devotee back to `/pay/{id}/return/{gateway}`. The
   server asks the gateway how it went — Razorpay's signature, PhonePe's order
   status, Cashfree's order, PayU's reverse hash — and only then marks it paid.
4. The gateway's webhook does the same independently, so a devotee who closes
   the app mid-payment still gets the plan. An hourly job re-checks anything
   left open and closes payments abandoned for a day.
5. A plan bought while one is running starts when the current one ends.

Webhook URLs to paste into each gateway's dashboard are shown on the
Payment gateways page.

## Store rules — read before switching payments on

- **Google Play**: removing ads and extra app features are *digital goods*,
  which Play requires to be sold through Play Billing. In India, Play's
  **user choice billing** lets you offer your own gateway alongside Play
  Billing once you enrol in the programme (Play still takes a reduced fee).
  Until then, keep **Offer on Android** off in production, or enrol first.
- **Apple App Store**: digital subscriptions must use In-App Purchase. The
  **Offer on iPhone** switch is off by default; the app then lists the plans
  without a buy button and says only that they cannot be bought in the app
  yet. A plan bought elsewhere on the same account still works on iPhone
  (Apple's multiplatform-services rule), but the iOS app must not point
  people to the website to buy — outside the US storefront that is refused
  as steering.
- **Physical services are different**: puja/seva bookings, prasadam delivery,
  and donations to a temple are not digital goods and can use Razorpay,
  PhonePe, Cashfree or PayU directly on both stores.

## Next phase, in order

1. **Store billing for subscriptions**
   - Add `in_app_purchase` to the app; create the same plans as products in
     Play Console and App Store Connect, with the plan `code` as product id.
   - New endpoint `POST /me/purchases/verify {platform, product_id, token}`:
     the server verifies with Google Play Developer API / App Store Server API
     and starts the same `devotee_subscriptions` row. Gateways stay for the
     website and for Android user-choice billing.
   - App Store Server Notifications and Play Real-time Developer Notifications
     for renewals, refunds and cancellations.
2. **Auto-renewing plans** — Razorpay Subscriptions / PhonePe Autopay /
   Cashfree Subscriptions on the web, UPI Autopay mandates; `renews_at` and
   "cancel renewal" on the subscription.
3. **More passport** (plan benefits, each a key in `SubscriptionPlan::BENEFITS`):
   - Extra passport books / themes (gold, sandalwood, silk).
   - Printed passport and certificates shipped home (a physical product,
     so a normal gateway checkout).
   - Unlimited memory photos and full-resolution originals.
   - Family passport sync across family members' own phones.
4. **Temple payments (not subscriptions)** — puja/seva booking with the
   temple as the payee: Razorpay Route or Cashfree Easy Split so money goes to
   the temple's own account with a platform fee; GST invoices; refunds from the
   admin panel through each gateway's refund API.
5. **Coupons and trials** — `coupons` table (percent / fixed, usage limits,
   first-purchase only), a 7-day free trial flag on a plan.
6. **Reporting** — revenue by plan, gateway and month on the admin dashboard;
   CSV export for the accountant.

## Ads — placements and why

| Placement | Format | Why here |
| --- | --- | --- |
| Temple page, after the gallery and before contact | Native (medium, then small) | Long page, natural pause between sections; never above the temple's own facts or timings |
| Explore and search results | Native small, every N results (admin sets N) | Looks like a result card, clearly labelled "Ad" |
| Home, between sections | Native | Below the day's deity and quick actions, never in the header |
| Weekday pages | Native (off by default) | Devotional content; the admin can leave it off |

Never shown in: the passport book, check-in, QR scanning, sign-in, checkout,
maintenance/update screens. Every ad carries a "Remove ads" link to the plans.

Networks: **AdMob** or **AppLovin MAX** as the primary, chosen in the admin
panel. **Meta Audience Network** is bidding-only, so it is added as a
mediation/bidding source inside AdMob or MAX (plus its adapter in the app
build) rather than chosen on its own.

Before release: replace Google's **test** AdMob app ids in
`android/app/src/main/AndroidManifest.xml` and `ios/Runner/Info.plist`, turn
off **Test ads only**, add `app-ads.txt` to the website root, and set up the
UMP consent form in AdMob (required for users in the EEA/UK).
