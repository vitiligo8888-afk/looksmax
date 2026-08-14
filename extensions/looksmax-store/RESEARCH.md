# Forum store / economy catalogue — evidence file

Read-only research. Every claim below carries a citation: a **row id** in the scrape
(`/work/lmx/scraper/looksmax.db` on `osprey`), a **live API URL**, or a **file:line**.

## Provenance and method

| Source | What it is | Size / verification |
|---|---|---|
| `osprey:/work/lmx/scraper/looksmax.db` | SQLite scrape of looksmax.org | `threads` 2,205,192 rows · `posts` 383,370 rows · `users` 77,241 · `forums` 18 · `categories` 3 · `pages` **0** (empty) — measured with `select count(*)` |
| Live forum API | `https://colleague-eligibility-workers-slides.trycloudflare.com/api/…` | Flarum JSON:API, fetched 2026-08-13 |
| `/root/forum-research/lane-extensions-raw.md` | Flarum 1.8 BUY-vs-BUILD audit | 1,200 lines |
| `/root/flarum-re/re-flarum/PREMIUM_CATALOG.md` | Flarum paid-extension catalog | 84 lines |

Schema (relevant columns only, from `sqlite_master`):

- `threads(id, forum_id, title, slug, url, author_name, replies, views, sticky, posts_fetched, …)`
- `posts(id, thread_id, position, author_name, user_title, user_banners, html, text, reaction_score, is_first, permalink, …)`
- `users(id, name, title, banners, avatar, post_count, reputation, style_class, badge_id, …)`
- **There is no FTS index** (`sqlite_master` contains only `idx_threads_*` / `idx_posts_*` btree indexes),
  so all category counts below come from three full regex passes over `posts.text` (383,370 rows)
  and `threads.title` (2,205,192 rows), run on osprey via `python3 sqlite3` (`sqlite3` CLI is not
  installed on that host).
- **Coverage caveat that matters for every count**: only 383,370 post bodies have been fetched
  (`posts_fetched=1` threads); the other ~1.9M threads exist as titles/URLs only. Post-body counts
  are therefore a **lower bound**; title counts cover the whole board.

---

# 1. Discussion 329 on the LIVE forum — actual text

`GET https://colleague-eligibility-workers-slides.trycloudflare.com/api/discussions/329?include=posts`

**Correction to the brief:** discussion 329 is titled
**"Six New Animated VIP Color's Dropped - Exclusive For VIP Members!"**
(`data.attributes.title`, `isSticky: true`, `commentCount: 33`, `viewCount: 458`,
`createdAt: 2026-08-12T20:21:42+00:00`). It does **not** contain the 14-colour list
(Blue/Green/Red/Yellow/Cosmic/Purple/Storm/Until Dawn/Blood/Black/Orange/Pink/Biohazard/Raspberry)
as text and **contains no prices at all**. The old-colour list the brief describes is present only as
a **screenshot attachment** (`https://i.looksmax.org/attachments/2026/08/6729828_5522209_Schermafbeelding_2025-11-07_om_19.56.34.png`).
The 14 names are recoverable from the scrape instead — see §2.3.

Verbatim OP (post id **9620**, post number 1):

> Hey all,
> I made these new color's for VIP members:
> Enjoy.
> **Old:** [attachment 5506202] **New:** [attachments 5506341, 5506306]
> The new VIP color's are:
> **- VIP Azure**
> - **VIP Winter (animated)**
> - VIP Slime (animated)
> - VIP Striped (animated)
> - VIP Ajax (animated)
> - VIP Away
> @Master

Replies that carry commercial signal (all from the same API call):

| Post id | # | Verbatim |
|---|---|---|
| 9621 | 2 | "give us vip sale" |
| 9624 | 5 | "Is this for lifetime only or all vips?" |
| 9630 | 11 | "All" (staff answer — new colours ship to **every** VIP tier, not just Lifetime) |
| 9625 | 6 | "Vip sale soon?" |
| 9626 | 7 | "The VIP sale was just on, my guy. Didn't you notice all the days going by with the **4 hours left bolded in big red font across the main screen**? … I'm sure they'll have some **October spooky sale** soon" |
| 9634 | 15 | "i bought my vip right in time" |
| 9622 | 3 | "i like how my username looks with azure equipped … i also think it deserves the **same white glow as other unanimated usernames**." |
| 9648 | 29 | "Numb also blessed us non-VIP members too! Look at this beautiful new **AI ad** that dropped along with the new VIP colors. Mirin, hard, especially since the new ad **shows up in between each comment (unavoidable)**." |
| 9632 | 13 | "Dude made the pornhub name theme jfl" |

**Design facts extractable from 329:** colours are *equippable* (a user "equips" one and can switch),
they are a VIP-tier entitlement rather than a separate purchase, animated variants are a distinct
class from static ones, a "white glow" outline is a separate cosmetic property, drops are
periodic content events with countdown-banner sales attached, and non-payers are monetised by
interstitial ads in the post stream.

Adjacent live discussions found via `GET /api/discussions?filter[q]=VIP`:

- **532 — "I just saw the VIP prices..."**, post **12207**: *"Fucking shit, fuck you mean **17 dollars for a month**? That's like 18k pesos … I hate this damn inferior Argentine economy"*; post **12210**: *"17 dollars is a decently big amount in any european country … Here it roughly translates to 50 Zł which is like 15 hot dogs."* → **live price point: $17/month.**
- **295 — "5x LIFETIME VIP GIVEAWAY ($1000+ value) THREAD MAKING CONTEST"**, post **8064** (see §4).
- **352 — "To everyone using the vip away color"** — 20 posts of pure cosmetic status conflict, e.g. post 10271 *"Nigga stuck on cosmic"*, post 10281 *"That shit is raping my eyes. Fuchsia mogs."*, post 10285 *"okay going"* (a user switching colour on social pressure). Evidence that colour choice is an identity/status good with churn, not a one-time buy.

---

# 2. Everything the source board actually sold

## 2.0 The canonical product page (the single most important row in the scrape)

**Thread 290986 · "VIP Supporters (Avatar frames, name changes, and more)"** (forum_id 11 = News &
Announcements, 17,000 views) — **post id 4933638**, author `Looksmax`. Full verbatim perk list:

