#!/usr/bin/env bun
/**
 * The visual gate: one command that renders every major surface at every
 * breakpoint, LOGGED OUT AND LOGGED IN, audits contrast, layering and layout on
 * the real composited page, writes screenshots, and fails the run on a
 * regression.
 *
 * Why this exists: every visual defect on this forum so far was found by a
 * person looking at the site. HTTP 200 and DOM assertions both passed while
 * post images painted over the header, thread titles rendered at 1.1:1, and
 * 393px of dead space sat above every post body.
 *
 *   bun e2e/visual/sweep.ts                    # full sweep, all viewports
 *   VIEWPORTS=desktop bun e2e/visual/sweep.ts  # one breakpoint, fast loop
 *   SURFACES=index,all bun e2e/visual/sweep.ts # a few surfaces
 *   NO_LOGIN=1 bun e2e/visual/sweep.ts         # skip the authenticated pass
 *
 * Exit code is non-zero when a gate fails, so it works as a pre-deploy check.
 *
 * SELF-CHECK: every gate here has been made to fail on purpose. `BREAK=<gate>`
 * re-does that on demand — see the BREAK switch below. A green check that has
 * never gone red is not evidence.
 */
import { mkdirSync, writeFileSync } from "node:fs";
import { Browser } from "./cdp";
import { CONTRAST_AUDIT, LAYER_AUDIT, STRUCTURE_AUDIT, LAYOUT_AUDIT } from "./inject";
import { FOLD_AUDIT, HEADER_HIT, ICON_AUDIT } from "./fold";
import { hoverContrast, type HoverFail } from "./hover";
import { SURFACES, VIEWPORTS } from "./surfaces";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const OUT = process.env.SHOT_DIR || "/work/flarum/visual";
const ONLY_VP = (process.env.VIEWPORTS || "").split(",").filter(Boolean);
const ONLY_SURF = (process.env.SURFACES || "").split(",").filter(Boolean);
const ADMIN_USER = process.env.ADMIN_USER || "admin";
const ADMIN_PASS = process.env.ADMIN_PASS || "";
const NO_LOGIN = process.env.NO_LOGIN === "1" || !ADMIN_PASS;
/** Deliberately break one gate, to prove it can go red. */
const BREAK = process.env.BREAK || "";

/**
 * Known-and-accepted contrast exceptions. Deliberately tiny and each one has a
 * reason: an empty list is the goal, and anything added here without a reason
 * is the check being neutered rather than the bug being fixed.
 */
const ALLOW: { match: RegExp; why: string }[] = [
  { match: /\.Button--danger|\.Alert--error/, why: "destructive colour is a signal; paired with an icon and text label" },
];

type Fail = {
  surface: string; viewport: string; kind: string; sel: string;
  fg: string; bg: string; ratio: number; need: number; text?: string; note?: string;
};

const contrastFails: Fail[] = [];
const contrastAll: Fail[] = [];
const overlapFails: any[] = [];
const railFails: any[] = [];
const glyphFails: any[] = [];
const wrapFails: any[] = [];
const tapFails: any[] = [];
const stylesheetFails: any[] = [];
const netFails: any[] = [];
const consoleFails: any[] = [];
const structure: Record<string, any> = {};
const layerReport: Record<string, any> = {};
const perf: Record<string, any> = {};
const notes: string[] = [];
/* the four audits added for the UI pass */
const hoverFails: (HoverFail & { surface: string; viewport: string; session: string })[] = [];
const iconFails: any[] = [];
const hitFails: any[] = [];
const fold: Record<string, any> = {};
let iconsSeen = 0;
let hoverRan = 0;

mkdirSync(OUT, { recursive: true });

const viewports = VIEWPORTS.filter((v) => !ONLY_VP.length || ONLY_VP.includes(v.name));
const allSurfaces = SURFACES.filter((s) => !ONLY_SURF.length || ONLY_SURF.includes(s.name));

const browser = await Browser.launch(21800, "1600,1200");

/**
 * Probe the site at the URL it thinks it lives at, not at the loopback port.
 *
 * Flarum emits ABSOLUTE asset URLs built from its configured base URL. Probing
 * http://127.0.0.1:8888 while the base URL is the public tunnel therefore makes
 * every stylesheet-referenced font and the web manifest cross-origin, and they
 * fail CORS: this reported 20 "Font net::ERR_FAILED" per run as product
 * defects. Fetched over the real origin the same files are 200, and the run has
 * zero failed requests. Auto-detected rather than hardcoded because the tunnel
 * hostname changes every time cloudflared restarts.
 */
