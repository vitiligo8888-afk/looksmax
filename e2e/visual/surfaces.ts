/**
 * The surfaces the visual sweep walks.
 *
 * Chosen so that every construct the theme styles is on at least one of them:
 * tiles, list rows, posts with images, posts with quotes, a profile, search
 * results, an empty state, a modal, and the whole logged-in chrome. Sampling a
 * subset of these is how a defect reaches the operator instead of the report.
 *
 * `auth: 'in' | 'out' | 'both'` — the logged-in header, the composer, the
 * notification tray and the session dropdown do not exist logged out, so a
 * logged-out screenshot proves nothing about them. Default is 'both'.
 */
export type Surface = {
  name: string;
  path: string;
  /** run only at these viewport names; default is all */
  only?: string[];
  /** in-page action to perform after load, before capture */
  after?: string;
  /** scroll to this y before capture (px), or 'images' to park media under the header */
  scroll?: number | "images";
  /** which session this surface is meaningful in */
  auth?: "in" | "out" | "both";
};

/**
 * Breakpoints.
 *
 * `desktop` and `mobile` are 1440x900 and 390x844 EXACTLY, because those are
 * the two viewports the above-the-fold composition is specified against and a
 * fold assertion measured at a different height is measuring a different page.
 * `wide` exists because the operator's report was specifically about wasted
 * horizontal space, which only appears once the viewport is wider than the
 * shell.
 *
 * The default run is the three that answer a question. The other three are the
 * breakpoints BETWEEN layouts, where the grid actually changes — they catch
 * different bugs and are worth the minutes, but not on every loop, so:
 *
 *   bun e2e/visual/sweep.ts            desktop + mobile + wide
 *   FULL=1 bun e2e/visual/sweep.ts     all six
 *   VIEWPORTS=laptop bun …             one, for a fast loop
 */
export const VIEWPORTS = [
  { name: "wide", width: 1920, height: 1080 },
  { name: "desktop", width: 1440, height: 900 },
  { name: "laptop", width: 1280, height: 860 },
  { name: "narrow", width: 1000, height: 900 },
  { name: "tablet", width: 834, height: 1000, mobile: true },
  { name: "mobile", width: 390, height: 844, mobile: true },
].filter((v) => process.env.FULL === "1" || ["wide", "desktop", "mobile"].includes(v.name));

/** Discussions picked for content shape, not for being first in the list. */
export const D_IMAGES = process.env.D_IMAGES || "/d/290-hgh-101-everything-you-need-to-know";
export const D_QUOTES = process.env.D_QUOTES || "/d/310-marpe-is-cope-stop-messaging-me-about-it";
export const D_RICH = process.env.D_RICH || "/d/329-six-new-animated-vip-colors-dropped-exclusive-for-vip-members";

/**
 * Scroll far enough that the sticky header is over real content, then sample.
 * A header bug that only shows at scrollY 0 does not exist; every one of them
 * needs the content column to have moved underneath the header.
 */
const SCROLLED = 1400;

export const SURFACES: Surface[] = [
  { name: "index", path: "/" },
  { name: "index-scrolled", path: "/", scroll: SCROLLED },
  { name: "all", path: "/all" },
  { name: "all-scrolled", path: "/all", scroll: SCROLLED },
  { name: "tags", path: "/tags" },
  { name: "tag", path: "/t/f-2" },
  { name: "tag-scrolled", path: "/t/f-2", scroll: SCROLLED },
  { name: "discussion-images", path: D_IMAGES },
  { name: "discussion-images-scrolled", path: D_IMAGES, scroll: "images" },
  { name: "discussion-quotes", path: D_QUOTES },
  { name: "discussion-rich", path: D_RICH },
  { name: "discussion-rich-scrolled", path: D_RICH, scroll: SCROLLED },
  { name: "profile", path: "/u/pinterest" },
  { name: "profile-scrolled", path: "/u/pinterest", scroll: 700 },
  { name: "search", path: "/?q=looksmax" },
  { name: "search-empty", path: "/?q=zzzzqqqnothingmatchesthis" },

  // ---- logged-in only -----------------------------------------------------
  { name: "in-index", path: "/", auth: "in" },
  { name: "in-index-scrolled", path: "/", scroll: SCROLLED, auth: "in" },
  { name: "in-discussion", path: D_RICH, auth: "in" },
  { name: "in-discussion-scrolled", path: D_RICH, scroll: SCROLLED, auth: "in" },
  { name: "in-settings", path: "/settings", auth: "in" },
  { name: "in-notifications", path: "/notifications", auth: "in", only: ["desktop", "mobile"] },
  {
    name: "in-header-session",
    path: "/",
    auth: "in",
    only: ["desktop", "laptop", "mobile"],
    after: `(() => {
      const t = document.querySelector('.SessionDropdown .Dropdown-toggle, .Header-secondary .Dropdown-toggle');
      if (t) { t.click(); return 'clicked'; }
      return 'not-found';
    })()`,
  },
  {
    name: "in-composer",
    path: D_RICH,
    auth: "in",
    only: ["desktop", "mobile"],
    after: `(() => {
      const b = [...document.querySelectorAll('.Post-controls button, .item-reply button, .DiscussionPage button')]
        .find(e => /reply/i.test(e.textContent || ''));
      if (b) { b.click(); return 'clicked-reply'; }
      return 'not-found';
    })()`,
  },

  // ---- overlays -----------------------------------------------------------
  {
    name: "modal-login",
    path: "/",
    auth: "out",
    only: ["desktop", "mobile"],
    after: `(() => {
      const b = [...document.querySelectorAll('.Header-secondary button, .Header-secondary a, .App-drawer button')]
        .find(e => /log ?in|sign ?in/i.test(e.textContent || ''));
      if (b) { b.click(); return 'clicked'; }
      if (window.flarum?.core?.app?.modal) { window.flarum.core.app.modal.show(window.flarum.core.compat['components/LogInModal']); return 'api'; }
      return 'not-found';
    })()`,
  },
  {
    name: "dropdown-session",
    path: "/",
    auth: "out",
    only: ["desktop"],
    after: `(() => {
      const t = document.querySelector('.Header-secondary .Dropdown-toggle, .Header-secondary .Button--icon');
      if (t) { t.click(); return 'clicked'; }
      return 'not-found';
    })()`,
  },
];