> We're introducing VIP Supporters! You can become one from **the upgrade page**.
> Becoming a VIP Supporter gives you the following perks:
> **Unique Username & Avatar Frame:** Choose between red, blue, yellow, and green. Upgrading will override your current rank color, no matter what it is.
> **Name change:** Available immediately the first time you upgrade, and afterwards **every 30 days** (you need to be VIP Supporter to use the feature). Your name change will remain even after the upgrade expires.
> **Exclusive Subforum:** Visible only to members who become VIP Supporters.
> **Conversations:** Create conversations with up to **15 people** total (up from the usual **5**).
> **Rating Threads:** Delete your own threads in the Ratings subforum without restrictions.
> **No Ads:** Ads are shown to members under with 500 posts and to guests to help pay server costs. With VIP, you will be able to browse the site **ad-free**.
> **Signature:** Have more freedom with your signature by being allowed to use **more text, images, emoji, and links, as well as a bigger font-size**.
> *Spoiler: Why are these features paid?* … 1) Name changes and thread deletion from ratings are very disruptive … useful to put behind a small paywall so only people who really need them can do it … 2) **Users sometimes offer up to hundreds of dollars in exchange for being given exceptions to rules (e.g., a bribe to get a name change).** The VIP supporter role allows for a cheap and actually 'legal' way of getting a ton of quality-of-life features in one handy package. 3) It helps pay the servers and new content!
> Get your VIP Supporter rank from the upgrade page! **(Currently we only accept crypto, but we're working on other methods.)**

Posts 2–5 of that thread are staff demo accounts, one per colour — **4933640** `FireVIP` "This is how RED VIP looks like.", **4933645** `AquaVIP` (BLUE), **4933643** `ThunderVIP` (YELLOW), **4933658** `AcidVIP` (GREEN).

**Thread 1 · "Rules and FAQ"** (533,000 views), **post id 1**, documents the surrounding
free-permission economy that the paid tier sits on top of:

> **How can I get VIP?** Read the official thread: VIP Support Info · **Purchase VIP here: Upgrade Account**
> **What is reputation?** … Each like or positive reaction (except "Anger") gives you +1 reputation.
> **What are trophies?** Trophies are badges you earn by completing milestones … **They don't provide perks but are visible for flair.**
> **How do ranks work?** **Every 500 posts unlocks a new rank.** Each rank also requires an additional week of registration time. Spamming to skip ranks won't work … **VIP users receive a special color and other perks.**
> **Can I edit or delete my posts and threads?** You have a **4-hour window** to edit/delete posts … **VIP users can delete rating threads and their images.**
> **Can I change my username?** **Yes, if you upgrade your account.**
> **When can I send PMs or vote in polls?** These features unlock automatically once you become active.

## 2.1 Complete monetisation timeline (forum_id 11, all 79 announcement threads enumerated)

Every thread in News & Announcements was listed (`select … from threads where forum_id=11`). The
commerce-relevant ones, in chronological order:

| created | thread id | title | what it establishes |
|---|---|---|---|
| 2020-09-24 | 211441 | Trophies | post **3616965**: "we enabled trophies … They're points you earn, just for entertainment — **they don't give you anything special**" |
| 2020-09-02 | 198141 | Updated Theme & Reputation Changes | post **3395523**: "**Daily Reaction Limit is now set to 100, up from 50** … to discourage reaction farming" — reactions are a rationed resource |
| 2021-02-09 | **290986** | VIP Supporters (Avatar frames, name changes, and more) | the product (above) |
| 2021-03-06 | 310308 | New Payment Method for VIP: Credit Card & PayPal | post **5257678**: "After lots of paperwork and dealmaking, we have added a new payment method for VIP: **Credit Card and Paypal**" |
| 2021-11-18 | 420995 | [Poll] Should you be able to hide your time online? | invisible-mode was **polled, not sold** (55 replies) |
| 2022-09-02 | 560338 | What changes would you like to see made to this forum? | 482 replies — the demand list, §3 |
| 2022-12-25 | 629297 | Ban Forgiveness | amnesty as an engagement event (132 replies) |
| 2023-01-17 | 645445 | VIP Discounted by 25% for a few days! | post **10307611**: "I have discounted all VIP packages by 25% … **use coupon code 25OFF**" |
| 2023-02-17 | **665781** | Lifetime VIP and Lifetime VIP+ and discounts! | post **10571773** — the price list, §4 |
| 2023-07-06 | 764175 | Post 4th of July Sale - 30% OFF all VIP Plans | post **11932143**: "discounted all VIP packages by 30% … **coupon code 30OFF**" |
| 2023-11-23 | 882245 | Thanksgiving forgiveness Unbans | recurring amnesty (repeated 2025 as thread 1737728) |
| 2024-05-18 | 1039204 | Contest: Win Lifetime VIP for Creating Unique Guides! | post **15616339**: "I will be giving away **5 LIFETIME VIPs (frame color of your choice)**" — content bounty paid in SKUs |
| 2024-07-02 | 1092255 | Contributor Rank Contest (Closed) | post **16328155**: "I will be giving out two Contributor Ranks … You'll get the **blue checkmark**" |
| 2024-07-02 | **1075981** | Verification Badges | post **16116345** — the badge taxonomy, §2.4 |
| 2024-10-01 | 1171465 | Looksmax.org 6th Anniversary Celebration Raffle | post **17380528**: "3-day VIP sale for **50% OFF with code 50OFF**. Anyone who purchases VIP during this sale will automatically be **entered into a raffle** to win one of 3 Lifetime VIP Packages" |
| 2024-10-05 | 1174870 | Looksmax 6th Anniversary Raffle Results | post **17426928**: "If you already have Lifetime VIP, you can either choose to keep it **to switch avatar frame color** or you can **gift it to someone else**" |
| 2024-12-14 | 1241541 | VIP Announcement: I Will Add More Colors For VIP Members—Vote! | post **18287756**: "Colors most likely gonna get added: **VIP Orange, VIP Pink, VIP Black**. … Let me know some suggestions" — colour roadmap by community vote |
| 2025-04-21 | 1411700 | New Modern Themes! | post **20462131** — themes shipped free |
| 2025-11-07 | 1701852 | 2025 Edition VIP Announcement! New Colors Has Been Added By NumbThePain | post **24166405**: "The new colors are: **VIP Until Dawn, VIP Blood, VIP Biohazard, VIP Raspberry**" |
| 2025-12-19 | 1772806 | ORG SMP Announcement | post **25024226** — Mogcoin economy, §2.7 |
| 2026-04-07 | 1997696 | Verified Creator Program - Revamp & Coordinator Role Open | post **27797815**: "**VIP status (active as long as you remain a Verified Creator)** … minimum 5,000 followers … create at least one piece of content promoting the forum every 90 days" — VIP as affiliate compensation |
| 2026-04-20 | 2031946 | The Current Sponsored Campaign with $AINI | post **28210034**: "What you're seeing is a **sponsored campaign** that was sent out **via DM/email and placed on the site** … If you don't like sponsored posts, you can ignore them." |
| 2026-05-05 | 2058711 | Partner form for Advertisers and Influencers | post **28513618**: "forms linked at the bottom of the site as **'Partners' and 'Collab'** for advertisers or influencers … Brands that want to grow awareness … people who want to sell products to our audience (supplements, courses, etc.)" |
| 2026-07-25 | 2261044 | Advertise with us! Reach the largest looksmax and incel audiences on the internet! | post **30874951**: cross-property ad sales for **incels.is + looksmax.org** via a Google Form |
| 2026-08-12 | 2307214 | Six New Animated VIP Color's Dropped | = live discussion 329 |

## 2.2 Category counts (both regex passes, verbatim numbers)

Pass A — broad keyword pass (naive; kept because the brief asked for these exact terms, but note the
noise column):

| category | posts matched (of 383,370) | thread titles matched (of 2,205,192) | noise verdict |
|---|---|---|---|
| credits | 878 | 340 | **~all noise** — "credit card", "credit where credit's due". Zero forum credits. |
| points shop | **0** | **1** | **the board never had a points shop** |
| points | 9,907 | 4,763 | mostly "PSL points", "trophy points" |
| upgrade | 323 | 340 | mixed; "purchased upgrades" profile field is real |
| premium | 295 | 208 | mostly "premium snap"/"Spotify Premium" |
| VIP | 752 | **2,092** | real |
| donator/donate | 342 | 425 | mostly blood/plasma/charity |
| custom title | 30 | 79 | real, free feature |
| username colo(u)r | 66 | 330 | real |
| avatar | 983 | 2,118 | real |
| signature | 399 | 718 | real |
| invisible mode | 630 | 434 | ~all noise (see refined pass: **1**) |
| PM limit | **0** | 1 | never sold as a standalone |
| upload limit | 3 | 4 | never sold |
| boost | 2,347 | 1,547 | all "testosterone boost" etc. — **no thread boosts existed** |
| highlight thread | 0 | 0 | **never existed** |
| sticky | 476 | 158 | mod-granted, never sold |
| raffle | 9 | 10 | real, staff-run |
| giveaway | 247 | 307 | real, huge |
| shop | 423 | 440 | ~all external peptide/gear shops |
| store | 1,014 | 978 | ~all external retail |
| buy | 8,185 | 9,867 | mixed |
| sell/sold | 3,092 | 1,991 | mixed |
| price/cost | 7,811 | 3,577 | mixed |
| `$<digit>` | 3,233 | 2,365 | mixed |
| subscription | 225 | 301 | mostly Spotify/YouTube |
| membership | 139 | 99 | mixed |
| lifetime | 652 | 457 | real |
| rank/usergroup | 332 | 602 | real |
| badge/trophy | 264 | 376 | real |
| banner | 143 | 382 | real |
| nickname change | 109 | 609 | real |
| ad-free | 5 | 9 | real, VIP-bundled |
| private forum/section | 3 | 13 | real, VIP-bundled |
| unban | 139 | 673 | real, **grey market** |
| gift | 578 | 864 | real |
| crypto payment | 4,023 | 1,995 | mixed |
| paypal/stripe | 396 | 215 | mixed |
| coupon/discount/sale | 499 | 608 | real |
| trophy points | 2 | 19 | real, free |

Pass B — high-precision phrases (the numbers to trust):

| category | posts | thread titles |
|---|---|---|
| VIP purchase language ("bought/buy/renew/subscribe … VIP", "VIP costs/price/expired") | **136** | **693** |
| "upgrade page" / `account/upgrades` | 5 | 1 |
| VIP colour names / "custom name color" | **100** | **347** |
| avatar frame | 8 | 7 |
| name change (paid) | 50 | **533** |
| exclusive/VIP subforum, VIP lounge | 13 | 26 |
| ad-free | 31 | 57 |
| signature perk | 338 | 532 |
| conversation/PM limit | 34 | 15 |
| ratings-thread deletion | 23 | 79 |
| lifetime VIP | **165** | 183 |
| VIP gifting / "free vip" begging | 59 | **195** |
| VIP giveaway | 118 | 153 |
| VIP sale / discount code | 23 | **232** |
| crypto payment ↔ VIP/upgrade | 293 | 101 |
| PayPal/card/Stripe ↔ VIP/upgrade | 103 | 23 |
| donation | 176 | 313 |
| account buying/selling | 101 | 92 |
| rep buying/selling/farming | **153** | **201** |
| paid unban | 11 | 6 |
| paid staff position | **0** | **0** |
| custom title | 26 | 68 |
| badge/trophy/banner as purchasable | 6 | 8 |
| invisible mode | **1** | 2 |
| upload/attachment limit | 17 | 3 |
| sticky/pin a thread | 87 | 68 |
| highlighted thread | 9 | 1 |
| **forum currency / points shop / credits shop** | **26** (all noise but 2) | **6** |
| **a forum shop/marketplace section** | **0** | **0** |
| external peptide/gear shop | 155 | **687** |

**The single most important negative result: the source board never had a currency, a points shop,
a marketplace, thread boosts, or highlighted threads.** `points_shop` = 0 posts / 1 title;
`shop_section` = 0 / 0; `highlight_thread` = 0 / 0 in the broad pass. Everything sold was a
**subscription entitlement bundle (VIP) plus advertising.**

## 2.3 SKU inventory — the VIP colour catalogue, assembled from the scrape

| Wave | Colours added | Citation |
|---|---|---|
| 2021 launch | Red, Blue, Yellow, Green (username **+ matching avatar frame**) | post **4933638**; demo posts 4933640 / 4933645 / 4933643 / 4933658 |
| 2024-12 | **Orange, Pink, Black** ("Colors most likely gonna get added") | post **18287756** (thread 1241541) |
| 2025-11 | **Until Dawn, Blood, Biohazard, Raspberry** | post **24166405** (thread 1701852) |
| 2026-08 | **Azure, Winter (animated), Slime (animated), Striped (animated), Ajax (animated), Away** | post **24166405**'s successor = live post **9620** / scrape post **31400921** (thread 2307214) |
| also referenced | Cosmic, Fuchsia, Storm, Purple, "rainbow" | live post 10271 "stuck on cosmic"; live post 10281 "Fuchsia mogs"; post **23701748** "mogs the **rainbow name color** … im legit the only user on the entire site with it" |

Roll-up — **22 distinct colour SKUs named in evidence**: Red, Blue, Yellow, Green, Orange, Pink,
Black, Until Dawn, Blood, Biohazard, Raspberry, Azure, Winter\*, Slime\*, Striped\*, Ajax\*, Away,
Cosmic, Fuchsia, Storm, Purple, Rainbow (\* = animated). The brief's Storm/Purple/Cosmic appear in
user speech (live posts 10271, 10281) rather than in an announcement post, and no announcement in
the scrape ever prints a per-colour price — colours are bundled, never itemised.

Colour is a **rank-overriding** cosmetic ("Upgrading will override your current rank color, no matter
what it is", post 4933638) — i.e. the paid cosmetic outranks the earned one. Free ranks are earned
at 500-post intervals (post **1**), and users track them: post **18288494** *"ask them when I get the
**glowing red name** I'm already at **70k posts**"* → post **18288527** staff: *"I just gave it to you."*

## 2.4 Badge / verification SKUs (thread 1075981, post 16116345)

> **Administrator** — black verification tick. **Moderator** — purple tick.
> **Verified** — light blue checkmark; "minimum following of **2,500** on any major social media …
> must verify your identity with proof".
> **Contributor** — dark blue checkmark **and Lifetime VIP status for as long as you hold the
> contributor title**; "Any member that has been here for 6 months are eligible to apply".
> **Sponsor** — "someone that is an **Ad Partner or has contributed lots of money to the forum**. Pink checkmark."
> **Lifetime VIP** — "All lifetime VIPs will receive a **dollar sign** signifying that they have donated to the forum. **You can always have this removed** by contacting me directly."

Corroboration that the pink Sponsor tick reads as a purchased status: post **31333525** *"watching
his **pink vip badge** get exposed to he 100$ and not generational wealth"*; post **31370634**
*"even has his own **sponsor badge** … He says he never unlocked the **vip lounge** 'before'"*.

## 2.5 What the entitlement actually gates (verbatim user evidence, not marketing copy)

| Perk | Verbatim quotes |
|---|---|
| Rating-thread deletion | post **15739627**: "**Pay for VIP. Only way to delete a rating thread.**" → post **15739653**: "i'm a poorcel saaarrr PLEASE DELETE MY THREAD"; post **28689508**: "you can only delete threads in the rating section but thats only w vip lmao"; post **24212058**: "I had some crypto to spare and **I need to deleted shit from ratings and change my user**." |
| Name change | post **9819453** (staff, in the 2FA thread): "**You can upgrade to VIP if you want it.**"; post **31352313** (German subforum): "**Du musst namechange oder vip kaufen zum ändern**" ("you have to buy a namechange or VIP to change it") — implies a **standalone name-change SKU** exists alongside VIP; thread **1690970** "PLEASE I NEED VIP FOR NAME CHANGE" (31 replies) |
| Exclusive subforum | thread **722644** "VIP subforum has INSANE hidden knowledge, gatekeeping is real" (25 replies); thread **1035228** "Where's the vip subforum" (16); thread **1137765** "Just copped lifetime VIP with the blue frame—where's the VIP subforum?"; post **15616461**: "The vip subforum doesn't work bro i wanna see the exclusive high IQ threads" |
| Ad-free | post **4933638** (perk text) + live post **9648** (ads "in between each comment (unavoidable)" for non-VIP) |
| Avatar frame | thread **1595875** "How do I get the frame around my avi" (12); thread **1409714** "Why is my avatar frame gone?" (9); **frame colour is re-selectable** for existing Lifetime VIPs (post 17426928) |
| Post/thread editing beyond 4h | post **15324676**: "**bought vip so i can edit older threads** just in case i also have money to throw away and like the color orange" |
| Status / respect | post **26915985** (a greycel onboarding guide): "Everything matters on this forum, your username matters, your avi matters, your banner matters, your signature matters, your join date matters, your post-rep ratio matters, **and your username color matters. VIP Advantages: Gains respect from fellow greycels and some colorcels** … purchase a VIP (**lifetime is recommended, but you can purchase a temporary VIP if you are financially poor**)" |
| Purchase count is public | post **31120547**: "15,000+ post and **4 upgrades purchased** nigga"; post **31113179** quotes a profile card verbatim: "Posts 81 · Reputation 83 · **Points 1** · **Purchased upgrades 1**" |

## 2.6 Payments, coupons, gifting, giveaways, raffles

- **Crypto first, cards later.** post **4933638**: "Currently we only accept crypto"; post **5257678** (2021-03-06) adds "Credit Card and Paypal". post **24212058**: "I had some **crypto** to spare".
- **Coupon codes are first-class:** `25OFF` (post 10571773, post 10307611), `30OFF` (post 11932143), `50OFF` (post 17380528).
- **Sale + raffle bundle:** buying during the anniversary sale auto-enters a raffle for 3 Lifetime VIPs (post **17380528**), results in post **17426928**.
- **Gifting is a real flow, not a hack:** post **17426928** "you can **gift it to someone else**"; post **23701748** "**5 of the account upgrades are gifting VIP to other people**, i only bought lifetime once myself"; post **31265131** "I … have given him money via **vip and gifting vip**".
- **User-run giveaways are a whole genre**: 285 thread titles match giveaway/raffle. Top by replies: **2099587** "x3 VIP GIVEAWAY + 5€ PayPal | GTFIH | Hosted by @Mast" (319 replies), **2215432** "5x LIFETIME VIP GIVEAWAY ($1000+ value)" (267), **2001856** "GreyGuessr.org … (vip giveaway for the most active user)" (266), **1980868** "🚀MASTER - LIFETIME VIP GIVEAWAY 🚀" (220), **2178351** "**1 Month VIP+** Giveaway" (217), **1906014** "🎁LIFETIME VIP GIVEAWAY🎁" (206), **2044246** "🏆GIVEAWAY 2x LIFETIME VIP🏆" (186), **1443613** "Gifting 1 month VIP to the first one who can solve my riddle" (169), **665829** "I will be gifting 2 random users Lifetime VIP today!" (121).
- Giveaway mechanics are copy-ready — post **25087109** (thread 1777797): "The giveaway will end in **24 hours** · **Users without VIP will have a higher chance of winning** in the draw · winners can choose **any colored frame or no colored frame at all**".
- **Begging for VIP is its own thread genre**: thread **1188438** "HOW TO GET FREE VIP (OFFICIAL GUIDE!!!)", **1410808** "someone gift me vip", **1410822** "can a rich nigga gift me vip pls", **722665** "Maybe a rich User could Upgrade my Account by buying me VIP?", **597238** "users who show their face here must get free VIP", **818426** "I will post this forum link on my class group chat if mods give me free vip".

## 2.7 Advertising, sponsorship and the off-forum economy

- **Sponsored threads are a product.** Titles carry a literal `[SPONSORED]` prefix: **2173183** "[SPONSORED] Follow The Ascension - Hollywood Protocol (streams + weekly updates)" (81 replies), **2173176** "[SPONSORED] Free Canthal Tilt Calculator - Hollywood Protocol" (74), **2173170** "[SPONSORED] Hollywood Protocol - @hollywoodngl's public ascension log" (68), **2219247** "[SPONSORED] Announcing DirtCheatLabs.com! The best lab tests at honest prices!", **2245388** "[SPONSORED] BLOXFLIP — THE #1 COMMUNITY-DRIVEN SOCIAL CASINO".
- User read of the ad business, post **30468475**: "**All the shitty websites come to org and pay master for advertisements** so retards fall for it".
- DM/email blast campaigns are sold too — post **28210034** ($AINI campaign "sent out via DM/email and placed on the site").
- Ad inventory demand from users themselves: post **30909591** (in the advertise-with-us thread): "**Can we get some porn ads on the side of .org like pornhub?**" → reply "We need this guy to sponsor .org".
- **Mogcoin** — the only real currency in the ecosystem, and it lives **off** the forum. post **25024226** (thread 1772806): "**Mogcoins = main currency. Same Mogconomy used on Discord. Your Mogcoin balance is shared between Discord and ORG SMP. One balance across both platforms.** … Earning Mogcoins: Mining, Farming, Bulk selling resources, Trading rare items, **Killing players with bounties** … Example: **Diamond ≈ 5000 Mogcoins / Sugarcane ≈ 10 Mogcoins** … **Gambling**: Blackjack, Roulette, Coinflips, Item gambling … **Bounties**: Pay Mogcoins to put a price on someone's head … **Forum roles transfer automatically. VIP, Lifetime VIP, Supporter sync 1:1 to the SMP.** They provide: Bigger daily Mogcoin payouts, Faster mining/farming, Reduced cooldowns, Access to exclusive perks and shops … **Battlepasses** … **Monetization: Some features cost Mogcoins. Some cost real money.**" (25 posts in the whole DB mention "mogcoin"; the forum itself has no balance.)
- **Grey market the board did not capture** — paid unbans: post **31404567** "The forum faggot @Starborn is back after paying Numb to unban him … **paid $200 for an unban**"; post **31242909** "is it true he **payed 500 dollars to get unbanned**?"; post **29846957** "**Paid a staff member to unban him** and then got him demoted"; post **30609423** "i dont care how much this retard is paying you to stay unbanned **i will pay double to get him banned**"; post **5260993** (asked directly under the payment-methods announcement) "**Can i pay to be unbanned** from incels.is". Account sales: post **14121887** "Explain why op not only **bought a vip** but **bought this fucking account** To flex his join date."
- **Reputation is farmed and traded but never sold by the house**: thread **1007122** "Guide to Rep Farm like a pro on looksmax.org" (post **15184368**: "**#7 Get VIP on here**, people will remind you of your poor financial decision of paying for a badge on an incel forum"), thread **1397519** "HOW I AMASSED 10K REP IN 1.5 WEEK". Repfarming is explicitly **rule-banned** (post **1**: "Repfarm … Do not manipulate the reputation system for gain") — i.e. demand exists and is being suppressed rather than priced.

## 2.8 Awards / recognition SKUs given away free

- **FUOTY (Forum User Of The Year)** — post **18293639**: "The final winner will be our Forum User Of The Year and will get to proclaim that **in their custom title and signature**"; post **13930823**: "You may now **change your custom title to '2023 FUOTY Winner'**" and, in the same post, a direct product request: "**@Master, if you add the badges feature, please add the FUOTY badges for the winners, 2nd and 3rd place for FUOTY for each year**".
- **Contributor rank** (post 16328155) = permanent Lifetime VIP for as long as held (post 16116345).
- **Verified Creator** (post 27797815) = VIP as payment for off-site promotion, revocable after 90 days of inactivity.

---

# 3. What users asked for and did not get

Primary source: **thread 560338 "What changes would you like to see made to this forum?"**
(482 replies; 50 post bodies in the scrape), opened by `Master` (post **9145554**: "What changes
would you like to see made to this forum in general? New features? New subforums? Let us know below.")

**Economy / store asks (never shipped):**

| Ask | Verbatim | Row |
|---|---|---|
| Free or cheaper cosmetics | "**Free username changes bro** Or can I please have a **shiny blue username** bro I beg" | post **9146041** |
| Free colours | "**make username colours free.**" | post **9189453** |
| More colour SKUs for free users | "**add new striking color roles**" | post **9145971** |
| **Gacha / lottery for cosmetics** | "**Give greycels the opportunity to gain robust colors with a lottery spin, one role per acc**" | post **9145975** |
| Forum NFTs | "**Forum NFTs so I can flex on the broke bois here by reppin' the most expensive one**" | post **9148562** |
| Forum cryptocurrency | "**It's time for the forum to launch its own cryptocurrency** Cdc_ChadCoin" | post **9149163** |
| Weekly award | "**Greycel of the week award**" | post **9145579** |
| Member of the week, computed | "**Forum member of the week** (posted in the announcements section) Equation: Total views in a given week x (Positive emotes + comments)" | post **9151928** |
| Reactions as content | "Make a **cope react**", "Add all the **lookism.net full size GIF reacts**", "More wojak/pepe/emoticon options like what's on .is" | posts **9145588**, **9146019**, **9147327**, **9180811** |
| Profile pinning | "**People should be able to pin posts and comments on their profile.**" | post **9151928** |
| Livestreams | "**The ability to host livestreams would be cool**" | post **9151928** (quoted at 9179795) |
| Self-lockout timer | "**The option to restrict your daily time on here by yourself** … during the rest of the day it's as if I am banned" | post **9150937**, echoed 9152208 |
| Visual distinction for pinned threads | "Also make **Pinned threads stand apart more visually** … it would probably need a vote." | post **9145933** |
| Watched-user list + Reddit-style sorting | "#1 **List of watched-users** … #2 something similar to reddit's '**top: hour/today/week/month**'" | post **9181079** |
| Better section taxonomy | "can we get an actual **hobby section**", "divide the forum into more subforums like on kiwifarms", "a section for **Gym and Nutrition**", "a subforum … **Self-Improvement**" | posts **9149288**, **9151040**, **9151470**, **9152294** |
| Signature restriction (anti-perk!) | "**Removal of signatures. Or text only signatures.** Some people put fucking imgur or video or tiktok, its annoying asf" | post **9149197** |
| Archive/backup | "please make an **official archive/backup of this site** … so many important things will be gone if this site crashed like lookism did" | posts **9180780**, **9180830** |

**Monetisation asks from users, unbuilt:**

- post **28518123** (thread 2058711, under the advertiser-partner announcement) — the most complete
  product spec anyone on the board ever wrote:
  > "It only took 8 years for Master to realize that he could make a lot more money … With such an
  > online audience of 1.5-2k users at the same time daily, the forum should be full of monetization
  > methods, like **custom Badges, custom names, custom options, custom reactions emojis, custom
  > frames, custom tags, custom titles, custom profile banners, 30 different VIP types of various
  > ranks and various benefits, all with custom color combinations** etc. If I were him, **I would
  > monetize every pixel on the forum screen, make it somehow customizable, and set a minimum price
  > for it.**"
- post **28518198** (same thread): "would be cool to see master do sum similar to **telegram where VIP
  members can have multiple users you can tag them by** and for there to be a option to have
  multiple users at once" (multi-alias identity SKU).
- post **15652427**: "VIP bhai, **master needs to add more cute colors, effects and what not then
  watch the money come in.**"
- post **18287890** / **18287902** (colour-vote thread): "**Add gradients** they'd look cool asf" →
  "agree **customizable gradients**". Not shipped in the 2025 or 2026 waves.
- post **18287912**: "**can you add them for people who haven't purchased vip yet**"; post **18288347**:
  "can u give **free vip for niggas in 3rd world/developing countries**" (regional pricing ask);
  live post **9621** "give us vip sale".
- post **24166672** (2025 colour thread): "Big respect now **make an option for vip users to be able to
  delete the name history thing**" — a privacy SKU, never shipped.
- live post **9622**: colours should carry "**the same white glow as other unanimated usernames**" —
  cosmetic composition (colour × glow × animation) requested, not offered.
- post **13974567**: "**Username Colours should depend on post:rep ratio** … @Alexanderr @Master" —
  earned-colour ladder alongside the paid one.
- Reaction rationing is a live pain point and an obvious SKU: post **31318254** "**Why cant i fucking
  react why does it say limit reached** u cant even react in peace"; post **31402994** "Now i should be
  able to get rid of that fucking **rep limit**". The daily cap is 100 (post 3395523).
- Private/gated content demand: post **29957682** "I wish a lot more of this forum was inaccessible to
  guests and even newer users. **It would be cool if at least we got a new cosmetic surgery section
  that's private / exclusive**"; post **28030021** "@Master **can we get our subforum already?**"
- More ads, revenue-shared: thread **2220687** "**Master should add more ads and pay us**" (39 replies).

**Thread titles carrying unfetched-but-countable demand** (bodies not in the scrape; titles are the
citation): **271995** "what if serge will turn **REPUTATION in a forum currency**" (14 replies);
**271998** "Imagine adding features like **store** when everyone here askes for a more private site…"
(17); **174003** "**Should we create a looks level currency?**" (7); **996412** "THE NARCY POINTS
SYSTEM, get awarded for your talent!!" (28); **1159473** "**Admins should add a feature that lets you
add music to your profile**" (34); **1498668** "FORUM SUGGESTION: Add a rainbow section"; **1887213**
"Forum suggestions MEGATHREAD | give a suggestion, and I'll put it in a suggestion thread" (25);
**1442638** ".org suggestion. [GTFIH actually good]" (38); **1588695** ".org suggestion; censor out
curse words and have chat filters" (12); **1896627** "My looksmax.org suggestion was successful" (12);
**1215495** "I Have A Lot Of Forum Suggestions"; **667924** "PLEASE ADD A POST WORD FILTER" (62);
**912616** "Can we make a gymcelling subforum?" (41); **75831** "You should be allowed to change your
name once on this site" (14). Explicit-suggestion title search returns **13** `.org/forum suggestion`
threads and **511** "should add / please add / can we get / we need a" titles.

Scale of the request corpus: the precision pass for *ask-verb within 80 chars of a forum-feature noun*
returns **850 post bodies** and **1,419 thread titles**.

---

# 4. Pricing signals — every verbatim price found

**House prices (staff, authoritative):**

| Price | Verbatim | Citation |
|---|---|---|
| **$99** Lifetime VIP · **$124.99** Lifetime VIP+ | "our newest VIP packages **Lifetime VIP and Lifetime VIP+**, where you can just pay one time for VIP and have it for the lifetime of the forum. **Lifetime VIP will be $99 and the ones with the frames will be $124.99.** I will also be throwing all VIP packages on sale for the next 3 days for **25% off** … **Use discount code 25OFF** for 25% off all upgrade packages." | post **10571773**, thread 665781 |
| **25% off** | "I have discounted all VIP packages by 25% … **coupon code 25OFF**" | post **10307611**, thread 645445 |
| **30% off** | "discounted all VIP packages by **30%** … **coupon code 30OFF**" | post **11932143**, thread 764175 |
| **50% off** + raffle entry | "3-day VIP sale for **50% OFF with code 50OFF**" | post **17380528**, thread 1171465 |
| **$17 / month** (current, live) | "fuck you mean **17 dollars for a month**? That's like 18k pesos" | live post **12207**, discussion 532 |

**Member-quoted prices (secondary but consistent):**

| Price | Verbatim | Citation |
|---|---|---|
| **$15/month** | "It's not my fault your a broke retard who **can't afford $15** like the rest of us." / "broke cus im not spending **15 bucks monthly** on FREE forum pip" | posts **24212058**, **24214383** |
| **$12** | "nigga u have vip with 80 posts" → "**its $12**" | post **31113204** |
| **$150** perceived value | "Makes it **worth $150** tbh" (on the 2025 colour drop) | post **24178479** |
| **$100 colours** vs earned colour | "is the **30k color** cooler ? Or is the **VIP 100 dollar colors** cooler ? Which personally catches your eye ?" | post **20453940**, thread 1410885 |
| **$100** badge | "watching his pink vip badge get exposed to he **100$** and not generational wealth" | post **31333525** |
| **$300** custom colour + **$150** base lifetime | "Random user of my choosing: (**$300 custom color name + lifetime VIP**) · Whoever makes the best thread/guide relevant to looksmaxxing: (**$300 custom color name + lifetime VIP**) · Best guide/post relevant to nootropics: (**$150 base lifetime VIP**) · … moneymaxxing: (**$150 base lifetime VIP**) · Best unfrauded ascension: (**$150 base lifetime VIP**)" — headline "**(1000$ Worth Of VIP)**" | live post **8064** (discussion 295) = scrape post **30356738** (thread 2215429) |
| **$1000+** bundle value | thread title "5x LIFETIME VIP GIVEAWAY ($1000+ value) THREAD MAKING CONTEST" | threads **2215429** / **2215432** |
| **$200** paid unban (grey market) | "@Starborn **paid $200 for an unban**" | post **31404567** |
| **$500** paid unban (alleged) | "is it true he **payed 500 dollars to get unbanned**?" | post **31242909** |
| **5€** cash side-prize | thread title "x3 VIP GIVEAWAY + **5€ PayPal**" | thread **2099587** |
| **Mogcoin** exchange rates | "**Diamond ≈ 5000 Mogcoins / Sugarcane (can be automated) ≈ 10 Mogcoins**" | post **25024226** |

**Derived price ladder** (the only one the evidence supports):
$12–$17 / month → $99 Lifetime → $124.99 Lifetime + frames → $150 "base lifetime VIP" street value →
$300 custom colour name → discount codes at 25 / 30 / 50 % on a 1–3 day timer with a countdown banner
("4 hours left bolded in big red font across the main screen", live post **9626**).

---

# 5. What commercial Flarum extensions monetize

Condensed from `/root/flarum-re/re-flarum/PREMIUM_CATALOG.md` (40 premium packages; source is the
`extiverse/flarum-premium-extensions` manifest) and `/root/forum-research/lane-extensions-raw.md`.

**Price reality check:** neither local file records prices —
`/root/flarum-re/re-flarum/PREMIUM_CATALOG.md:84`: *"Pricing not captured (Floxum listings are
Cloudflare-gated; **model is subscription/per-license**)"*, and `:5`: *"you buy a **subscription**
(Floxum/Extiverse) or a **per-extension license** (KILOWHAT sells direct), receive a Composer auth
token, and install from a **private Composer repo**"*. I re-probed live on 2026-08-13:
`https://floxum.com/extensions` returns a Livewire SPA whose HTML contains **no price strings**, and
`https://kilowhat.net/flarum/audit-log/` 404s (554 bytes of text). So the price column below is the
*model*, not a scraped number — flagged rather than invented.

| # | Feature monetized | Vendor / package | Price model | Cite |
|---|---|---|---|---|
| 1 | **Cosmetic store — buy avatar frames / username styles with forum currency** | Ziiven `flarum-decoration-store` | per-license (unpublished) | PREMIUM_CATALOG.md:63 |
| 2 | **Paywall — hide post content behind payment/points** | Ziiven `flarum-pay-to-see` | per-license | PREMIUM_CATALOG.md:64 |
| 3 | **Payments / paid memberships / gated content** | Blomstra `payments` | subscription | PREMIUM_CATALOG.md:44 |
| 4 | Realtime WebSockets (live posts, typing, presence, push) | Blomstra `realtime` | subscription; Packagist 404 | PREMIUM_CATALOG.md:43; lane-extensions-raw.md:186, 622 |
| 5 | Realtime (alt) | Kyrne `websocket` | per-license; dead since 2021-08 | PREMIUM_CATALOG.md:61; lane-extensions-raw.md:187 |
| 6 | Audit log (actor/IP/before-after diff) | KILOWHAT `audit-pro` | direct license | PREMIUM_CATALOG.md:16 — **now free**: `flarum/audit` v1.8.0, lane-extensions-raw.md:492, 962 |
| 7 | Advanced form builder | KILOWHAT `formulaire` | direct license | PREMIUM_CATALOG.md:17 |
| 8 | Link previews / OpenGraph cards | KILOWHAT `rich-embeds` | direct license | PREMIUM_CATALOG.md:18 — free equivalent `datlechin/flarum-link-preview`, lane-extensions-raw.md:761 |
| 9 | WordPress SSO | KILOWHAT `wordpress` | direct license | PREMIUM_CATALOG.md:19 |
| 10 | Custom URLs / route renaming | KILOWHAT `custom-paths` | direct license | PREMIUM_CATALOG.md:20 |
| 11 | Photo albums / galleries | KILOWHAT `cimaise` | direct license | PREMIUM_CATALOG.md:21 |
| 12 | Auto-assign badges by rules/activity | justoverclock `auto-post-badge-pro` | subscription | PREMIUM_CATALOG.md:26 |
| 13 | Duplicate-thread detection | justoverclock `check-duplicate-discussions` | subscription | PREMIUM_CATALOG.md:27 — free: `blomstra/flag-duplicates`, lane-extensions-raw.md:503 |
| 14 | Discord live widget | justoverclock `discord-widget` | subscription | PREMIUM_CATALOG.md:28 |
| 15 | Extra per-discussion tagging | justoverclock `discussion-tags` | subscription | PREMIUM_CATALOG.md:29 |
| 16 | Export posts to PDF | justoverclock `export-post-to-pdf` | subscription | PREMIUM_CATALOG.md:30 |
| 17 | Magazine/blog front page | justoverclock `frontend-blog` | subscription | PREMIUM_CATALOG.md:31 |
| 18 | IGDB / IMDb / TheAudioDB / Steam metadata cards | justoverclock `igdb-api`, `imdb-api`, `theaudiodb-api`, `steam-api` | subscription | PREMIUM_CATALOG.md:32-35 |
| 19 | Job-board listings as a content type | justoverclock `job-cards` | subscription | PREMIUM_CATALOG.md:36 |
| 20 | Related-discussion recommendations | justoverclock `related-discussions` | subscription | PREMIUM_CATALOG.md:37 |
| 21 | Auto-screenshot thumbnails for links | justoverclock `website-live-screenshot` | subscription | PREMIUM_CATALOG.md:38 |
| 22 | S3/object-storage assets + avatars | Blomstra `s3-assets` | subscription | PREMIUM_CATALOG.md:45 |
| 23 | SEO / meta / structured data | Blomstra `meta` | subscription | PREMIUM_CATALOG.md:46 |
| 24 | Outbound webhooks (pro) | datitisev `flarum-webhooks-pro` | subscription | PREMIUM_CATALOG.md:52 — free: `fof/webhooks`, lane-extensions-raw.md:141, 969 |
| 25 | Scheduled DB + asset backups | datitisev `flarum-backup` | subscription | PREMIUM_CATALOG.md:53 — free-ish: `acpl/flarum-db-snapshots`, lane-extensions-raw.md:1016 |
| 26 | Maintenance mode + scheduling | datitisev `flarum-maintenance` | subscription | PREMIUM_CATALOG.md:54 |
| 27 | Guest posting / inline quick reply | Convo `guest-posting`, `quick-reply` | per-license | PREMIUM_CATALOG.md:59-60 |
| 28 | Helpdesk / ticketing | V17 Development `flarum-support` | per-license | PREMIUM_CATALOG.md:66 |
| 29 | Auto-moderation rule engine | OrdinaryJellyfish `automod` | per-license; Packagist 404 | PREMIUM_CATALOG.md:67 — free: `askvortsov/flarum-auto-moderator`, lane-extensions-raw.md:493 |
| 30 | **Pin / highlight / feature discussions** | MBL `highlighted-discussions` | per-license | PREMIUM_CATALOG.md:68; lane-extensions-raw.md:915 |

(Also in the catalog but out of the 30-row budget: `kyrne/aegis` 2FA hardening, `maicol07/flarum-oidc-client`,
`ianm/translate`, `glowingblue/localizd`, `davwheat/virtual-authors` — PREMIUM_CATALOG.md:62, 69-72.)

**Ecosystem shape** (PREMIUM_CATALOG.md:76-84): the paid tier clusters into realtime infra,
**monetization on the forum**, enterprise auth, ops/compliance, content enrichment and managed hosting.
Only **two** of 40 premium packages are *forum-economy* products — `ziiven/flarum-decoration-store`
and `ziiven/flarum-pay-to-see`. The gamification/currency layer is essentially **all free**
(lane-extensions-raw.md:463-484): `fof/gamification` 1.6.12 (dl 68,957), `fof/badges` 1.0.5,
`antoinefr/flarum-ext-money` (a single `users.money` float — "**do not use** — it would fork the source
of truth", :472), `xypp/forum-quests` v2.0.4 ("**quests that pay out currency** — a genuinely novel
engagement mechanic", :477), `foskym/flarum-custom-levels`, `huseyinfiliz/leaderboard` (rejected for
un-indexed COUNTs, :475). Note the local audit already assumes an in-house ledger:
"You already own the ledger: `/root/flarum-stack/extensions/looksmax-economy` — `points`,
`lifetime_points`, `rank_slug` on `users`" (lane-extensions-raw.md:465-466).

## 5.1 Obviously valuable things the source board never sold

Each line = a paid feature the market prices, that the scrape shows the board **did not** sell,
with the demand evidence already cited above.

| Not sold | Evidence it was never sold | Evidence of demand |
|---|---|---|
| Any **forum currency / points shop** | `points_shop` 0 posts / 1 title; `forum_currency` 26 noise posts; `shop_section` 0/0 (§2.2) | threads **271995**, **174003**, **996412**; posts **9149163**, **9148562** |
| **Cosmetic store** with à-la-carte cosmetics (the `ziiven/flarum-decoration-store` model) | colours ship only as a VIP entitlement (post 4933638; live post 9630 "All") | post **28518123** ("monetize every pixel"), **15652427**, **9145975** (lottery spin) |
| **Standalone name change** as a SKU | only referenced obliquely in German (post **31352313**) — never announced | 533 thread titles about name changes; thread **1690970** |
| **Thread boost / highlight / bump** | `highlight_thread` 0/0; sticky is mod-granted only | post **9145933** (pinned threads should stand out), 68 sticky-request titles |
| **Ad-free as a separate purchase** | bundled into VIP only (post 4933638) | live post **9648** (unavoidable inter-comment ads) |
| **Reaction / emoji packs, higher react caps** | daily cap 100, free (post **3395523**) | posts **31318254**, **31402994**, **9145588**, **9146019**, **9147327** |
| **Profile customization SKUs** (music, pinned posts, banner packs, multi-alias) | none exist in any announcement | posts **9151928**, **28518198**; thread **1159473** |
| **Paid private sections / paywalled guides** (`ziiven/flarum-pay-to-see`) | only one free VIP subforum, and it was broken (post **15616461**) | posts **29957682**, **28030021**; thread **722644** |
| **Priced amnesty / unban** | staff give amnesty away free (threads 629297, 882245, 1737728) | **$200 / $500** grey-market unbans (posts **31404567**, **31242909**, **29846957**) |
| **Leaderboards, quests, battlepasses on the forum** | exist only in the Discord/Minecraft SMP (post **25024226**) | post **9151928** (member-of-the-week formula); the SMP itself |
| **Gifting as a storefront flow** | gifting happens by manual admin action (post **17426928**) | 285 giveaway/raffle titles; 195 "free vip" titles |
| **Classifieds / vendor marketplace** | `shop_section` 0/0 | **687** thread titles about external peptide/gear shops; sponsored vendor threads (2219247, 2173176) are the ad-hoc substitute |
| **Regional / PPP pricing** | one global USD price | posts **18288347**, **12207**, **12210** (Argentina, Poland) |
| **Tiering beyond VIP / VIP+ / Lifetime** | 3 SKUs in 5 years | post **28518123**: "**30 different VIP types of various ranks and various benefits**" |

---

# 6. Ranked catalogue the evidence supports (top 25)

Ranked by strength of evidence × revenue precedent. Every line cites the row it rests on.

| # | Catalogue item | Why (citation) |
|---|---|---|
| 1 | **VIP subscription, monthly** — the anchor SKU | live post **12207** "$17 for a month"; posts **24212058** "$15", **31113204** "its $12" |
| 2 | **Lifetime VIP** (one-time) | post **10571773** "Lifetime VIP will be **$99**"; 165 posts / 183 titles mention it |
| 3 | **Lifetime VIP+ (with avatar frames)** — the upsell tier | post **10571773** "**$124.99** … the ones with the frames" |
| 4 | **Username colour catalogue, equippable & switchable** (22 named colours identified, static and **animated**) | posts **4933638**, **18287756**, **24166405**, live post **9620**; live post 10285 shows switching |
| 5 | **Avatar frame, colour-matched, re-selectable** | post **4933638**; post **17426928** "keep it to switch avatar frame color" |
| 6 | **Username change** (immediate on purchase, then every 30 days) | post **4933638**; post **9819453**; 533 name-change titles |
| 7 | **Delete-your-own-thread right in Ratings** | post **4933638**; post **15739627** "Pay for VIP. Only way to delete a rating thread." |
| 8 | **Ad-free browsing** | post **4933638**; live post **9648** (ads between every comment for non-payers) |
| 9 | **Exclusive VIP subforum / lounge** | post **4933638**; thread **722644** (25 replies); post **31370634** "unlocked the vip lounge" |
| 10 | **Extended signature rights** (more text/images/emoji/links, bigger font) | post **4933638**; 338 posts / 532 titles |
| 11 | **Raised conversation participant cap (5 → 15)** | post **4933638**; the only PM-limit SKU evidenced |
| 12 | **Extended edit window on old posts/threads** | post **15324676** "bought vip so i can edit older threads"; free window is 4h (post **1**) |
| 13 | **Coupon-code sales on a countdown** (25OFF / 30OFF / 50OFF) | posts **10307611**, **11932143**, **17380528**, **10571773**; live post **9626** ("4 hours left bolded in big red font") |
| 14 | **Gifting a subscription to another user** | post **17426928**; post **23701748** "5 of the account upgrades are gifting VIP to other people" |
| 15 | **Giveaway/raffle mechanics tied to purchase** (buy → auto-entry) | post **17380528**; post **25087109** (non-VIPs weighted higher); 285 giveaway titles |
| 16 | **Verification badge tiers** (Admin/Mod/Verified/Contributor/**Sponsor**/Lifetime-`$`) | post **16116345**; post **31333525**; post **31370634** |
| 17 | **Custom title** (currently free; awarded as a prize) | posts **18293639**, **13930823**, **26074821** |
| 18 | **Sponsored / `[SPONSORED]` thread placements + DM/email blasts** | threads **2173183**, **2173176**, **2173170**, **2219247**, **2245388**; post **28210034**; post **30468475** |
| 19 | **Advertiser & influencer partner intake ("Partners"/"Collab" forms)** | posts **28513618**, **30874951** |
| 20 | **Creator/affiliate programme paying in VIP** (5k followers, content every 90 days) | post **27797815**; earlier tier post **16116345** |
| 21 | **Contributor rank = Lifetime VIP for as long as held** | posts **16116345**, **16328155** |
| 22 | **Content-bounty contests paid in SKUs** (5× Lifetime VIP for guides; $300 custom colour prizes) | posts **15616339**, **30356738**/live **8064** |
| 23 | **Crypto + card/PayPal checkout, crypto-first** | posts **4933638**, **5257678**, **24212058** |
| 24 | **Public "Purchased upgrades" count on the profile card** (status ledger, drives repeat buys) | post **31113179** ("Purchased upgrades 1"), post **31120547** ("4 upgrades purchased") |
| 25 | **Rank ladder every 500 posts + reputation + trophies** (free substrate the paid colour overrides) | post **1**; post **3395523** (react cap 100); post **4933638** ("override your current rank color") |

**Ranked 26-32, unsold but evidence-backed (build these to beat the source board):** à-la-carte
cosmetic store with a currency (post 28518123); colour gacha/lottery spin (post 9145975); animated +
glow + gradient composition (live post 9622, post 18287890); reaction-cap and emoji-pack SKUs
(post 31318254); paid private/gated sections (post 29957682); profile music / pinned posts /
multi-alias (posts 9151928, 28518198); regional pricing (posts 18288347, 12207).