let base = BASE;
{
  await browser.goto(BASE + "/");
  const declared = await browser
    .eval(`(() => { try { return window.flarum.core.app.forum.attribute('baseUrl') || null; } catch (e) { return null; } })()`)
    .catch(() => null);
  if (declared && !String(declared).startsWith(BASE)) {
    const reachable = await browser.goto(String(declared) + "/");
    if (reachable) {
      base = String(declared);
      notes.push(`base url: probing ${base} (configured), not ${BASE} — asset URLs are absolute`);
    } else {
      notes.push(`base url: ${declared} declared but unreachable; staying on ${BASE}`);
    }
  }
}

/** Run one pass (a session) over the surfaces that belong to it. */
async function pass(session: "out" | "in") {
  const surfaces = allSurfaces.filter((s) => (s.auth ?? "both") === "both" || s.auth === session);
  if (!surfaces.length) return;

  for (const vp of viewports) {
    await browser.viewport(vp);
    for (const surf of surfaces) {
      if (surf.only && !surf.only.includes(vp.name)) continue;
      const tag = `${session}:${surf.name}@${vp.name}`;
      browser.resetNetwork();
      const booted = await browser.goto(base + surf.path);
      if (!booted) {
        notes.push(`${tag}: SPA did not boot`);
        continue;
      }

      if (surf.after) {
        const r = await browser.eval(surf.after).catch((e) => `err ${e}`);
        notes.push(`${tag}: after -> ${r}`);
        await Bun.sleep(900);
      }

      if (surf.scroll === "images") {
        // Park real media directly under the sticky header. This is the exact
        // condition the operator reported, so it is reproduced rather than
        // approximated by an arbitrary scroll offset.
        const parked = await browser.eval(`(() => {
          const h = document.querySelector('.App-header');
          const hh = h ? h.getBoundingClientRect().height : 60;
          const img = [...document.querySelectorAll('.Post-body img, .Post-body iframe')]
            .find(e => e.getBoundingClientRect().height > 60);
          if (!img) return 'no-media';
          const y = img.getBoundingClientRect().top + window.scrollY - hh + 40;
          window.scrollTo(0, Math.max(0, y));
          return 'parked@' + Math.round(window.scrollY);
        })()`);
        notes.push(`${tag}: ${parked}`);
        await Bun.sleep(700);
      } else if (typeof surf.scroll === "number") {
        await browser.eval(`window.scrollTo(0, ${surf.scroll})`);
        await Bun.sleep(500);
      }

      // ---- stylesheet sentinel + layout ----------------------------------
      // First, because less.php fails SILENTLY: it throws while compiling,
      // forum.css is never written, and the forum serves with no stylesheet at
      // all while forum.js still builds. Every other gate is meaningless then.
      const yRaw = await browser.eval(LAYOUT_AUDIT).catch((e) => {
        notes.push(`${tag}: layout audit threw ${e}`);
        return "{}";
      });
      const layout = JSON.parse(yRaw || "{}");
      if (layout.stylesheet && !layout.stylesheet.ok) {
        stylesheetFails.push({ surface: surf.name, viewport: vp.name, session, ...layout.stylesheet });
      }
      for (const r of layout.railGap || []) {
        // The body must start under the post HEADER, never under the rail. A
        // 2px tolerance covers sub-pixel rounding on the header's margin.
        if (r.gap > r.expected + 2 || !r.sideBySide) {
          railFails.push({ surface: surf.name, viewport: vp.name, session, ...r });
        }
      }
      for (const g of layout.glyphOverflow || []) if (!g.pass) glyphFails.push({ surface: surf.name, viewport: vp.name, session, ...g });
      for (const w of layout.oneLine || []) if (!w.pass) wrapFails.push({ surface: surf.name, viewport: vp.name, session, ...w });
      for (const t of layout.tapTargets || []) tapFails.push({ surface: surf.name, viewport: vp.name, session, ...t });

      // ---- contrast -------------------------------------------------------
      const cRaw = await browser.eval(CONTRAST_AUDIT).catch((e) => {
        notes.push(`${tag}: contrast audit threw ${e}`);
        return "[]";
      });
      for (const r of JSON.parse(cRaw || "[]")) {
        const row: Fail = { surface: surf.name, viewport: vp.name, ...r };
        contrastAll.push(row);
        if (!r.pass && !ALLOW.some((a) => a.match.test(r.sel))) contrastFails.push(row);
      }

      // ---- icons ----------------------------------------------------------
      // "for notifications the loading spinner thingy is a weird stretched like
      // rotating big icon" — an unresolved <iconify-icon> has no intrinsic size
      // and a flex parent stretches it. Measured against the glyph's own
      // viewBox aspect, not against an assumed 1:1.
      const iRaw = await browser.eval(ICON_AUDIT).catch((e) => {
        notes.push(`${tag}: icon audit threw ${e}`);
        return "{}";
      });
      const icons = JSON.parse(iRaw || "{}");
      iconsSeen += icons.total || 0;
      for (const g of icons.worst || []) iconFails.push({ surface: surf.name, viewport: vp.name, session, ...g });

      // ---- above the fold --------------------------------------------------
      // Only where the question means something: the two surfaces a visitor
      // lands on, at the two viewports the brief names.
      if ((surf.name === "index" || surf.name === "all" || surf.name === "in-index") && !surf.scroll) {
        const fRaw = await browser.eval(FOLD_AUDIT).catch(() => "{}");
        fold[`${session}:${surf.name}@${vp.name}`] = JSON.parse(fRaw || "{}");
      }

      // ---- header hit-testing ---------------------------------------------
      // Every scrolled surface, because "it isn't clickable anymore" only
      // happens once content has moved under the header.
      const hRaw = await browser.eval(HEADER_HIT).catch(() => "{}");
      const hit = JSON.parse(hRaw || "{}");
      for (const c of hit.controls || []) {
        if (c.present && c.sized && !c.ok) {
          hitFails.push({ surface: surf.name, viewport: vp.name, session, scrollY: hit.scrollY, ...c });
        }
      }

      // ---- hover contrast --------------------------------------------------
      // Real pointer, real cascade. Desktop only: :hover is not a state a touch
      // device has, and emulating one there measures a fiction.
      if (!vp.mobile && (vp.name === "desktop" || vp.name === "laptop")) {
        try {
          for (const f of await hoverContrast(browser)) {
            if (!ALLOW.some((a) => a.match.test(f.sel))) {
              hoverFails.push({ surface: surf.name, viewport: vp.name, session, ...f });
            }
          }
          hoverRan++;
        } catch (e) {
          notes.push(`${tag}: hover audit threw ${e}`);
        }
      }

      // ---- layering -------------------------------------------------------
      const lRaw = await browser.eval(LAYER_AUDIT).catch(() => "{}");
      const layers = JSON.parse(lRaw || "{}");
      layerReport[tag] = { header: layers.header, scrollY: layers.scrollY, overlaps: layers.overlaps };
      for (const o of layers.overlaps || []) overlapFails.push({ surface: surf.name, viewport: vp.name, session, ...o });

      // ---- network + console ---------------------------------------------
      for (const b of browser.badResponses) {
        // Flarum answers 404 for a genuinely missing route the SPA probes for;
        // everything else is a defect. Nothing is excluded by type — a 404
        // avatar is exactly the bug this caught on the standing card.
        netFails.push({ surface: surf.name, viewport: vp.name, session, ...b });
      }
      for (const e of browser.failedRequests) netFails.push({ surface: surf.name, viewport: vp.name, session, url: e, status: 0, type: "transport" });
      for (const e of browser.failedImages) netFails.push({ surface: surf.name, viewport: vp.name, session, url: e, status: 0, type: "image-transport" });
      for (const e of browser.consoleErrors) consoleFails.push({ surface: surf.name, viewport: vp.name, session, text: e });

      // ---- structure (desktop only; it is shape, not layout) ---------------
      if (vp.name === "desktop") {
        const sRaw = await browser.eval(STRUCTURE_AUDIT).catch(() => "{}");
        structure[`${session}:${surf.name}`] = JSON.parse(sRaw || "{}");
      }

      // ---- scroll smoothness ----------------------------------------------
      // A background that costs frames is worse than no background.
      if (surf.name === "discussion-images" && vp.name === "desktop" && session === "out") {
        perf.scroll = await browser.eval(`new Promise(res => {
          const frames = []; let last = performance.now(); let n = 0;
          const step = () => {
            const now = performance.now();
            frames.push(now - last); last = now;
            window.scrollBy(0, 40);
            if (++n < 90) requestAnimationFrame(step);
            else {
              const s = frames.slice(2).sort((a,b) => a-b);
              res({ frames: s.length, median: +s[Math.floor(s.length/2)].toFixed(2),
                    p95: +s[Math.floor(s.length*0.95)].toFixed(2), max: +s[s.length-1].toFixed(2) });
            }
          };
          requestAnimationFrame(step);
        })`, true).catch((e) => ({ error: String(e) }));
        await browser.eval(`window.scrollTo(0,0)`);
        await Bun.sleep(400);
      }

      // ---- pixels ---------------------------------------------------------
      const dir = `${OUT}/${session}/${vp.name}`;
      mkdirSync(dir, { recursive: true });
      if (surf.scroll || surf.after) await browser.shot(`${dir}/${surf.name}.png`);
      else await browser.fullShot(`${dir}/${surf.name}.png`);
      process.stdout.write(`  shot ${tag}\n`);
    }
  }
}

