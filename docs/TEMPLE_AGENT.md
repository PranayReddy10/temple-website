# Temple research agent

Adds a state's top temples with full details: names (English and Telugu),
deity, address, PIN, map location, history, significance and festivals,
visitor rules, timings, sevas and how to reach. It never adds a temple the
site already lists.

## Load the researched states (Andhra Pradesh and Telangana)

The research is kept in `database/data/temples/<state>.json`, one file per
state. On the server:

```bash
php artisan app:deploy --force               # runs the how_to_reach migration
php artisan temples:import-state AP --dry-run # shows which temples are new and which are already listed
php artisan temples:import-state AP
php artisan temples:import-state TG
```

If an earlier version of a file was already imported, add `--refresh`: a
fact-checked record (one carrying `"verification"`) then replaces what the
earlier import wrote, corrections and removals included, but only on temples
no staff member has edited.

`--draft` adds new temples as drafts for an editor to publish, instead of
publishing them.

## How "already listed" is decided

A record is the same temple as a listed one, in the same state, when it has
the same slug, the same name, one of its other names ("Kanipakam Temple"), a
shorter name naming its town ("Kanipakam Vinayaka Temple"), or a map pin at
the same spot. A listed temple is then only filled in: its empty fields are
filled, timings and sevas are added only when it has none, and anything an
editor wrote is kept. A temple an editor verified or deleted is left alone.
Running an import again changes nothing.

## Find and research more temples with the agent (free)

The agent costs nothing and needs no API key. It uses only free, open
sources:

- **Wikidata** finds the state's temples, most notable first, and gives the
  map location, official website, when built, style, deity, Telugu name and
  World Heritage status;
- **Wikipedia** gives the summary, history and festivals;
- **the temple's own website** gives timings, sevas, phone and email (read
  by the same reader as the admin panel's "Read official site");
- **OpenStreetMap** gives the address, PIN code and district at the map pin.

It skips temples the site already lists, saves each new one to the state's
data file (with its sources) and imports it.

```bash
php artisan temples:agent AP --limit=10           # discover and add 10 more
php artisan temples:agent TG --temple="Kurumurthy Venkateswara Temple"  # research named temples
php artisan temples:agent KA --limit=25 --draft   # next state, as drafts
php artisan temples:agent AP --no-import          # only save the research to the data file
```

What these sources do not have (dress code, queue rules, how to reach, and
timings for temples whose website cannot be read) stays empty for editors
to fill. Commit the changed data file so the research is kept and reviewed.

Optional: `TEMPLE_AGENT=claude` in `.env` switches to Claude with web
search, which researches more fields but is **paid API usage** and needs
`ANTHROPIC_API_KEY`. Leave it unset to stay free.

## How far the shipped data is checked

Every record carrying `"verification"` was cross-checked field by field:
kept only when the temple's own site, the AP/Telangana endowments department
(tms.ap.gov.in, *.telangana.gov.in), TTD or the district portal shows it, or
two independent sources agree; corrected otherwise; emptied when nothing
confirmed it. Its `changes` list says what was corrected and why.

As of 14 October 2026, 53 of the 95 records are checked. The other 42
(no `"verification"` key) are from the first research pass and still need
the check. The checking environment could search the web but not open
pages; the rest can be checked the same way, or by an editor against the
temple's own website.

## Trust level

Everything imported is **community level**: researched from public sources,
not confirmed with the temple. Unknown values are left empty rather than
guessed, and no seva price is ever imported ("No published price"). Timings
carry a note telling devotees to check before travelling. Editors verify a
temple against the temple itself before raising its trust level.
