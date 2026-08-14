#!/usr/bin/env bun
/**
 * Blind census of the XenForo constructs present in the scraped post HTML.
 *
 * Deliberately prior-free: nothing here is a keyword list of "constructs we
 * expect". Every element name, class token, data-* attribute and bb-code
 * wrapper is extracted from the corpus itself and tallied, so the long tail
 * shows up instead of only the handful someone happened to remember.
 */
import { Database } from "bun:sqlite";

const DB = process.argv[2] || "/work/lmx/scraper/looksmax.db";
const LIMIT = Number(process.argv[3] || 0); // 0 = all

const db = new Database(DB, { readonly: true });
db.exec("PRAGMA journal_mode = WAL");

const tags = new Map<string, number>();
const classes = new Map<string, number>();
const dataAttrs = new Map<string, number>();
const attrsByTag = new Map<string, number>();
const hosts = new Map<string, number>();
const bump = (m: Map<string, number>, k: string, n = 1) => m.set(k, (m.get(k) || 0) + n);

// posts carrying each construct (not occurrences) — that is what matters for
// "how many posts break if I get this wrong"
const postsWith = new Map<string, number>();

let n = 0;
const q = LIMIT
  ? db.query(`SELECT id, html FROM posts WHERE html IS NOT NULL AND html <> '' LIMIT ${LIMIT}`)
  : db.query(`SELECT id, html FROM posts WHERE html IS NOT NULL AND html <> ''`);

for (const row of q.iterate() as any) {
  const html: string = row.html;
  n++;
  const seen = new Set<string>();

  // element names
  for (const m of html.matchAll(/<([a-zA-Z][a-zA-Z0-9-]*)\b/g)) {
    const t = m[1].toLowerCase();
    bump(tags, t);
    seen.add("tag:" + t);
  }
  // class tokens
  for (const m of html.matchAll(/\bclass="([^"]*)"/g)) {
    for (const c of m[1].split(/\s+/)) {
      if (!c) continue;
      bump(classes, c);
      seen.add("class:" + c);
    }
  }
  // data-* attribute names
  for (const m of html.matchAll(/\b(data-[a-zA-Z0-9-]+)=/g)) {
    bump(dataAttrs, m[1]);
    seen.add("data:" + m[1]);
  }
  // tag+attribute pairs, so e.g. img[data-url] vs img[src] is visible
  for (const m of html.matchAll(/<([a-zA-Z][a-zA-Z0-9-]*)\b([^>]*)>/g)) {
    const t = m[1].toLowerCase();
    for (const a of m[2].matchAll(/\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=/g)) {
      bump(attrsByTag, `${t}[${a[1].toLowerCase()}]`);
    }
  }
  // link/image hosts, to size the local-media rewrite
  for (const m of html.matchAll(/\b(?:src|href|data-url|data-src)="(https?:)?\/\/([^/"]+)/g)) {
    bump(hosts, m[2].toLowerCase());
  }
  for (const s of seen) bump(postsWith, s);
}

const dump = (name: string, m: Map<string, number>, min = 1, top = 400) => {
  const rows = [...m.entries()].filter(([, c]) => c >= min).sort((a, b) => b[1] - a[1]).slice(0, top);
  console.log(`\n### ${name} (${m.size} distinct, showing ${rows.length})`);
  for (const [k, c] of rows) console.log(`${String(c).padStart(9)}  ${k}${postsWith.has(k) ? "" : ""}`);
};

console.log(`posts scanned: ${n}`);
dump("elements", tags);
dump("class tokens", classes, 2);
dump("data-* attributes", dataAttrs);
dump("tag[attr]", attrsByTag, 5);
dump("hosts", hosts, 5, 60);

console.log("\n### posts-containing counts (top 300)");
for (const [k, c] of [...postsWith.entries()].sort((a, b) => b[1] - a[1]).slice(0, 300)) {
  console.log(`${String(c).padStart(9)}  ${(100 * c / n).toFixed(2).padStart(6)}%  ${k}`);
}
