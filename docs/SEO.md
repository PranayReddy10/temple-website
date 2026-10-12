# Getting darshansaathi.com to the top of Google

What the code already does, the keywords each page aims at, and the work
only people can do. Ranking is earned over months; there is no switch.

## What each page aims at

| Page | Searches it is built for | Title pattern |
|---|---|---|
| `/temples/{slug}` | "<temple> timings", "<temple> darshan timings today", "<temple> how to reach", "<temple> dress code" | `<Temple>, <Town>: Timings, Photos & How to Reach` |
| `/temples/{slug}/sevas` | "<temple> seva booking", "<temple> online puja booking", "<temple> abhishekam ticket price" | `Book sevas online at <Temple>` |
| `/temples/{slug}/donate` | "<temple> online donation", "<temple> hundi online" | `Donate online to <Temple>` |
| `/te/temples/{slug}` (and hi, ta, kn) | the same in Telugu, Hindi, Tamil, Kannada | the temple's name in that language |
| `/states/{state}`, `/states/{state}/{district}` | "temples in Telangana", "temples in Mulugu district", "famous temples near Warangal" | `Temples in <District>, <State>: Timings & How to Reach` |
| `/deities/{deity}` | "Narasimha temples in India", "famous Shiva temples" | `<Deity> temples in India: timings, pujas and how to reach` |
| `/festivals/{festival}` | "Ugadi 2026 date", "Narasimha Jayanti 2027", "Masik Shivaratri dates 2026" | `<Festival> <year>: Date, Tithi & Significance` |
| `/festivals/ekadashi`, `/purnima`, `/amavasya` | "Ekadashi 2026 dates", "Purnima dates 2026", "Amavasya 2026 list" | `Ekadashi <year> Dates: Full List with Names` |
| `/festivals/{year}/{month}` | "October 2026 festivals list", "festivals in November 2026" | `Hindu Festivals in <Month Year>: Dates & Vrat List` |
| `/` | "temple timings", "temple darshan timings app", the brand | `Temple timings, puja booking and directions` |

Every page also carries: a canonical address, structured data (temple:
HinduTemple with opening hours, address, map position and photos; FAQ;
breadcrumbs; sevas with prices; the site's search box), Open Graph cards
for WhatsApp, an image for every temple (photo, else the deity's painting,
else a generated card), `max-image-preview:large`, the image sitemap,
hreflang for the language pages, and IndexNow pings to Bing when a temple
changes. Account, booking-form and search-result pages are `noindex`.

## The work that moves rankings most (admin panel)

In order of effect, per temple, starting with the best-known ones:

1. **Darshan timings.** "<temple> timings" is the biggest search for
   every temple. Without timings the page cannot answer it, and Google
   quotes something else (seva durations). Add every timing, weekend ones
   too.
2. **Photos.** A real photo of the temple and the deity: it is what Google
   shows beside the result and in image search. Three or more per temple.
3. **A description in your own words**, 150 to 300 words: the history,
   what the temple is known for, the best time to visit. Copied text from
   other sites does not rank.
4. **Search keywords** (the temple's *Search keywords* field in the admin): local names, spellings and the
   town people use ("Mallur Narasimha temple", "Hemachala gutta").
5. **Telugu (and other) names, reviewed.** Each reviewed name opens a page
   in that language, which competes for searches in that script.
6. **Sevas with fees and booking switched on**, so the sevas page answers
   "seva booking" searches and people can book.

Then run on the server once: `php artisan deities:fetch-images`, so temples
without photos show their deity's painting.

## Search Console (weekly)

- **Performance → Queries**: what people searched to find the site. A
  query with many impressions but a low position is the next page to
  improve (usually: add timings, photos, description to that temple).
- **Pages → Not indexed**: fix anything listed under "Crawled – currently
  not indexed" by adding content to those temples.
- After improving a temple: **URL inspection → Request indexing**.
- Sitemaps: `sitemap.xml` stays submitted; it lists every temple, its
  sevas and hundi pages, the festival pages and the policy pages.

## Links from elsewhere (what Google trusts most)

- Ask each temple trust that registers to link to its page from its own
  website, Facebook page and Google Maps listing ("Website" field), and
  to print the QR poster (it points at darshansaathi.com).
- Share temple pages and festival pages in WhatsApp groups, Facebook
  pages and Instagram of devotee communities before each festival.
- List the site on Telangana / Andhra tourism directories, local news
  sites and blogs that write about temples; a few real links beat many
  directory ones.
- Never buy links: Google penalises the whole site.

## Timing

New pages are usually crawled within days and start ranking for long,
specific searches ("<small temple> timings") within weeks. Competitive
searches ("Tirumala darshan timings") take months and depend mostly on the
content and links above.
