# The user card — one implementation, mounted from one place

`local/looksmax-userinfo` owns the user hover card on this forum. There is
exactly one component (`cardView` in `js/dist/forum.js`), one style
(`.LmxHoverCard` in `less/forum.less`), one configuration (`src/Config.php`,
published on the forum payload as `lmxUserInfo`) and one delegated listener.

**In almost every case another lane needs to do nothing at all.**

---

## How a username gets a card

The listener is delegated from `document` and matches:

```
a[href*="/u/"]          — any link to a profile
[data-lmx-user]         — an element carrying a username
[data-lmx-userid]       — an element carrying a numeric user id
```

If your lane renders a username as `<a href="/u/{slug}">`, the card already
works there. That covers, today, and each is asserted by
`e2e/card-sweep.ts`:

| Call site | Where it comes from | Matched by |
|---|---|---|
| post author rail | this extension, `authorPanel()` | `a[href*="/u/"]` |
| post header / `PostUser` | flarum core | `a[href*="/u/"]` |
| post mentions `@user` | flarum/mentions | `a.UserMention[href*="/u/"]` |
| quote attribution | looksmax-format | `a[href*="/u/"]` |
| discussion list author + last poster | flarum core | `a[href*="/u/"]` |
| index last-poster | looksmax-index | `a[href*="/u/"]` |
| shoutbox name | looksmax-chat `js/chat.js:329` | `a.LmxChat-who[href="/u/..."]` **and** `data-lmx-user` |
| shoutbox `@mentions` | looksmax-chat `js/chat.js:270` | `a.LmxChat-mention[href="/u/..."]` |
| shoutbox avatar | looksmax-chat `js/chat.js:288` | `a.LmxChat-avatar[href="/u/..."]` |
| leaderboard | looksmax-ranks `js/dist/forum.js:508` | `a.LmxLeader-name[href="/u/..."]` |
| notifications | flarum core | `a[href*="/u/"]` |
| search results | looksmax-search | `a[href*="/u/"]` |
| profile header | flarum core `UserCard` | `a[href*="/u/"]` |

## The API, for the cases the selector cannot reach

`window.LmxUserCard` is published once the forum bundle has bound.

```js
// a username that is not a link
LmxUserCard.attach(el, { username: 'Notcel' });   // or { id: 3015 }

// open it yourself, ignoring trigger and delay
LmxUserCard.open(el, { focus: true });
LmxUserCard.close();

// opt a subtree out entirely
LmxUserCard.ignore(el);

// open the DM composer to a user (model or numeric id)
LmxUserCard.message(3015);

// open the inbox
LmxUserCard.inbox();

// the live configuration, read-only by convention
LmxUserCard.config.trigger;   // 'hover' | 'click'
```

`attach()` only sets data attributes. It adds no listener, so calling it twice
is harmless and removing the element leaks nothing.

---

## Implementations this replaced

### 1. `local/looksmax-ranks` — `.LmxHover`

**Status: suppressed, pending a one-line change in that lane.**

- built at `extensions/looksmax-ranks/js/dist/forum.js:319` (`ensureCard`),
  filled at `:337` (`showCard`), attached at `:307` (`attachHover(node, s)`)
  from `decorate()`
- styled at `extensions/looksmax-ranks/less/forum.less:481`, at a bare
  `z-index: 9999` — which is also a token violation (`--z-tooltip` is 1070), and
  is why it painted *over* this card rather than under it
- it shows: name, custom title, rank, tier, rank blurb, badge count.
  All six are on this card already (`rankChip`, `groupNodes`,
  `LmxAuthor-title`, and the badge count via `UserModel.badges`), plus join
  date, post/thread/reaction counts, presence, carried-over standing and the
  actions.

Until that lane changes, `js/dist/forum.js` puts `lmx-usercard-owner` on
`<html>` once this card has bound and `less/forum.less` hides `.LmxHover`. If
this extension is disabled or fails to boot, the class is never set and theirs
comes straight back.

**The one line to delete, in that lane:**

```
extensions/looksmax-ranks/js/dist/forum.js:307      attachHover(node, s);
```

Deleting it makes the suppression a no-op. `attachHover`, `showCard`,
`ensureCard` (`:311`–`:390`) and the `.LmxHover*` rules
(`less/forum.less:481`–`:532`) then become dead and can be removed too, but
nothing breaks if they are left.

### 2. Flarum core's `UserCard` on hover

Core's `PostUser` shows a `UserCard` when the post header's avatar is hovered.
Not deleted — `UserCard` is still the profile page's header, and this extension
appends to its `infoItems`. What is gone is the *hover* instance on a post,
because `less/forum.less` hides `.Post-header .PostUser` outright when the
author rail is present: the rail is the same two facts, four pixels away.

### 3. Nothing in `local/looksmax-chat`

Checked, for the record: `js/chat.js` renders usernames, mentions and avatars
as `/u/` anchors (`:270`, `:288`, `:329`) and has no card of its own. It gets
this one for free. The reason the shoutbox card *looked* separate is that
`looksmax-ranks`' `SELECTORS` list includes `.LmxChat-who`
(`extensions/looksmax-ranks/js/dist/forum.js:267`), so it was `.LmxHover`
appearing there — case 1, not a third implementation.

---

## Configuration

One place: **Admin → Extensions → Looksmax User Info**. Settings are
`userinfo.*` and are read by `src/Config.php`; `js/dist/admin.js` registers the
controls. Nothing in the forum bundle takes a per-call-site option.

| Setting | Default | What it does |
|---|---|---|
| `userinfo.trigger` | `hover` | `hover` or `click`, on pointer devices |
| `userinfo.openDelay` | `320` | ms before it opens |
| `userinfo.closeDelay` | `220` | ms before it closes |
| `userinfo.placement` | `auto` | `auto`, `bottom` or `top`; always clamped into the viewport |
| `userinfo.followScroll` | `true` | close the popover when the anchor scrolls |
| `userinfo.mobile` | `sheet` | `sheet`, `popover` or `off` below the breakpoint |
| `userinfo.mobileBreakpoint` | `767` | widest viewport treated as touch |
| `userinfo.width` | `340` | card width, published as `--lmx-card-w` |
| `userinfo.avatarSize` | `64` | published as `--lmx-card-avatar` |
| `userinfo.fields` | see `src/Config.php` | ordered, comma separated |
| `userinfo.actions` | see `src/Config.php` | ordered, comma separated; each is *also* permission-gated |
| `userinfo.railEnabled` | `true` | the author panel beside a post |
| `userinfo.railWidth` | `200` | published as `--lmx-author-w` |
| `userinfo.dmEnabled` | `true` | direct messages |

Styling is by token, not by arbitrary CSS: everything the card paints resolves
to a custom property from `looksmax-theme/less/tokens.less`, so a change to the
accent moves the card with it.