await pass("out");

let loginResult = "skipped";
if (!NO_LOGIN) {
  loginResult = await browser.login(ADMIN_USER, ADMIN_PASS, base);
  notes.push(`login: ${loginResult}`);
  if (String(loginResult).startsWith("ok")) await pass("in");
}

// -------------------------------------------------------------------- report
const uniq = <T,>(rows: T[], key: (r: T) => string) => {
  const m = new Map<string, T & { seenOn?: string[] }>();
  for (const r of rows) {
    const k = key(r);
    const e = m.get(k);
    if (e) e.seenOn!.push((r as any).session + ":" + (r as any).surface + "@" + (r as any).viewport);
    else m.set(k, { ...r, seenOn: [(r as any).session + ":" + (r as any).surface + "@" + (r as any).viewport] });
  }
  return [...m.values()];
};

const cUniq = uniq(contrastFails, (r) => `${r.kind}|${r.sel}|${r.fg}|${r.bg}`).sort((a, b) => a.ratio - b.ratio);
const oUniq = uniq(overlapFails, (r: any) => `${r.sel}|${r.z}`);
const gUniq = uniq(glyphFails, (r: any) => `${r.sel}`);
const rUniq = uniq(railFails, (r: any) => `${r.surface}|${r.sideBySide}`);
const wUniq = uniq(wrapFails, (r: any) => `${r.sel}|${r.lines}`);
const nUniq = uniq(netFails, (r: any) => `${r.status}|${String(r.url).replace(/\d+/g, "N")}`);
const eUniq = uniq(consoleFails, (r: any) => r.text.slice(0, 120));
const tUniq = uniq(tapFails, (r: any) => r.sel);
const hvUniq = uniq(hoverFails, (r: any) => `${r.sel}|${r.what}|${r.fg}|${r.bg}`).sort((a: any, b: any) => a.ratio - b.ratio);
const icUniq = uniq(iconFails, (r: any) => `${r.tag}|${r.cls}|${r.name}|${r.problems.join(",")}`);
const hitUniq = uniq(hitFails, (r: any) => `${r.name}|${r.hit}`);

