<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The website's policy and information pages as first written.
 *
 * Seeded once, then edited in Admin → Website → Pages. "Restore the default
 * text" there brings back the version below. Words in braces are filled in
 * when the page is shown, from Admin → Website → Business details, so the
 * support address or the business name is changed in one place:
 *
 *   {app} {website} {email} {business} {address} {grievance_officer} {courts}
 *
 * A starting point written for an Indian temple-information and booking
 * app; have it read by a lawyer before relying on it.
 */
final class DefaultPages
{
    /** @return array<string, array{title: string, summary: string, footer: bool, sort: int, body: string}> */
    public static function all(): array
    {
        return [
            'about-us' => [
                'title' => 'About us',
                'summary' => 'What {app} is: a companion for temple visits across India.',
                'footer' => true,
                'sort' => 1,
                'body' => <<<'MD'
{app} is a companion for visiting Hindu temples across India. It brings together darshan timings, pujas and sevas, dress codes, festivals and directions, so a devotee can plan a visit with confidence.

With the app you can:

- find temples by place, deity or name, and see how to reach them;
- book pujas and sevas that temples offer through {app};
- keep a temple passport of the temples you have visited, with photos and memories;
- plan a yatra, listen to bhajans and mantras, and get festival reminders.

Temple information comes from temples themselves, from our editors and from devotees, and is checked before it is published. Timings and rules do change, so please confirm with the temple before travelling.

{app} is run by {business}. Write to us at [{email}](mailto:{email}).
MD,
            ],

            'contact-us' => [
                'title' => 'Contact us',
                'summary' => 'How to reach {app} support for help with the app, bookings, payments or your account.',
                'footer' => true,
                'sort' => 2,
                'body' => <<<'MD'
We are happy to help with the app, a booking or payment, your account, or a correction to a temple's details.

- **Email:** [{email}](mailto:{email}). We reply within 2 working days.
- **In the app:** Profile → Help & support, which lets you follow your request.

Please include your booking reference or payment id when writing about a booking or payment, and the email or phone number on your account.

**Business:** {business}<br>
**Address:** {address}

For a complaint about how your personal data is handled, see the Grievance Officer section of our [Privacy policy](/privacy-policy).
MD,
            ],

            'privacy-policy' => [
                'title' => 'Privacy policy',
                'summary' => 'What personal data {app} collects, why, who it is shared with, how long it is kept, and your rights.',
                'footer' => true,
                'sort' => 10,
                'body' => <<<'MD'
This policy explains how {business} ("we", "us") handles personal data when you use the {app} app and the website {website} (together, "{app}"). It is written to meet India's Digital Personal Data Protection Act, 2023 and the Information Technology Act, 2000 and its rules.

## What we collect

**Account details.** Your name, and the email address and/or phone number you sign up with. If you sign in with Google or Apple, we receive your name, email address and an account identifier from them; we never see your Google or Apple password. Optional profile details you add: photo, home state, date of birth, gender, preferred language.

**What you do in {app}.** Temples you save, like or follow; your temple visits (check-ins), passport stamps, photos and memories; reviews you write; yatras you plan; reminders you set; support requests.

**Bookings and payments.** For a puja or seva booking: the devotee name, phone number, gotram, nakshatram and notes you give, the temple, date and number of people. For any payment: the amount, what it was for, its status, and the reference ids from the payment gateway. Card, UPI and bank details are entered on the payment gateway's own page or app (Razorpay, Cashfree or PhonePe) and are never seen or stored by us.

**Location.** Only when you allow it, to show nearby temples and to check that you are at a temple when you check in. We do not track your location in the background.

**Temple teams ({app} Trust app and Temple Portal).** If you run a temple on {app}: your name, email, phone number and password; the temples you manage and your role there; your location when you ask to manage a temple or register a new one, to check that you are at the temple (used only at that moment); photos and details you add for the temple; and what you do at the counter (bookings and passports you scan). If the temple takes payments, the owner also gives the bank account or UPI id that settlements are paid to, and, to verify who represents the temple, their name and Aadhaar number, photos of the Aadhaar card, a photo of themselves and a document proving the temple is theirs to represent. These documents are stored privately, seen only by our verification team, and used only to approve payouts and meet legal duties; only the last four digits of the Aadhaar number are ever shown.

**Device and usage information.** App version, device type and operating system, language, a push-notification token, sign-in times, crash information, and which screens and features are used (through Google Analytics for Firebase, when switched on). This is used to keep {app} working and to improve it, not to identify you.

**Advertising.** If {app} shows ads, the ad network (Google AdMob or AppLovin) may use your device's advertising identifier to show and measure ads. You can reset it or opt out of personalised ads in your phone's settings. Premium plans remove ads.

## Why we use it

- To run your account and sign you in.
- To provide the features you use: temple information, check-ins, your passport, photos, memories, reviews and reminders.
- To take bookings, pass the booking details to the temple that performs the puja or seva, and take payment for them.
- To send notifications you have asked for, such as booking updates and festival reminders.
- To answer support requests and keep {app} safe, including preventing fraud and misuse.
- To understand how {app} is used and improve it.
- To meet legal obligations, such as keeping payment records.

We rely on your consent, which you give by creating an account and using these features, and which you can withdraw at any time by deleting your account; and, for payment records, on our legal obligations.

## Who we share it with

We do not sell personal data. We share it only as needed to provide {app}:

- **Temples**, for your bookings: the booking details above, so they can perform the puja or seva and recognise you on the day.
- **Payment gateways** (Razorpay, Cashfree, PhonePe), to take payments.
- **Service providers** who host or run parts of {app} for us: our hosting provider, DigitalOcean (photo storage), Google Firebase (push notifications and analytics), email delivery providers, and ad networks where ads are shown.
- **Other devotees**, only for what you choose to make public: your reviews (with your name) and photos you submit for a temple's public gallery.
- **Authorities**, when the law requires it.

Some providers store data outside India; they are bound to protect it.

## How long we keep it

We keep your data while your account is open. When you delete your account, your profile, check-ins, photos, memories, reviews, saved temples, follows and devices are deleted within 30 days. Records of bookings and payments are kept, without being used for anything else, for as long as tax and accounting law requires (currently up to 8 years). Backups are overwritten within 90 days.

## Your rights

You can see and correct your details in the app (Profile → Edit profile), download your passport, and delete your account at any time in the app (Profile → Delete account) or [on the website](/account-deletion). You may also ask us for a summary of the data we hold about you, to correct or erase it, or to nominate someone to act for you, by writing to [{email}](mailto:{email}).

## Children

{app} is meant for people aged 18 and over. Younger devotees should use it with a parent or guardian, who is responsible for their use. We do not knowingly collect data from children without a parent's or guardian's consent.

## Security

Data is sent over encrypted connections, passwords are stored only as secure hashes, and access to personal data is limited to people who need it. No system is perfectly secure; if a breach affects you, we will tell you and the authorities as the law requires.

## Grievance Officer

If you have a concern about your personal data, write to {grievance_officer} at [{email}](mailto:{email}). We acknowledge complaints within 24 hours and resolve them within 15 days. If you are not satisfied, you may complain to the Data Protection Board of India.

## Changes

We will post any changes here and update the date below; for significant changes we will also tell you in the app.
MD,
            ],

            'terms-and-conditions' => [
                'title' => 'Terms and conditions',
                'summary' => 'The terms for using the {app} app and website, bookings, payments and content.',
                'footer' => true,
                'sort' => 11,
                'body' => <<<'MD'
These terms are an agreement between you and {business} ("we", "us") for the use of the {app} app and the website {website} ("{app}"). By using {app} you accept them. If you do not, please do not use {app}.

## Using {app}

- You must be 18 or over, or use {app} with a parent or guardian.
- Keep your sign-in details to yourself; you are responsible for what is done with your account.
- Give true details, especially in bookings: temples rely on them.
- Do not misuse {app}: no false check-ins, fake reviews, spam, attempts to break or overload it, or copying its content in bulk.

## Temple information

Temple details, timings, rules and festival dates come from temples, our editors and devotees. We work to keep them accurate, but they can change without notice and we cannot guarantee them. Please confirm with the temple before travelling. {app} is not run by, and does not speak for, any temple unless that is stated on the temple's page.

## Bookings of pujas and sevas

- A booking is a request to the temple to perform the puja or seva on the chosen date. The temple performs it; {app} takes the booking and payment and passes them on.
- The price shown is set by the temple and includes what the temple has asked for. Any convenience fee is shown before you pay.
- A booking is confirmed only when the payment succeeds; you then get a booking QR code to show at the temple. A booking left unpaid is not confirmed.
- Arrive on time and follow the temple's rules and dress code. The temple may refuse entry to anyone who does not.
- Cancellations and refunds follow our [Refund and cancellation policy](/refund-and-cancellation).

## Premium plans

Premium plans are paid in advance for the period shown and unlock the benefits listed when you buy. They do not renew automatically; you choose whether to buy again. Plans are not refundable once active, except as set out in the refund policy or required by law.

## Payments

Payments are processed by Razorpay, Cashfree or PhonePe, under their own terms. We never see your card, UPI or bank details. Prices are in Indian rupees.

## Your content

You keep the rights to the photos, reviews and memories you add. By making something public (a review, a photo for a temple's gallery) you allow us to show it in {app} and on the website. Do not post anything you do not have the right to, or anything offensive, false or unlawful; see our [Community guidelines](/community-guidelines). We may remove content and suspend accounts that break these terms.

## Our content

The {app} name, logo, design and the content we create belong to us. You may use {app} for your own, non-commercial use.

## Availability and liability

We provide {app} as it is, and may change, pause or stop features. To the extent the law allows, we are not liable for indirect losses, or for losses arising from temple timings, closures or decisions outside our control. Our total liability for any claim is limited to the amount you paid us for the booking or plan concerned. Nothing here limits your rights under the Consumer Protection Act, 2019.

## Ending your account

You may delete your account at any time (see [Account deletion](/account-deletion)). We may suspend or close accounts that break these terms.

## Law and disputes

These terms are governed by the laws of India. Please write to [{email}](mailto:{email}) first so we can try to resolve any problem. Disputes are subject to {courts}.

## Changes

We may update these terms; the date below shows the latest version. Continuing to use {app} after a change means you accept it.
MD,
            ],

            'refund-and-cancellation' => [
                'title' => 'Refund and cancellation policy',
                'summary' => 'How to cancel a puja or seva booking or a premium plan on {app}, and when and how refunds are made.',
                'footer' => true,
                'sort' => 12,
                'body' => <<<'MD'
This policy covers payments made in {app} for puja and seva bookings and for premium plans.

## Cancelling a puja or seva booking

You can cancel a booking in the app until the booked day (My seva bookings → the booking → Cancel).

- **Cancelled 24 hours or more before the booked date:** the full amount is refunded.
- **Cancelled less than 24 hours before:** the temple may already have made arrangements, so a refund is at the temple's discretion. We ask the temple for you.
- **After the date, or if you did not attend:** no refund.

## When the temple cancels

If a temple cancels a puja or seva, or the temple is closed on the booked day (for example for an eclipse or an unforeseen event), you get a full refund, or, if you prefer, the booking moved to another date.

## Failed or duplicate payments

If money was taken but the booking or plan was not confirmed, or you were charged twice, the amount is refunded automatically, usually within 5–7 working days. If it is not, write to us with the payment id.

## Premium plans

- Premium plans can be cancelled for a full refund within 48 hours of purchase if none of the plan's benefits have been used.
- After that, plans are not refundable, but stay active until the end of the period you paid for.
- Plans do not renew automatically, so there is nothing to cancel for the next period.

## How refunds are paid

Refunds go back to the original payment method (UPI, card, net banking or wallet) through the same payment gateway. Once we approve a refund it is processed within 2 working days; your bank may take a further 5–7 working days to show it.

## Asking for a refund

Write to [{email}](mailto:{email}) with your booking reference or payment id and the reason. We reply within 2 working days.
MD,
            ],

            'shipping-and-delivery' => [
                'title' => 'Shipping and delivery policy',
                'summary' => 'How bookings and premium plans bought on {app} are delivered.',
                'footer' => true,
                'sort' => 13,
                'body' => <<<'MD'
{app} sells services, not physical goods, so nothing is shipped.

- **Puja and seva bookings** are confirmed instantly once payment succeeds. The confirmation and a booking QR code appear in the app (My seva bookings), and the puja or seva is performed at the temple on the booked date.
- **Premium plans** are active as soon as payment succeeds, on the account that bought them.

If a temple offers prasadam by post as part of a seva, this is stated on the seva, and the temple sends it; delivery times are given there and usually take 7–15 days within India.

If your booking or plan does not appear within an hour of a successful payment, write to [{email}](mailto:{email}) with the payment id.
MD,
            ],

            'account-deletion' => [
                'title' => 'Delete your account',
                'summary' => 'How to delete your {app} account and data, in the app or from this page, and what is kept.',
                'footer' => true,
                'sort' => 20,
                'body' => <<<'MD'
You can delete your {app} account at any time.

## In the app

Open **Profile → Delete account**, and confirm. You are signed out and your account is deleted straight away.

## Without the app

Fill in the form below with the email or phone number on your account, or write to [{email}](mailto:{email}) from your registered email. We confirm that the request is yours and delete the account within 7 days.

## What is deleted

- Your profile: name, email, phone, photo and other details.
- Your check-ins, passport stamps, photos, memories, reviews, yatras, saved and followed temples.
- Your devices and notification settings.

## What is kept

Records of puja bookings and payments are kept, as tax and accounting law requires (up to 8 years), and are not used for anything else. Bookings for a future date stay with the temple so the puja or seva can still be performed; cancel them first if you want a refund.

## Temple team accounts ({app} Trust app and Temple Portal)

In the {app} Trust app, open **Account → Delete account**, enter your password and confirm. Or use the form above, or write to [{email}](mailto:{email}). Your name, email, phone and password are erased and your access to every temple ends. The temple's own information (timings, sevas, events, photos) and its booking, donation and settlement records stay with the temple.

Deletion cannot be undone. Premium plans end when the account is deleted and are not refunded.
MD,
            ],

            'community-guidelines' => [
                'title' => 'Community guidelines',
                'summary' => 'What can be posted in reviews, photos and temple suggestions on {app}.',
                'footer' => true,
                'sort' => 21,
                'body' => <<<'MD'
{app} is a place of devotion. Please keep reviews, photos, memories and temple suggestions respectful.

- Write about your own visit, honestly. No fake reviews, and no reviews written for payment.
- Respect every faith, tradition, community and person. No hateful, abusive or obscene content.
- Post only photos you took, and respect the temple's rules: many do not allow photography of the deity or inside the sanctum.
- Do not share other people's personal details, or photos of people who have not agreed to it.
- No advertising, spam or links to unrelated sites.

Reviews and photos may be checked before they appear. We remove content that breaks these guidelines and may suspend accounts that keep doing so. To report something, use Report in the app or write to [{email}](mailto:{email}).
MD,
            ],

            'disclaimer' => [
                'title' => 'Disclaimer',
                'summary' => 'Limits of the temple information, timings and content on {app}.',
                'footer' => true,
                'sort' => 22,
                'body' => <<<'MD'
Temple information on {app}, including timings, rules, dress codes, festival dates and directions, is provided in good faith from temples, our editors and devotees. It can change without notice and may contain errors. Please confirm with the temple before travelling.

Devotional content such as mantras, bhajans, stories and the significance of temples and festivals is shared for devotion and general knowledge, and represents traditions and beliefs that can differ from place to place.

{app} is an independent service. Unless a temple's page says so, it is not run by or affiliated with any temple, trust or government body, and the temples' names and images belong to them.
MD,
            ],
        ];
    }

    /** One page's default body, as HTML. */
    public static function html(string $slug): ?string
    {
        $page = self::all()[$slug] ?? null;

        return $page === null ? null : Str::markdown($page['body'], ['html_input' => 'allow']);
    }
}
