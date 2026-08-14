# Internationalisation — state, findings and what other lanes need to do

Owner: the i18n lane. Extension: `extensions/looksmax-i18n/`.
Everything below is measured on osprey (`/work/flarum`, container `flarum-app`) on
2026-08-13 unless it says otherwise.

---

## 1. The one thing to read if you read nothing else

### `php flarum info` destroys the English forum

Reproduced deterministically inside `flarum-app`, from a clean cache:

```
rm -f storage/locale/*
curl -s -o /dev/null http://127.0.0.1/    → catalogue.en…php  79638 bytes  www-data
rm -f storage/locale/*
php flarum info                           → catalogue.en…php    133 bytes  root
rm -f storage/locale/*
php flarum cache:clear                    → (no file — fine)
php flarum migrate                        → (no file — fine)
php flarum schedule:list                  → (no file — fine)
```

133 bytes is `new MessageCatalogue('en', array())` with an empty `.meta`
(`a:0:{}`). Symfony's Translator caches per locale, so **every subsequent web
request loads that empty catalogue instead of reading the YAML**, every key in
the forum falls through to its own name, and visitors read
`core.forum.post_scrubber.original_post_link` where a sentence should be.

The site is **HTTP 200** throughout. `forum.css` is present. `docker ps` is
green. Nothing is logged. This has taken the forum down twice in one day.

**Cause** — `Flarum\Foundation\ApplicationInfoProvider` (constructor line 85)
type-hints `Flarum\Locale\Translator` and calls `trans()` at lines 126–132. That
resolves the `translator` singleton *without* resolving `LocaleManager`. Every
`addTranslations()` in the entire application — core's `core.yml`, every
`Extend\Locales`, every language pack — is registered inside a
`$container->resolving(LocaleManager::class, …)` callback. So the translator has
**zero resources**, and the first `trans()` dumps that emptiness to disk as the
authoritative cache. As root, because `docker exec` runs as root, so `www-data`
cannot overwrite it and the site cannot self-heal.

**Fixed** by `extensions/looksmax-i18n/src/LocaleResourceGuard.php`, which forces
`LocaleManager` to resolve whenever the translator does. Verified: after the
guard, `php flarum info` leaves `catalogue.en` at 81083 bytes, unchanged.

**You do not need to stop running `php flarum info`.** You do need to not
disable `local-looksmax-i18n`.

---

## 2. Coverage — the number

Re-runnable, from `extensions/looksmax-i18n/`:

```
bun tools/audit.ts                    # the number + every offending file:line
bun tools/audit.ts --all              # what was excluded from the denominator, and why
bun tools/audit.ts --check --floor 97 # non-zero exit when coverage drops
bun tools/audit.ts --ext looksmax-store
```

It lexes PHP, JS, LESS and Blade with comment/string/regex tracking (a regex
over these heavily-commented files reports ~900 findings of which ~40 are real),
classifies each string literal, and reports
`translated / (translated + hardcoded)`.

Every exclusion carries a named reason and `--all` prints them, so the
denominator can be argued with rather than trusted.

| | coverage | translated | user-visible |
|---|---|---|---|
| **before** | **6.1%** | 29 | 477 |
| **after** | **90.2%** | 759 | 841 |

The denominator grew because six authoring lanes read the code and found
strings the heuristics had bucketed as identifiers or SQL — every table header
in the store, every stat label in the author panel, the reaction picker's group
headings. Finding more work while doing the work is the honest outcome.

Of the 759 translated: 206 are keys inside a `trans()` call, 395 are a
translator call, 71 are English literals sitting behind a translation seam
(see §2.1). Of the 82 still hardcoded: **26 are deferred with a written design**
in the relevant `i18n-map.json`, 56 are unreviewed.

Per-extension baselines are in `extensions/looksmax-i18n/tools/baseline.json`.

### 2.1 What "translated" is allowed to mean

Three things, and the report prints the split so the headline number can be
argued with rather than taken:

- **a key in a `trans()` call** — the ordinary case.
- **a fallback argument.** Several lanes settled on `t(key, params, fallback)`,
  where the fallback is the original English, so a half-deployed locale pack
  degrades to readable English instead of to
  `local-looksmax-store.forum.time.days_left`. Counting those as untranslated
  would score the safer pattern worse than the reckless one.