/*
 * The fold verdict.
 *
 * Brief: "ensure it's rich and has what we want front and center the main
 * content of the site, not polluted by bs banners". Turned into three numbers a
 * gate can read, at the two viewports it was specified against:
 *   - at least one whole feed item inside the first viewport
 *   - the first content pixel no lower than 62% of the viewport height
 *   - promotional bands take less of the fold than content does
 * 62% rather than "above the fold" outright: a header, a primary action row and
 * a section strip are legitimately above the first row and measure ~330px at
 * 900. Below that the page is orienting the reader; above it, it is stalling.
 */
const foldFails: any[] = [];
for (const [k, f] of Object.entries<any>(fold)) {
  if (!f || !f.viewport) continue;
  const limit = Math.round(f.viewport.h * 0.62);
  const why: string[] = [];
  if (!f.feedItemsTotal) why.push("no feed items on the page at all");
  else if (!f.feedInFold) why.push(`0 whole feed items in the fold (first starts at y=${f.feedTop})`);
  if (f.feedTop !== null && f.feedTop > limit) why.push(`first content at y=${f.feedTop}, limit ${limit}`);
  if (f.bannerPx > f.contentPx) why.push(`banners take ${f.bannerPx}px of the fold, content ${f.contentPx}px`);
  // The operator's own acceptance criterion for the start-here cards.
  for (const d of f.feedDupes || []) {
    why.push(`feed panel "${d.panel}" renders ${d.cards} cards for ${d.distinct} distinct discussions`);
  }
  if (f.startHere && !f.startHere.inFold) {
    why.push(`first "Por donde empezar" card starts at y=${f.startHere.top}, below the ${f.viewport.h}px fold`);
  }
  if (why.length) foldFails.push({ where: k, ...f, regions: undefined, why });
}

