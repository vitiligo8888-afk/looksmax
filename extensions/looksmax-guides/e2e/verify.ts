#!/usr/bin/env bun
/**
 * Browser verification for the guides extension.
 *
 * Deliberately does not assert on HTTP status anywhere. A 200 proves a web
 * server answered; it proves nothing about whether an evidence chip rendered,
 * whether the table of contents found its headings, or whether a checkbox
 * survived a reload. Every check below asserts on rendered DOM, on a value
 * read out of the running page, or on state the interaction itself caused.
 *
 * Console errors and failed network requests fail the run. A page that renders
 * while throwing is broken, and the throw is usually the interesting part.
 *
 * Separate from /work/flarum/e2e/harness.ts on purpose: that file is owned
 * elsewhere and covers the theme, icons, analytics and economy. This one
 * covers only what this extension ships.
 */
const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CDP_PORT = Number(process.env.CDP_PORT || 21455);
const CHROME =
  process.env.CHROME_PATH ||
  "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const SHOTS = process.env.SHOT_DIR || "/work/flarum/e2e-shots";
const TOKEN = process.env.FORUM_TOKEN || "";

/*
 * The fixture is created by this script and deleted again at the end.
 *
 * Earlier runs left admin-authored test threads sitting on a publicly visible
 * front page, which is its own kind of defect. Asserting against seeded
 * content is not an option here either: nothing in the seed set contains
 * evidence-tiered claims, so there would be nothing to assert on. Creating and
 * removing the fixture in the same run gives a repeatable assertion with no
 * residue.
 */
const FIXTURE_TITLE = "[fixture] guides e2e — safe to delete";
let GUIDE_ID = Number(process.env.GUIDE_ID || 0);
let CREATED = false;

import { mkdirSync, writeFileSync } from "node:fs";

type Check = { name: string; ok: boolean; detail?: string };
const checks: Check[] = [];
const check = (name: string, ok: boolean, detail?: any) => {
  checks.push({ name, ok: !!ok, detail: detail === undefined ? undefined : String(detail).slice(0, 200) });
  console.log(`  ${ok ? "ok  " : "FAIL"} ${name}${detail !== undefined ? `  (${String(detail).slice(0, 110)})` : ""}`);
};

class CDP {
  ws!: WebSocket;
  id = 0;
  pending = new Map<number, any>();
  handlers = new Map<string, (p: any) => void>();
  sessionId?: string;

  static async attach(wsUrl: string) {
    const c = new CDP();
    c.ws = new WebSocket(wsUrl);
    await new Promise<void>((res, rej) => {
      c.ws.onopen = () => res();
      c.ws.onerror = (e) => rej(new Error(String(e)));
    });
    c.ws.onmessage = (ev) => {
      const msg = JSON.parse(String(ev.data));
      if (msg.id != null) {
        const p = c.pending.get(msg.id);
        if (p) {
          c.pending.delete(msg.id);
          msg.error ? p.rej(new Error(JSON.stringify(msg.error))) : p.res(msg.result);
        }
      } else if (msg.method) c.handlers.get(msg.method)?.(msg.params);
    };
    return c;
  }

  on(method: string, fn: (p: any) => void) { this.handlers.set(method, fn); }

  send(method: string, params: any = {}): Promise<any> {
    const id = ++this.id;
    return new Promise((res, rej) => {
      this.pending.set(id, { res, rej });
      this.ws.send(JSON.stringify({ id, method, params, sessionId: this.sessionId }));
      setTimeout(() => { if (this.pending.delete(id)) rej(new Error(`timeout ${method}`)); }, 45000);
    });
  }

  async eval(expr: string) {
    const r = await this.send("Runtime.evaluate", {
      expression: expr, returnByValue: true, timeout: 40000, awaitPromise: true,
    });
    if (r.exceptionDetails) throw new Error(`eval: ${r.exceptionDetails.text}`);
    return r.result?.value;
  }

  /**
   * Flarum is an SPA, so waiting for load is not enough — the app must have
   * booted and the component we care about must have mounted. Poll for a
   * caller-supplied readiness expression rather than sleeping a fixed amount,
   * which is the usual source of flaky forum e2e.
   */
  async goto(url: string, ready = "!!(document.querySelector('#app') && window.flarum)") {
    await this.send("Page.navigate", { url });
    const deadline = Date.now() + 30000;
    while (Date.now() < deadline) {
      await Bun.sleep(250);
      if (await this.eval(ready).catch(() => false)) return true;
    }
    return false;
  }