- **an English literal behind a translation seam.** `looksmax-ranks`'
  catalogue cannot hold keys: `looksmax-userinfo/src/RankSource.php` matches on
  the lowercased `name`, and `looksmax-store/src/Seed.php` copies `name` and
  `blurb` into the `store_items` **table** — so a key breaks one silently and
  persists into the database in the other. A private `localize()` on every read
  path translates instead, leaving the literal as the fallback. Credited only
  when the literal exactly equals a value shipped in that extension's `en.yml`.
  Verified rather than believed:

  ```
  GET /api/users/1?lang=es → "rankName":"Lumbrera", "nextRankName":"Ascendido"
  GET /api/users/1?lang=en → "rankName":"Luminary", "nextRankName":"Ascended"
  ```

Two kinds of human verdict are read back out of each `locale/i18n-map.json` and
shown with their reason under `--all`: `not_translatable` (read, and confirmed
no user can see it) is excluded from the denominator; `deferred` (user-visible,
but seeded into the database or compiled into cached XSLT) still counts as
hardcoded, because a reader still sees English.

---

## 3. Wire an extension for translation — the one-line version

```php
// extend.php
(new Extend\Locales(__DIR__.'/locale')),
```

Then `locale/en.yml` and `locale/es.yml`, filenames = locale codes. Keys are
namespaced by the Flarum extension ID: `local-looksmax-store.forum.…`.
(`flarum-analytics`'s ID is `local-analytics`, not `local-flarum-analytics`.)

**ICU MessageFormat is on for every key.** `LocaleManager::addTranslations()`
registers into the `messages+intl-icu` domain unconditionally, so
`{count, plural, one {…} other {…}}` and `{gender, select, …}` work with no
extra setup. **Never concatenate a count with a word.** `count + " days left"`
cannot be translated into Spanish, where the verb changes too:
`{count, plural, one {queda # día} other {quedan # días}}`.

**Never format a number, date or relative time by hand.** `window.lmxI18n` is
live on every page:

| call | English | Spanish |
|---|---|---|
| `lmxI18n.num(12431)` | `12,431` | `12.431` |
| `lmxI18n.compact(12431)` | `12.4K` | `12,4 mil` |
| `lmxI18n.monthYear('2019-03-04')` | `Mar 2019` | `mar 2019` |
| `lmxI18n.rel(t)` | `3 hours ago` | `hace 3 horas` |
| `lmxI18n.list(['a','b','c'])` | `a, b and c` | `a, b y c` |

`toLocaleString()` with no locale argument follows the *browser*, not the forum,
and is a defect. So is any hand-rolled `k`/`M` suffix.

---

## 4. What is done, and what is waiting on you

Authoring is decoupled from applying, because six lanes are editing these files
concurrently. Each extension gets a `locale/i18n-map.json` patch spec — file,
line, exact source text, exact replacement — and
`bun tools/codemod.ts` applies it. The codemod is **content-matched, not
line-matched** (your edits above it do not break it), **idempotent** (re-running
is safe), and **refuses to guess** (a `find` that matches zero or two places
fails loudly rather than editing the wrong one).

```
bun tools/codemod.ts --dry     # show what would change
bun tools/codemod.ts           # apply
bun tools/codemod.ts --revert  # put it all back
```

If you own an extension and want to apply your own conversion by hand instead,
the spec is a complete instruction list — read it and delete it when done.

---

## 5. Findings in other lanes' files — not touched, please pick up

### 5.1 `e2e/shot.ts --login` has never worked (UI lane)

`e2e/shot.ts:76` POSTs to `/login` with a JSON content type and **no CSRF
token**. Flarum answers **400**. The script prints the 400 and carries on, so
**every screenshot any lane has taken with `--login` is a logged-out page** that
looks plausible.

Measured: the same POST with `X-CSRF-Token: flarum.core.app.session.csrfToken`
returns `200 {"token":"…","userId":1}`.

Fix — `e2e/shot.ts`, in the `if (LOGIN)` block, change the headers to:

```js
headers: {
  'Content-Type': 'application/json',
  'X-CSRF-Token': flarum.core.app.session.csrfToken,
},
```

…and read the token inside the page context, as
`extensions/looksmax-i18n/e2e/i18n.ts:login()` does. That file is a working
reference. It also asserts `session.user.username()` afterwards, because a 200
from `/login` is not proof that the *page* is authenticated.

**This is worth re-checking any "logged-in" screenshot conclusion reached today.**

### 5.2 `<html lang="en">` is hardcoded on every server-rendered page (brand lane)

`extensions/looksmax-brand/views/layouts/basic.blade.php:24`.

That layout backs the 404, the 500, the CSRF-mismatch page, password reset and
e-mail confirmation. On a Spanish-primary forum it declares English to screen
readers, to browser translation, and to search engines, on the pages a visitor
most often arrives at from outside.

Core's own frontend layout does this correctly —
`vendor/flarum/core/views/frontend/app.blade.php:3` is
`@if ($language) lang="{{ $language }}"`. Verify `$language` is in scope in the
basic layout; if it is not, `{{ app('flarum.locales')->getLocale() }}` is
always available.

The copy on those pages is hardcoded English too — `not_found.blade.php:19,21,25`
and `default.blade.php:15,17,22`. A patch spec is being written for them; the
`lang` attribute is the part that cannot wait.

### 5.3 The forum's `<html>` tag has no `dir` fallback problem, but the error layout has no `dir` at all

Not urgent — both shipped languages are LTR and RTL is explicitly out of scope.
Recorded only so that adding Arabic later is a one-line change and not a hunt.

### 5.4 `looksmax-import` writes an English string into post bodies

`extensions/looksmax-import/src/Console/ImportCommand.php:286` writes
`"\n\n_(body not scraped for this thread)_"` into the post body at import time.
Every visitor reading that thread sees it, in whatever language they are using.

It is written once, into the database, so it cannot be re-translated per reader
as it stands. The cheap fix is a marker (e.g. `[LMXNOTE key=import.no_body]`)
that the formatter swaps at render, which the format lane already has the
machinery for. Filed for the import + format lanes jointly.

### 5.5 Post language is not UI language, and nothing marks it yet

The imported corpus is English, Spanish, Russian, Turkish, German and French,
and the search lane already detects post language for tokenisation
(`HANDOFF-SEARCH.md`, `/root/search-notes/meili-capabilities.md`). Nothing
surfaces that to a reader.

Keys are already written and shipped for it —
`local-looksmax-i18n.forum.content.*` (`language_label`, `mixed`, `filter_all`,
`filter_mine`, `translate`, `show_original`) — so the UI can be built without a
second translation pass. What is missing is the serializer attribute carrying
the detected language onto the post payload, which belongs to the search lane
(it owns the detection) or the format lane (it owns post rendering), not here.

---

## 6. Locale selection — how it actually behaves

| visitor | mechanism | where |
|---|---|---|
| guest, first visit | `Accept-Language`, matched against installed locales | `src/NegotiateLocale.php` |
| guest, has chosen | `locale` cookie | core `SetLocale` |
| member | `locale` preference on the account | core `SetLocale` |
| anyone, explicit | `?lang=es` / `?lang=en` — outranks everything, does not write the account | `src/NegotiateLocale.php` |
| default when nothing matches | `default_locale` = **`es`** | settings |

**The trap, and it cost an hour**: core's `SetLocale` reads the `locale` cookie
**for guests only**. A signed-in member with no stored preference never reaches
that branch. Anything that tries to influence the locale by injecting a cookie
*before* core runs is silently discarded for exactly those users.
`NegotiateLocale` therefore runs `insertAfter(SetLocale::class, …)`, not before.

Matching is three-pass per requested tag: exact, then the tag's base language
(`es-419` → `es`, which is what a Mexican Chrome sends), then an installed
regional variant of that base. `pt-BR` matches nothing and falls to the default.

`Vary: Accept-Language` is set on every response, because one URL now produces
two documents and a shared cache would otherwise serve the first visitor's
language to everyone behind it.

---

## 7. The language control — and the header budget

The operator's report: *"the language change icon takes too much space in the
top bar and makes the word mark get overlapped"*.

Measured at 1440 in the browser: core adds the locale dropdown by itself as soon
as a second language pack is enabled, as
`li.item-locale > button.Dropdown-toggle.Button--link`, **121 × 34 px** — its
label is the language's full name plus a sort caret.

Now **34 × 34**, showing the two-letter subtag `ES` / `EN`, with explicit
`width`/`height` and `aspect-ratio: 1` so it cannot stretch the way the header
search icon currently does. **87px returned to the header.**

- Not a flag: a flag is a country and Spanish is not one.
- Not a globe: the first attempt used `content: "\f0ac"` and rendered a **tofu
  box**, because the icon lane has moved this install to a self-hosted Iconify
  set and Font Awesome is no longer loaded. A glyph that depends on another
  lane's font choice breaks when that lane ships.
- The subtag needs no translation and names what it selects.

For a **signed-in** member the control is folded into the session menu instead
and removed from the header entirely, costing zero header pixels. The CSS that
hides it keys off `html.lmx-locale-in-session`, which
`js/dist/forum.js` sets **only after the menu item was actually added** — so if
core renames the component, the failure is "the control is still in the header",
never "there is no way to change language".

**What the UI lane needs from the header: 34px, or nothing at all if you would
rather I move it to a footer.** There is no `.App-footer` in this theme today
(verified in the DOM), which is why the logged-out control is still in the bar.
If the desktop rework adds one, say so and I will move it and give the 34px
back.

---

## 8. The browser gate

```
bun extensions/looksmax-i18n/e2e/i18n.ts gate  --login admin:PASS
bun extensions/looksmax-i18n/e2e/i18n.ts shots --login admin:PASS --out DIR
bun extensions/looksmax-i18n/e2e/i18n.ts gate  --expect-fail   # prove it can go red
```

9 surfaces × 2 locales × 2 widths, logged in and logged out. Asserts:

1. **Zero raw translation keys on screen** — in text nodes *and* in
   `title`/`placeholder`/`aria-label`/`alt`. This is the P0. The page counts
   them itself: `js/dist/forum.js` walks the DOM and publishes
   `window.__lmxI18n.rawKeyCount`.
2. `<html lang>` matches the locale actually being served.
3. `app.data.locale` agrees with it.
4. A language control is reachable, in whichever of its two homes applies.
5. The header control is ≤ 48px.
6. Three `hreflang` alternates.
7. **No element overflows the viewport and no two sibling text elements
   overlap.** Spanish runs ~24% longer than English on this forum's chrome, and
   the damage it does is visual, so it is measured rather than eyeballed.

The overflow check ignores `sr-only`, `text-overflow: ellipsis`,
`-webkit-line-clamp`, off-canvas drawers and any ancestor that clips — its first
version reported 25 "failures" per mobile page, all of them deliberate, which is
a check nobody reads twice.

---

## 9. Spanish, and what was NOT hand-translated

Core and the bundled `flarum/*` extensions use **`flarum-lang/spanish` 1.13.2**
(`composer require "flarum-lang/spanish:^1.0"`, pulls `flarum-lang/utils`).

Chosen against 13 candidates by measured coverage, not by README:
**93.7% of core's 589 keys** (552/589), **98.9% of the 278 bundled-extension
keys**, 95.3% overall — counted key-for-key against `flarum/framework` tag
`v1.8.18`. Human Spanish, not machine output; pan-Hispanic, zero *voseo*, zero
*vosotros*; ICU plurals in 51 strings. Its "informal" (`tú`) register is the
default and is what we use.

Hard gate applied: `darkfoxdeveloper/lang-spanish` and its aliases are flagged
in our own `/root/flarum-re/re-flarum/DEPRECATED.tsv` and `analysis/abandoned.tsv`
and were disqualified. `flarum-lang/spanish` appears in neither.

The 37 core keys it is missing are all 1.8.16–1.8.18 additions and all but two
are admin-only. The two a visitor can see —
`core.lib.error.network_message` and `offline_message` — are filled in
`extensions/looksmax-i18n/locale/es.yml`.

That file also overrides six of the pack's error strings. The pack renders them
as *"¡Recórcholis!"*, *"¡Nanay de la China!"*, *"¡Para el carro!"* and
*"¡Caramba!"* — dated peninsular interjections; *nanay de la China* in
particular is a grandparent's phrase and is not something anyone in Mexico says.
The audience is Mexican and the rest of the forum's register is plain, so the
error text is plain. **Only the exclamation is dropped; no information changed.**

Our own extensions' Spanish is written here, not sourced.

---

## 10. Spanish is the DEFAULT, not an option beside English

Confirmed, each measured on the running site:

1. **`default_locale` = `es`.** A request with no cookie, no account preference
   and no `Accept-Language` gets `<html lang="es">` and Spanish copy.
2. **English stays one action away and is remembered.** For a guest the switcher
   writes a year-long `locale` cookie; for a member it writes the account
   preference through the API. `NegotiateLocale` only guesses when *neither*
   exists, so a chosen English is never overridden on the next page or the next
   session.
3. **`Accept-Language` still beats the default.** `Accept-Language: en-US` →
   `lang="en"` on the first request, no click required. `es-419` → `es`.
   `fr-FR`, which is not installed, falls to the default rather than matching
   something approximate.
4. **The parts that are easy to forget:**

   | surface | state |
   |---|---|
   | welcome banner | per-locale, via `ForumStrings` |
   | meta description, `og:`/`twitter:description` | per-locale, via `LocalisedSettings` |
   | page titles | core, covered by the Spanish pack |
   | validation messages | `validation.yml`, 94.2% in the pack |
   | e-mail subjects and bodies | core sends in the recipient's own locale (`NotificationMailer` reads their preference) |
   | 404 / 500 / CSRF pages | keyed by the brand lane's spec; `<html lang>` fixed |
   | admin panel | Spanish pack covers it; `window.lmxI18n` now loads there too |
   | **PWA manifest** | **still English — see below** |

`forum_description` and `welcome_message` are Flarum *settings*, not keys: one
row, one value, shown to everybody. The setting holds the **Spanish**, so
removing this extension degrades a Spanish-first forum to Spanish rather than to
English, and `LocalisedSettings` swaps in the translation for the request's
locale. An admin who types their own description keeps it — only the exact
strings this lane ships are replaced.

**Why a settings decorator and not another head hook**: `Head.php` tried it
first and lost. `local-looksmax-brand`'s head hook runs *after* this one,
re-reads `forum_description`, and writes it three times, twice as raw
`$document->head` entries a meta-map overwrite cannot reach. Measured: `?lang=en`
returned `og:locale=en_US` (so this lane's hook definitely ran, in English) with
a Spanish description. Decorating the read fixes core's `Meta`, the brand lane's
`Head` and the API serializer at once, and does not depend on hook order that is
not mine to choose.

### 10.1 The six front-page sections

Keys are shipped and waiting, in `looksmax-index/locale/{en,es}.yml` under
`local-looksmax-index.forum.section.<key>.{title,desc}` — the namespace
`Sections::NS` already asks for. Nothing renders them yet, so nothing is broken;
the keys are in place ahead of the UI lane needing them.

| key | es (source) | en |
|---|---|---|
| `peptides` | Peptides | Peptides |
| `anabolicos` | Anab**ó**licos | Anabolics |
| `softmaxing` | Softmaxing | Softmaxing |
| `looksmaxing` | Looksmaxing | Looksmaxing |
| `peligrosomaxing` | Peligrosomaxing | Peligrosomaxing |
| `mejores_guias` | Mejores Gu**í**as | Best Guides |

The **Spanish is the source string**. Three are identical in both files on
purpose: *Softmaxing* and *Looksmaxing* are the subculture's own coinages and
are written that way in Spanish too; **`Peligrosomaxing` was coined in Spanish
and has no established English rendering, so it is left as written rather than
invented into "Dangermaxxing"** — flagging that as a decision for the operator,
not one I made. `Peptides` is in English in the requested list even though the
Spanish is *Péptidos*; that is left alone.

Accents live in the display string. `Sections::SECTIONS` keeps the slugs ASCII
(`anabolicos`, `mejores-guias`), which is right: a slug is a URL, a title is
text.

`src/Lexicon.php` is a weighted classification lexicon, not copy — its ~200
tokens are matched against post text and never rendered, and it is already
bilingual by design (`peptido`, `cuidado de la piel`). Recorded as
`not_translatable_files` so the audit stops counting it. **Translating it would
break matching, not fix it.**

News/announcements keys are shipped too: `…forum.news.{heading,empty,see_all,
pinned,dismiss}` in both extensions' packs.

---

## 11. The content is not in the language the interface is

Measured over the whole imported corpus, not a sample —
`bun extensions/looksmax-i18n/tools/corpus-language.ts <posts>`:

| | posts | share of all | share of identified |
|---|---|---|---|
| **English** | 227,797 | 68.5% | **98.2%** |
| Turkish | 1,577 | 0.5% | 0.7% |
| Russian | 1,262 | 0.4% | 0.5% |
| German | 614 | 0.2% | 0.3% |
| **Spanish** | **589** | **0.2%** | **0.3%** |
| French | 111 | 0.0% | 0.0% |
| too short to call | 70,969 | 21.3% | — |
| undetermined / ambiguous | 29,841 | 9.0% | — |
| **total analysed** | **332,760** | | |

**The interface is becoming Spanish-first over a corpus that is 98% English.**
That is a product decision, not a bug, and it should be made with these numbers
visible. The options are not equivalent: seeding real Spanish content, or
marking post language and offering translation (§5.5), or accepting that a
Spanish-speaking newcomer's first page of threads will be in English.

Method and its limits, stated plainly: stopword scoring over the six known
languages plus a Cyrillic script check, run over every post body. Posts under
eight words get their own bucket rather than being forced into a language.
Spot-checked against ground truth — a grep for distinctly-Spanish function
words (`está`, `también`, `porque`, `siempre`) matches 123 posts, fewer than
the 589 the detector labels Spanish, which is the direction that would be
expected if the detector is working.

---

## 12. Still open

- Applying the six lanes' patch specs and re-measuring coverage.
- `src/Seed.php` in the store seeds item names and descriptions **into the
  database**. A key in a seeded row is a different problem from a key in a
  template; a design is being recorded in that lane's spec rather than guessed
  at.
- The format lane's spoiler/quote/claim labels live in **compiled XSLT**, which
  is built once rather than per request — translating them needs either a
  per-locale compile or a data attribute the JS fills in.
- Post-language marking and per-post translation (§5.5).
- A translation-completeness dashboard in the admin panel. The audit already
  produces the JSON for it.
- **Database-held display text is the largest remaining Spanish-first gap**, and
  no locale pack can reach it:
  - **47 tag rows** (`tags.name`, `tags.description`) — `Blackpill`,
    `Motivation`, `Theory`, `Serious`, `Looksmaxing`, `Offtopic`,
    `Moneymaking & Success`, `Cosmetic Surgery`… These are the section headings
    and every chip on the front page, and they are all English. The six new
    sections replace some of this; the rest needs either a rename or a
    `name_key` column with a translate-at-render seam.
  - **The store catalogue** (`store_items.name`/`blurb`, seeded from
    `Seed.php`) — same shape, design written in that lane's spec.
  - **Ledger reason codes** rendered raw into the member-facing credits table.
- **The PWA manifest** (`looksmax-brand/assets/site.webmanifest`) is a static
  file with one language, and its `name`/`description` show in the install
  prompt. Needs a route serving it from the translator.
- **Two lanes chose different mechanisms for the same XSLT problem** — the
  format lane proposes server-side s9e XSL parameters, the guides lane a
  `data-lmx-t` attribute swept by JS. **Pick the server-side parameters**: the
  JS sweep means a flash of English on every post, English for crawlers, and
  English for anyone with JS off — and the guides lane reports its own sweeper
  is currently dead (`js/dist/forum.js:52` returns the module rather than
  `.default`, so its guard never passes).
