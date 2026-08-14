#!/usr/bin/env bun
/**
 * Pull one (or N) real post(s) per construct out of the scrape and write them
 * as fixtures. The converter test suite runs on THESE, not on hand-written
 * HTML, so the tests exercise markup the board actually emits.
 */
import { Database } from "bun:sqlite";
import { mkdirSync, writeFileSync } from "node:fs";

const DB = process.argv[2] || "/work/lmx/scraper/looksmax.db";
const OUT = process.argv[3] || "/work/lmx/scraper/fixtures";
mkdirSync(OUT, { recursive: true });

// name => predicate on the raw html. Derived from the blind census, not guessed.
const WANT: Record<string, (h: string) => boolean> = {
  "quote-simple": (h) => h.includes("bbCodeBlock--quote") && !h.includes("bbCodeSpoiler") && h.length < 2500,
  "quote-nested": (h) => (h.match(/bbCodeBlock--quote/g) || []).length >= 2,
  "quote-no-source": (h) => h.includes("bbCodeBlock--quote") && !h.includes("bbCodeBlock-sourceJump"),
  "spoiler-simple": (h) => h.includes("bbCodeSpoiler") && (h.match(/bbCodeSpoiler\b/g) || []).length === 1,
  "spoiler-titled": (h) => h.includes("bbCodeSpoiler-button-title"),
  "spoiler-nested": (h) => (h.match(/bbCodeSpoiler["\s]/g) || []).length >= 2,
  "spoiler-nested-3": (h) => (h.match(/bbCodeSpoiler["\s]/g) || []).length >= 3,
  "spoiler-with-unfurl": (h) => h.includes("bbCodeSpoiler") && h.includes("js-unfurl"),
  "unfurl": (h) => h.includes("bbCodeBlock--unfurl"),
  "unfurl-figure": (h) => h.includes("js-unfurl-figure"),
  "unfurl-pending": (h) => h.includes('data-pending="true"') || h.includes("is-pending"),
  "mention": (h) => h.includes('class="username"') && h.includes("data-user-id"),
  "mention-many": (h) => (h.match(/data-user-id/g) || []).length >= 3,
  "usergroup-mention": (h) => h.includes("data-usergroup-id"),
  "smilie-sprite": (h) => h.includes("smilie--sprite"),
  "smilie-emoji": (h) => h.includes("smilie--emoji"),
  "large-emoji": (h) => h.includes("uw_large_emoji"),
  "image-wrapper": (h) => h.includes("bbImageWrapper"),
  "image-aligned": (h) => h.includes("bbImageAligned--"),
  "image-sized": (h) => /<img[^>]+width="\d/.test(h) && h.includes("bbImage"),
  "image-lazyload": (h) => h.includes("lazyload") && h.includes("<noscript"),
  "image-proxy": (h) => h.includes("data-proxy-href"),
  "media-embed": (h) => h.includes("data-s9e-mediaembed"),
  "media-video": (h) => h.includes("bbMediaWrapper") && h.includes("<video"),
  "media-audio": (h) => h.includes("<audio"),
  "media-site": (h) => h.includes("data-media-site-id"),
  "code-block": (h) => h.includes("bbCodeCode"),
  "code-lang": (h) => h.includes("data-lang"),
  "code-inline": (h) => h.includes("bbCodeInline"),
  "table": (h) => h.includes("bbTable"),
  "list-ul": (h) => h.includes("<ul") && h.includes("data-xf-list-type"),
  "list-ol": (h) => h.includes("<ol"),
  "heading": (h) => /<h[234]/.test(h),
  "styled-span": (h) => /<span style="[^"]*color/.test(h),
  "styled-size": (h) => /<span style="[^"]*font-size/.test(h),
  "styled-family": (h) => /<span style="[^"]*font-family/.test(h),
  "align-center": (h) => h.includes('style="text-align: center"') || h.includes("text-align: center"),
  "cf-email": (h) => h.includes("__cf_email__"),
  "iframe": (h) => h.includes("<iframe"),
  "hr": (h) => h.includes("<hr"),
  "inline-link": (h) => h.includes("link--external") && !h.includes("unfurl"),
  "internal-link": (h) => h.includes("link--internal"),
};

const db = new Database(DB, { readonly: true });
const found: Record<string, any> = {};
const need = new Set(Object.keys(WANT));
let scanned = 0;

for (const row of db.query(`SELECT id, thread_id, author_name, html FROM posts WHERE html IS NOT NULL AND html <> ''`).iterate() as any) {
  scanned++;
  if (!need.size) break;
  const h: string = row.html;
  if (h.length > 60000) continue;
  for (const k of [...need]) {
    try {
      if (WANT[k](h)) { found[k] = row; need.delete(k); }
    } catch {}
  }
}

for (const [k, row] of Object.entries(found)) {
  writeFileSync(`${OUT}/${k}.html`, row.html);
  writeFileSync(`${OUT}/${k}.meta.json`, JSON.stringify({ id: row.id, thread_id: row.thread_id, author: row.author_name }, null, 2));
}
console.log(`scanned ${scanned}; captured ${Object.keys(found).length}/${Object.keys(WANT).length}`);
if (need.size) console.log("MISSING:", [...need].join(", "));
