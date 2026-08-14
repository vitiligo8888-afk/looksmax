#!/usr/bin/env bun
/**
 * The fast loop: shoot the surfaces that are being worked on, at the two
 * viewports the brief specifies, and print the fold + header + icon numbers.
 *
 * The full sweep (sweep.ts) is the gate; this is what you run twenty times
 * while changing a stylesheet. It probes the PUBLIC origin by default, because
 * Flarum emits absolute asset URLs against its configured base url and probing
 * the loopback makes every webfont cross-origin — measured as 5 spurious
 * "Font net::ERR_FAILED" per run that do not exist for a real visitor.
 */
import { Browser } from "./cdp";
import { FOLD_AUDIT, HEADER_HIT, ICON_AUDIT } from "./fold";

const BASE = process.env.FORUM_URL || "https://looksmax.lat";
const OUT = process.env.SHOT_DIR || "/work/flarum/look2";
const PATHS = (process.env.PATHS || "/,/all").split(",");
const VPS = [
  { name: "desktop", width: 1440, height: 900 },
  { name: "mobile", width: 390, height: 844, mobile: true },
];

const b = await Browser.launch(Number(process.env.CDP_PORT || 21890), "1440,900");
if (process.env.LOGIN === "1") console.log("login:", await b.login(process.env.ADMIN_USER || "admin", process.env.ADMIN_PASS || "", BASE));

for (const vp of VPS) {
  await b.viewport(vp);
  for (const p of PATHS) {
    b.resetNetwork();
    const ok = await b.goto(BASE + p);
    const name = (p === "/" ? "index" : p.replace(/\W+/g, "-").replace(/^-|-$/g, "")) + "-" + vp.name;
    if (!ok) { console.log(name, "DID NOT BOOT"); continue; }
    const fold = JSON.parse((await b.eval(FOLD_AUDIT).catch(() => "{}")) || "{}");
    const hit = JSON.parse((await b.eval(HEADER_HIT).catch(() => "{}")) || "{}");
    const ico = JSON.parse((await b.eval(ICON_AUDIT).catch(() => "{}")) || "{}");
    await b.shot(`${OUT}/${name}.png`);
    await b.eval("window.scrollTo(0,1400)"); await Bun.sleep(500);
    const hit2 = JSON.parse((await b.eval(HEADER_HIT).catch(() => "{}")) || "{}");
    await b.shot(`${OUT}/${name}-scrolled.png`);
    await b.eval("window.scrollTo(0,0)"); await Bun.sleep(300);
    await b.fullShot(`${OUT}/${name}-full.png`);
    console.log(`\n== ${name}`);
    console.log(`   fold: feedTop=${fold.feedTop} wholeItemsInFold=${fold.feedInFold}/${fold.feedItemsTotal} banner=${fold.bannerPx}px content=${fold.contentPx}px nav=${fold.navPx}px`);
    console.log(`   startHere: ${fold.startHere ? `first card top=${fold.startHere.top} inFold=${fold.startHere.inFold}` : "(no section block on this page)"}`);
    console.log(`   regions: ${(fold.regions || []).map((r: any) => `${r.role}@${r.top}+${r.height} ${r.sel}`).join(" | ")}`);
    console.log(`   icons: ${ico.total} total, ${ico.failing} failing`);
    for (const g of (ico.worst || []).slice(0, 4)) console.log(`      ${g.w}x${g.h} fs=${g.fontSize} ${g.name || g.cls}: ${g.problems.join("; ")}`);
    const badTop = (hit.controls || []).filter((c: any) => c.present && c.sized && !c.ok);
    const badScr = (hit2.controls || []).filter((c: any) => c.present && c.sized && !c.ok);
    console.log(`   header hit: top ${badTop.length} bad, scrolled ${badScr.length} bad` +
      (badScr.length ? " -> " + badScr.map((c: any) => `${c.name}<-${c.hit}(z=${c.hitZ})${c.trappedBy ? " trapped:" + c.trappedBy : ""}`).join(", ") : ""));
    if (b.failedRequests.length || b.badResponses.length) console.log(`   net: ${b.failedRequests.slice(0,4).join(" | ")} ${b.badResponses.slice(0,4).map(r=>r.status+" "+r.url).join(" | ")}`);
    if (b.consoleErrors.length) console.log(`   console: ${b.consoleErrors.slice(0, 3).join(" | ")}`);
  }
}
await b.close();