const report = {
  base,
  loopback: BASE,
  when: new Date().toISOString(),
  login: loginResult,
  viewports: viewports.map((v) => v.name),
  surfaces: allSurfaces.map((s) => s.name),
  stylesheet: stylesheetFails,
  contrast: { checked: contrastAll.length, failing: contrastFails.length, uniqueFailing: cUniq.length, worst: cUniq.slice(0, 80) },
  layering: { overlaps: oUniq, perSurface: layerReport },
  rail: rUniq,
  glyphs: gUniq,
  wrapping: wUniq,
  tapTargets: tUniq,
  hoverContrast: { ran: hoverRan, failing: hoverFails.length, unique: hvUniq },
  icons: { seen: iconsSeen, failing: iconFails.length, unique: icUniq },
  headerHit: hitUniq,
  fold: { measured: fold, failing: foldFails },
  network: nUniq,
  consoleErrors: eUniq,
  structure,
  perf,
  notes,
};
writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));

const line = (s: string) => console.log(s);
line(`\n=========== visual sweep ===========`);
line(`login                  : ${loginResult}`);
line(`audits that threw      : ${notes.filter((n) => /audit threw/.test(n)).length}`);
for (const n of notes.filter((n) => /audit threw/.test(n)).slice(0, 4)) line(`  ! ${n}`);
line(`stylesheet compiled    : ${stylesheetFails.length === 0 ? "yes" : "NO — forum.css missing on " + stylesheetFails.length + " surfaces"}`);
line(`contrast pairs checked : ${contrastAll.length}`);
line(`contrast FAILING       : ${contrastFails.length} (${cUniq.length} unique)`);
for (const f of cUniq.slice(0, 40)) {
  line(`  ${String(f.ratio).padStart(5)}:1 (need ${f.need})  ${f.fg} on ${f.bg}  ${f.kind}  ${f.sel}` +
    (f.text ? `  "${f.text}"` : "") + (f.note ? `  [${f.note}]` : ""));
}
line(`\nheader overlaps        : ${overlapFails.length} (${oUniq.length} unique)`);
for (const o of oUniq) line(`  ${o.sel}  z=${o.z} position=${o.position}  on ${o.seenOn!.slice(0, 6).join(", ")}`);
line(`\nauthor rail placement  : ${railFails.length} bad (${rUniq.length} unique)`);
for (const r of rUniq) line(`  gap ${r.gap} (expected <=${r.expected}) sideBySide=${r.sideBySide}  ${r.seenOn!.slice(0, 4).join(", ")}`);
line(`\navatar glyph overflow  : ${glyphFails.length} (${gUniq.length} unique)`);
for (const g of gUniq.slice(0, 12)) line(`  top+${g.overTop} bottom+${g.overBottom} lh=${g.lineHeight}  ${g.sel}`);
line(`\nrail lines wrapping    : ${wrapFails.length} (${wUniq.length} unique)`);
for (const w of wUniq) line(`  ${w.sel} ${w.lines} lines (h=${w.h} lh=${w.lineHeight})  ${w.seenOn!.slice(0, 3).join(", ")}`);
line(`\nsmall tap targets      : ${tUniq.length}`);
for (const t of tUniq.slice(0, 12)) line(`  ${t.w}x${t.h}  ${t.sel}  "${t.text}"`);
line(`\nhover-state contrast   : ${hoverFails.length} failing (${hvUniq.length} unique) over ${hoverRan} hovered surfaces`);
for (const f of hvUniq.slice(0, 20)) {
  line(`  ${String(f.ratio).padStart(5)}:1 (need ${f.need})  ${f.fg} on ${f.bg}  ${f.sel} -> ${f.what}  "${f.text}"`);
}
line(`\nicons measured         : ${iconsSeen}   failing ${iconFails.length} (${icUniq.length} unique)`);
for (const g of icUniq.slice(0, 20)) line(`  ${g.w}x${g.h} fs=${g.fontSize} ${g.tag} ${g.name || g.cls}  ${g.problems.join("; ")}`);
line(`\nheader controls unclickable: ${hitFails.length} (${hitUniq.length} unique)`);
for (const h of hitUniq.slice(0, 12)) line(`  ${h.name} at (${h.x},${h.y}) scrollY=${h.scrollY} -> hit ${h.hit} z=${h.hitZ} ${h.hitPosition}${h.trappedBy ? "  trapped by " + h.trappedBy : ""}`);
line(`\nabove the fold:`);
for (const [k, f] of Object.entries<any>(fold)) {
  if (!f || !f.viewport) continue;
  line(`  ${k.padEnd(28)} feed@y=${String(f.feedTop).padStart(4)}  whole-items-in-fold=${f.feedInFold}  banner=${f.bannerPx}px content=${f.contentPx}px nav=${f.navPx}px`);
}
for (const f of foldFails) line(`  FAIL ${f.where}: ${f.why.join("; ")}`);
line(`\nbad/failed requests    : ${netFails.length} (${nUniq.length} unique)`);
for (const n of nUniq.slice(0, 20)) line(`  ${n.status || "ERR"} ${n.type}  ${n.url}  on ${n.seenOn!.slice(0, 3).join(", ")}`);
line(`\nconsole errors         : ${consoleFails.length} (${eUniq.length} unique)`);
for (const e of eUniq.slice(0, 12)) line(`  ! ${e.text.slice(0, 170)}  on ${e.seenOn!.slice(0, 3).join(", ")}`);
if (perf.scroll) line(`\nscroll frame times     : median ${perf.scroll.median}ms  p95 ${perf.scroll.p95}ms  max ${perf.scroll.max}ms`);
line(`\nreport: ${OUT}/report.json   shots: ${OUT}/<session>/<viewport>/<surface>.png`);