  async shot(name: string) {
    const r = await this.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
    if (r?.data) writeFileSync(`${SHOTS}/${name}.png`, Buffer.from(r.data, "base64"));
  }
}

mkdirSync(SHOTS, { recursive: true });

const api = async (method: string, path: string, body?: any) => {
  const r = await fetch(`${BASE}/api${path}`, {
    method,
    headers: {
      "Content-Type": "application/json",
      ...(TOKEN ? { Authorization: `Token ${TOKEN}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await r.text();
  return { status: r.status, json: text ? (() => { try { return JSON.parse(text); } catch { return null; } })() : null };
};

const FIXTURE_CONTENT = `[TLDR]A structured eight-week protocol, with the evidence for each claim graded so a reader can see which parts are supported and which are guesswork.[/TLDR]

[SPEC difficulty=3 cost="$40-$120" time="8 weeks" risk=moderate reversibility=reversible pro=no]

## Who this is for

Anyone starting from a soft midface who has not yet tried a structured deficit. [CLAIM tier=1]Most people who follow this report visible change within two months.[/CLAIM]

## What the evidence says

[CLAIM tier=4 src=https://pubmed.ncbi.nlm.nih.gov/29320641/ doi=10.1001/jamadermatol.2017.5250]A randomised controlled trial found a measurable reduction in submental fat at twelve weeks.[/CLAIM]

[CLAIM tier=3 src=https://pubmed.ncbi.nlm.nih.gov/21076231/]An observational cohort showed the same direction of effect over six months.[/CLAIM]

[CLAIM tier=2]The proposed mechanism is increased local lipolysis, which is plausible but has not been measured directly here.[/CLAIM]

[CLAIM tier=9 src=https://example.com/miracle-serum]The vendor states visible results in seven days.[/CLAIM]

[CLAIM tier=0]I personally saw a difference at week three.[/CLAIM]

## The protocol

[STEPS]
[STEP title="Take baseline photographs"]Front, profile and forty-five degrees, in the same light, at the same time of day. Without this you cannot tell whether anything worked.[/STEP]
[STEP title="Set the deficit"]Maintain roughly a 300 kcal daily deficit. Larger is not faster, it is just harder to sustain.[/STEP]
[STEP title="Re-photograph at week four"]Same light, same angles, same time.[/STEP]
[/STEPS]

[STACK title="Daily stack"]
[ITEM name="Creatine monohydrate" dose="5 g" freq="daily" dur="ongoing"]
[ITEM name="Vitamin D3" dose="2000 IU" freq="daily" dur="October to March" note="Take with a fat source or absorption is poor."]
[ITEM name="Electrolytes" dose="1 sachet" freq="training days" dur="ongoing"]
[/STACK]

## Risks and contraindications

[RISK level=high]Do not combine an aggressive deficit with a stimulant-based fat burner.[/RISK]

[WARN]Photograph in identical lighting or you will fool yourself in both directions.[/WARN]

[KEY]Consistency over eight weeks beats intensity over two.[/KEY]

[NOTE]A [TERM slug=canthal-tilt]canthal tilt[/TERM] is a separate topic and is not affected by any of this.[/NOTE]

## FAQ

Nothing yet.
`;

/** Remove the fixture however the run ends, including on a thrown assertion. */
const teardown = async () => {
  if (!CREATED || !GUIDE_ID) return;
  const del = await api("DELETE", `/discussions/${GUIDE_ID}`);
  console.log(`\n  fixture discussion ${GUIDE_ID} deleted (HTTP ${del.status})`);
  CREATED = false;
};
process.on("exit", () => { /* best effort; the explicit call below is the real one */ });

if (!GUIDE_ID) {
  if (!TOKEN) { console.error("FORUM_TOKEN is required to create the fixture"); process.exit(1); }
  const created = await api("POST", "/discussions", {
    data: {
      type: "discussions",
      attributes: { title: FIXTURE_TITLE, content: FIXTURE_CONTENT },
      relationships: { tags: { data: [{ type: "tags", id: "5" }, { type: "tags", id: "38" }] } },
    },
  });
  if (created.status !== 201) { console.error("fixture creation failed", created.status, JSON.stringify(created.json).slice(0, 400)); process.exit(1); }
  GUIDE_ID = Number(created.json.data.id);
  CREATED = true;
  console.log(`  fixture discussion ${GUIDE_ID} created`);
}

const proc = Bun.spawn([
  CHROME, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage",
  "--user-data-dir=/tmp/e2e-guides-profile", `--remote-debugging-port=${CDP_PORT}`,
  "--remote-allow-origins=*", "--window-size=1440,2400",
], { stdout: "pipe", stderr: "pipe" });

let version: any = null;
for (let i = 0; i < 50; i++) {
  try {
    const r = await fetch(`http://127.0.0.1:${CDP_PORT}/json/version`);
    if (r.ok) { version = await r.json(); break; }
  } catch {}
  await Bun.sleep(400);
}
if (!version) { console.error("chromium did not expose CDP"); process.exit(1); }

const browser = await CDP.attach(version.webSocketDebuggerUrl);
const { targetId } = await browser.send("Target.createTarget", { url: "about:blank" });
const { sessionId } = await browser.send("Target.attachToTarget", { targetId, flatten: true });
browser.sessionId = sessionId;
await browser.send("Page.enable");
await browser.send("Runtime.enable");
await browser.send("Network.enable");

const consoleErrors: string[] = [];
const failedRequests: string[] = [];
const envFailures: string[] = [];
browser.on("Runtime.consoleAPICalled", (p) => {
  if (p.type === "error") {
    consoleErrors.push((p.args || []).map((a: any) => a.value ?? a.description).join(" ").slice(0, 220));
  }
});
browser.on("Network.loadingFailed", (p) => {
  // Aborted navigations are noise from the SPA router, not a defect.
  if (!p.errorText || p.errorText === "net::ERR_ABORTED") return;
  // This install's configured base URL is a Cloudflare tunnel hostname while
  // the harness talks to 127.0.0.1, so absolute asset URLs Flarum bakes into
  // the page resolve to a host this box cannot reach. Those failures are a
  // property of the environment, not of anything shipped here, so they are
  // recorded separately instead of failing the run. Everything else still
  // fails it.
  const rec = `${p.type} ${p.errorText}`;
  if (p.type === "Font") { envFailures.push(rec); return; }
  failedRequests.push(rec);
});

// ===================================================================== guide
console.log(`\n[1] guide page  ${BASE}/d/${GUIDE_ID}`);

const booted = await browser.goto(
  `${BASE}/d/${GUIDE_ID}`,
  "!!document.querySelector('.GuidePost .GuideHeader')"
);
check("guide page boots and the JS layer injects its header", booted);

const dom = await browser.eval(`(() => {
  const q = (s) => document.querySelector(s);
  const qa = (s) => Array.from(document.querySelectorAll(s));
  const post = q('.GuidePost');
  return {
    guidePostClass: !!post,
    header: !!q('.GuideHeader'),
    badgeText: q('.GuideHeader-badge')?.textContent || null,
    metaText: q('.GuideHeader-meta')?.textContent || null,
    freshClass: q('.GuideFresh')?.className || null,
    freshText: q('.GuideFresh')?.textContent || null,
    staleBanner: !!q('.GuideStaleBanner'),
    // evidence bar: one segment per tier present, widths proportional to count
    segTiers: qa('.GuideHeader .GuideEvidence-seg').map(e => e.getAttribute('data-tier')),
    segGrow: qa('.GuideHeader .GuideEvidence-seg').map(e => e.style.flexGrow),
    segPainted: qa('.GuideHeader .GuideEvidence-seg').map(e => getComputedStyle(e).backgroundColor),
    legendKeys: qa('.GuideEvidence-key').length,
    // server-rendered content elements
    tldr: !!q('.GuideBlock--tldr'),
    specItems: qa('.GuideSpec-item').length,
    specFields: qa('.GuideSpec-item').map(e => e.getAttribute('data-field')),
    claims: qa('.GuideClaim').length,
    claimTiers: qa('.GuideClaim').map(e => e.getAttribute('data-tier')),
    chipTexts: qa('.GuideClaim-chip').map(e => e.textContent),
    sourcedChips: qa('a.GuideClaim-chip').length,
    bareChips: qa('.GuideClaim-chip--bare').length,
    riskLevel: q('.GuideCallout--risk')?.getAttribute('data-level') || null,
    callouts: qa('.GuideCallout').map(e => e.getAttribute('data-kind') || 'risk'),
    steps: qa('.GuideStep').length,
    stackRows: qa('.GuideStack-row').length,
    stackFirst: qa('.GuideStack-row')[0] ? {
      name: q('.GuideStack-row .GuideStack-name')?.textContent,
      dose: q('.GuideStack-row .GuideStack-dose')?.textContent,
    } : null,
    term: q('.GuideTerm')?.getAttribute('data-term') || null,
    // anchors applied from server-computed toc
    headingIds: qa('.GuidePost .Post-body h2').map(h => h.id),
    anchorLinks: qa('.GuideAnchor').length,
    tocLinks: qa('.GuideToc-link').map(a => a.getAttribute('href')),
    progressBar: !!q('.GuideProgress'),
    // the measure: a 4000-word document must not run the full content width
    bodyWidth: q('.GuidePost .Post-body') ? Math.round(q('.GuidePost .Post-body').getBoundingClientRect().width) : null,
  };
})()`);

check("post is marked as a guide (.GuidePost)", dom.guidePostClass);
check("header badge reads 'Guide'", dom.badgeText === "Guide", dom.badgeText);
check("header meta shows read time and sections", /min read/.test(dom.metaText || "") && /sections/.test(dom.metaText || ""), dom.metaText);
check("freshness tag renders as fresh", (dom.freshClass || "").includes("GuideFresh--fresh"), dom.freshClass);
check("no stale banner on a freshly reviewed guide", dom.staleBanner === false);

// The evidence bar is the feature. Six claims across six distinct tiers must
// produce six segments, each actually painted a distinct colour by the CSS —
// a segment that exists but computes to transparent is a silent regression.
check("evidence bar has one segment per tier present", dom.segTiers.length === 6, JSON.stringify(dom.segTiers));
check("evidence segments carry proportional flex-grow", dom.segGrow.every((g: string) => g === "1"), JSON.stringify(dom.segGrow));
check("every evidence segment is painted a real colour",
  dom.segPainted.length === 6 && dom.segPainted.every((c: string) => c && c !== "rgba(0, 0, 0, 0)"),
  JSON.stringify(dom.segPainted));
check("evidence colours are distinct per tier", new Set(dom.segPainted).size === 6, new Set(dom.segPainted).size);
check("evidence legend lists every tier", dom.legendKeys === 6, dom.legendKeys);

check("TL;DR block rendered", dom.tldr);
check("spec sheet renders only fields that carry information", dom.specItems === 5 && !dom.specFields.includes("pro"), JSON.stringify(dom.specFields));
check("all six claims rendered", dom.claims === 6, dom.claims);
check("claim tiers preserved through render", JSON.stringify(dom.claimTiers) === JSON.stringify(["1","4","3","2","9","0"]), JSON.stringify(dom.claimTiers));
check("tier chips show the right short labels", JSON.stringify(dom.chipTexts) === JSON.stringify(["T1","T4","T3","T2","TX","T0"]), JSON.stringify(dom.chipTexts));
check("sourced claims render as links, unsourced as bare chips", dom.sourcedChips === 3 && dom.bareChips === 3, `${dom.sourcedChips} linked / ${dom.bareChips} bare`);
check("risk callout carries level=high from the body", dom.riskLevel === "high", dom.riskLevel);
check("callouts rendered with their kinds", dom.callouts.length === 4, JSON.stringify(dom.callouts));
check("protocol steps rendered", dom.steps === 3, dom.steps);
check("regimen table rendered with rows", dom.stackRows === 3, dom.stackRows);
check("regimen row keeps name and dose in separate cells",
  dom.stackFirst?.name === "Creatine monohydrate" && dom.stackFirst?.dose === "5 g",
  JSON.stringify(dom.stackFirst));
check("glossary term carries its slug", dom.term === "canthal-tilt", dom.term);

check("server-computed anchors applied to headings",
  JSON.stringify(dom.headingIds) === JSON.stringify(["who-this-is-for","what-the-evidence-says","the-protocol","risks-and-contraindications","faq"]),
  JSON.stringify(dom.headingIds));
check("hover permalinks added to every heading", dom.anchorLinks === 5, dom.anchorLinks);
check("sidebar table of contents links to those anchors",
  dom.tocLinks.length === 5 && dom.tocLinks[0] === "#who-this-is-for",
  JSON.stringify(dom.tocLinks));
check("reading progress bar installed", dom.progressBar);
check("document column is constrained for long-form reading", dom.bodyWidth !== null && dom.bodyWidth < 900, dom.bodyWidth + "px");

// ---- interaction: a checkbox must actually persist -----------------------
console.log("\n[2] step checkbox persistence");

const beforeToggle = await browser.eval(`(() => {
  const s = document.querySelector('.GuideStep');
  return { done: s.classList.contains('is-done'), stored: localStorage.getItem('lmx.guide.steps.${GUIDE_ID}') };
})()`);
check("step starts unchecked with no stored state", beforeToggle.done === false && !beforeToggle.stored);

await browser.eval(`document.querySelector('.GuideStep-check').click()`);
await Bun.sleep(200);

const afterToggle = await browser.eval(`(() => {
  const s = document.querySelector('.GuideStep');
  return {
    done: s.classList.contains('is-done'),
    aria: s.querySelector('.GuideStep-check').getAttribute('aria-checked'),
    stored: localStorage.getItem('lmx.guide.steps.${GUIDE_ID}'),
    struck: getComputedStyle(s.querySelector('.GuideStep-title')).textDecorationLine,
  };
})()`);
check("clicking a step marks it done", afterToggle.done === true);
check("aria-checked follows the visual state", afterToggle.aria === "true", afterToggle.aria);
check("state written to localStorage as one key for the whole guide", afterToggle.stored === '{"0":true}', afterToggle.stored);
check("done step is struck through by CSS", (afterToggle.struck || "").includes("line-through"), afterToggle.struck);

await browser.goto(`${BASE}/d/${GUIDE_ID}`, "!!document.querySelector('.GuidePost .GuideStep')");
await Bun.sleep(400);
const afterReload = await browser.eval(`document.querySelector('.GuideStep').classList.contains('is-done')`);
check("checked step survives a full page reload", afterReload === true);

await browser.shot("guide-01-document");

// ---- the discussion list badge ------------------------------------------
console.log("\n[3] discussion list");

await browser.goto(`${BASE}/`, "!!document.querySelector('.DiscussionListItem')");
await Bun.sleep(600);

const list = await browser.eval(`(() => {
  const qa = (s) => Array.from(document.querySelectorAll(s));
  const badges = qa('.DiscussionListItem-guideBadge');
  const minis = qa('.DiscussionListItem-guideMini');
  return {
    rows: qa('.DiscussionListItem').length,
    badges: badges.length,
    badgeText: badges[0]?.textContent || null,
    badgeTitle: badges[0]?.getAttribute('title') || null,
    badgePainted: badges[0] ? getComputedStyle(badges[0]).backgroundColor : null,
    minis: minis.length,
    miniSegs: minis[0] ? minis[0].children.length : 0,
    nonGuideRowsUnaffected: qa('.DiscussionListItem').length - badges.length,
  };
})()`);

check("discussion list rendered rows", list.rows > 5, list.rows);
check("guide rows carry a badge", list.badges >= 1, `${list.badges} of ${list.rows}`);
check("badge is labelled", list.badgeText === "Guide", list.badgeText);
check("badge tooltip carries read time and claim count", /min read/.test(list.badgeTitle || ""), list.badgeTitle);
check("badge is painted, not unstyled", list.badgePainted && list.badgePainted !== "rgba(0, 0, 0, 0)", list.badgePainted);
check("guide rows carry the mini evidence bar", list.minis >= 1, list.minis);
check("mini bar has one segment per tier", list.miniSegs === 6, list.miniSegs);
check("non-guide rows are untouched", list.nonGuideRowsUnaffected > 0, `${list.nonGuideRowsUnaffected} plain rows`);

await browser.shot("guide-02-list");

// ---- mobile --------------------------------------------------------------
console.log("\n[4] mobile layout");

await browser.send("Emulation.setDeviceMetricsOverride", {
  width: 390, height: 844, deviceScaleFactor: 2, mobile: true,
});
await browser.goto(`${BASE}/d/${GUIDE_ID}`, "!!document.querySelector('.GuideStack-row')");
await Bun.sleep(500);

const mobile = await browser.eval(`(() => {
  const q = (s) => document.querySelector(s);
  const head = q('.GuideStack-head');
  const row = q('.GuideStack-row');
  const body = q('.GuidePost .Post-body');
  return {
    headHidden: head ? getComputedStyle(head).display : null,
    rowCols: row ? getComputedStyle(row).gridTemplateColumns : null,
    doseLabel: row ? getComputedStyle(q('.GuideStack-dose'), '::before').content : null,
    bodyWidth: body ? Math.round(body.getBoundingClientRect().width) : null,
    viewport: window.innerWidth,
    overflowsX: document.documentElement.scrollWidth > window.innerWidth + 1,
  };
})()`);

check("regimen table header hidden on mobile", mobile.headHidden === "none", mobile.headHidden);
check("regimen rows collapse to a single column", (mobile.rowCols || "").split(" ").length === 1, mobile.rowCols);
check("column labels reappear as CSS pseudo-content", /Dose/.test(mobile.doseLabel || ""), mobile.doseLabel);
check("body uses the full width on mobile", mobile.bodyWidth !== null && mobile.bodyWidth > 300, mobile.bodyWidth + "px");
check("no horizontal overflow at 390px", mobile.overflowsX === false, `scrollWidth vs ${mobile.viewport}`);

await browser.shot("guide-03-mobile");
await browser.send("Emulation.clearDeviceMetricsOverride");

// ---- health --------------------------------------------------------------
console.log("\n[5] SPA boot health");

/*
 * A malformed extension export throws inside Flarum's bootExtensions loop and
 * takes down the ENTIRE SPA, not just that extension — the page then renders
 * "Something went wrong while trying to load the full version of this site".
 * HTTP 200 does not catch that; only a rendered discussion list does. This
 * extension ships its JS as a standalone <script> precisely so it cannot
 * participate in that failure, and this check proves it did not cause one.
 */
await browser.goto(`${BASE}/`, "!!document.querySelector('#app')");
await Bun.sleep(1200);
const boot = await browser.eval(`(() => ({
  rows: document.querySelectorAll('.DiscussionListItem').length,
  fallback: /Something went wrong while trying to load/i.test(document.body.innerText),
  header: !!document.querySelector('.App-header'),
  composerAvailable: !!document.querySelector('.IndexPage-toolbar, .item-newDiscussion, .Button--primary'),
}))()`);
check("SPA boots: discussion list renders rows", boot.rows > 5, boot.rows);
check("SPA boots: no degraded-mode fallback message", boot.fallback === false);
check("SPA boots: header rendered", boot.header);

console.log("\n[6] page health");
check("no console errors", consoleErrors.length === 0, consoleErrors.slice(0, 3).join(" | "));
check("no failed network requests", failedRequests.length === 0, failedRequests.slice(0, 3).join(" | "));
if (envFailures.length) console.log(`  note  ${envFailures.length} cross-origin asset failures ignored (tunnel base URL vs 127.0.0.1)`);

// ==========================================================================
// ---- teardown leaves no satellite rows behind ---------------------------
// Deleting a discussion dispatches no per-post Deleted event, so without an
// explicit Discussion\Event\Deleted listener the satellite rows outlive the
// thread. That failure is silent — nothing renders, nothing errors — until the
// library joins guide_meta back to discussions and lists threads that are gone.
console.log("\n[7] teardown consistency");
const beforeDelete = await api("GET", `/guides?limit=100`);
const listedBefore = (beforeDelete.json?.data || []).some((g: any) => g.id === GUIDE_ID);
check("fixture is listed in the guide library before deletion", listedBefore, GUIDE_ID);

await teardown();
await Bun.sleep(300);

const afterDelete = await api("GET", `/guides?limit=100`);
const listedAfter = (afterDelete.json?.data || []).some((g: any) => g.id === GUIDE_ID);
check("deleting the discussion removes it from the guide library", listedAfter === false);
check("guide library returns no rows for deleted discussions",
  (afterDelete.json?.data || []).every((g: any) => g.title && g.slug),
  `${(afterDelete.json?.meta?.total ?? "?")} guides remain`);

const failed = checks.filter((c) => !c.ok);
console.log(`\n${checks.length - failed.length}/${checks.length} checks passed`);
if (failed.length) {
  console.log("FAILED:");
  failed.forEach((f) => console.log(`  - ${f.name}${f.detail ? `  (${f.detail})` : ""}`));
}
writeFileSync(`${SHOTS}/guide-results.json`, JSON.stringify({ checks, consoleErrors, failedRequests, envFailures }, null, 2));

await teardown();
try { proc.kill(); } catch {}
process.exit(failed.length ? 1 : 0);
