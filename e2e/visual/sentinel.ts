#!/usr/bin/env bun
/**
 * The smallest possible "did the theme actually reach the browser" check.
 *
 * Separate from sweep.ts because it has to be cheap enough to run on every
 * deploy: one page load, one eval, one line of output. It answers the question
 * that a 200 and a present forum.css both fail to answer — whether the
 * stylesheet in front of a user contains our tokens, or a stale build from
 * before the edit.
 *
 * Prints `ok <n> tokens` or `MISSING <detail>` and exits non-zero on failure.
 */
import { Browser } from "./cdp";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";

const b = await Browser.launch(Number(process.env.CDP_PORT || 21899), "1280,900");
try {
  await b.viewport({ name: "d", width: 1280, height: 900 });
  const booted = await b.goto(`${BASE}/`);
  if (!booted) {
    console.log("MISSING spa-did-not-boot");
    process.exit(1);
  }

  // One token per theme file that carries layout or colour meaning, so a file
  // dropped from extend.php is caught as well as a compile failure.
  const raw = await b.eval(`(() => {
    const cs = getComputedStyle(document.documentElement);
    const need = {
      'tokens.less': '--z-header',
      'tokens.less/ink': '--ink',
      'tokens.less/decor': '--decor-grid-size',
      'tokens.less/shell': '--shell',
    };
    const missing = [];
    for (const [file, prop] of Object.entries(need)) {
      if (!cs.getPropertyValue(prop).trim()) missing.push(file + ':' + prop);
    }
    // base.less is a rule, not a token: the header must carry the header layer
    const h = document.querySelector('.App-header');
    const hz = h ? getComputedStyle(h).zIndex : 'no-header';
    if (hz !== cs.getPropertyValue('--z-header').trim()) missing.push('base.less:.App-header z=' + hz);
    return JSON.stringify({ missing, sheets: document.styleSheets.length, hz });
  })()`);
  const r = JSON.parse(raw || "{}");
  if (r.missing?.length) {
    console.log(`MISSING ${r.missing.join(" ")}`);
    process.exit(1);
  }
  console.log(`ok tokens resolve, ${r.sheets} sheets, header z=${r.hz}`);
} finally {
  await b.close();
}