await browser.close();

/**
 * BREAK=<gate> flips one gate's verdict, so the run goes red on demand and the
 * gate is demonstrably capable of failing. Used once per gate when it is
 * written; kept so the next person can re-prove it in one command.
 */
const broke = (g: string) => BREAK === g;

/*
 * A gate that never ran is not a gate that passed.
 *
 * Both in-page audits are shipped to the browser as source strings. A single
 * bad escape in one of them threw at parse time, the catch turned that into an
 * empty result, and this run printed "contrast pairs checked: 0" alongside
 * PASS on every colour gate. Everything below is meaningless without evidence
 * that the audits actually produced samples, so that is a gate of its own.
 */
const auditThrew = notes.filter((n) => /audit threw/.test(n));
const gates: [string, boolean][] = [
  ["audits produced samples", contrastAll.length > 0 && auditThrew.length === 0 && !broke("samples")],
  ["stylesheet compiled", stylesheetFails.length === 0 && !broke("stylesheet")],
  ["contrast", cUniq.length === 0 && !broke("contrast")],
  ["contrast on hover", hvUniq.length === 0 && hoverRan > 0 && !broke("hover")],
  ["icons sized and resolved", icUniq.length === 0 && iconsSeen > 0 && !broke("icons")],
  ["header clickable when scrolled", hitUniq.length === 0 && !broke("hit")],
  ["feed above the fold", foldFails.length === 0 && Object.keys(fold).length > 0 && !broke("fold")],
  ["no content over header", oUniq.length === 0 && !broke("header")],
  ["author rail beside body", rUniq.length === 0 && !broke("rail")],
  ["avatar glyphs inside box", gUniq.length === 0 && !broke("glyph")],
  ["rail lines do not wrap", wUniq.length === 0 && !broke("wrap")],
  ["no failed requests", nUniq.length === 0 && !broke("network")],
  ["no console errors", eUniq.length === 0 && !broke("console")],
  ["scroll p95 under 34ms", (!perf.scroll?.p95 || perf.scroll.p95 < 34) && !broke("perf")],
];
let bad = 0;
line("");
for (const [n, ok] of gates) {
  line(`  ${ok ? "PASS" : "FAIL"}  ${n}`);
  if (!ok) bad++;
}
process.exit(bad ? 1 : 0);
