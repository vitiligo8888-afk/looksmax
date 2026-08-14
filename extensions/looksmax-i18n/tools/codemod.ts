#!/usr/bin/env bun
/**
 * Apply the per-extension translation patch specs.
 *
 *   bun tools/codemod.ts --dry            # report only, change nothing
 *   bun tools/codemod.ts                  # apply
 *   bun tools/codemod.ts --ext looksmax-store
 *   bun tools/codemod.ts --revert         # put every replacement back
 *
 * ── Why authoring and applying are separate steps ───────────────────────────
 *
 * Six agents are editing these extensions right now. A conversion that rewrote
 * their PHP and JS in place would collide with whatever they saved thirty
 * seconds later, and the collision would be silent — a half-converted file
 * still parses.
 *
 * So each lane emits `locale/i18n-map.json` describing WHAT to replace, and
 * this applies it. That buys three properties that matter more than the
 * convenience of doing it in one pass:
 *
 *  - **Content-matched, not line-matched.** `find` is the exact source text.
 *    Another agent inserting forty lines above it changes nothing here.
 *  - **Idempotent.** A replacement whose `find` is gone and whose `replace` is
 *    present is already applied, and reports as such rather than as an error.
 *    Re-running after somebody reverts a file re-applies cleanly.
 *  - **Refusing to guess.** If `find` occurs zero times, or more than once, the
 *    entry FAILS. It does not "do its best" with the first hit. A codemod that
 *    edits the wrong one of two identical strings produces a bug that looks
 *    like a translation error and is not.
 *
 * The spec is also the deliverable the operator asked for — file, line, exact
 * proposed replacement — so an entry that cannot be applied automatically is
 * still a complete instruction for a person.
 */
import { readFileSync, writeFileSync, existsSync, readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";

const ROOT = join(import.meta.dir, "..", "..", "..");
const EXT_DIR = join(ROOT, "extensions");

const argv = process.argv.slice(2);
const has = (n: string) => argv.includes(`--${n}`);
const flag = (n: string, d?: string) => {
  const i = argv.indexOf(`--${n}`);
  return i === -1 ? d : argv[i + 1];
};

const DRY = has("dry");
const REVERT = has("revert");
const ONLY = flag("ext");
const VERBOSE = has("verbose");

type Replacement = {
  file: string;
  line?: number;
  find: string;
  replace: string;
  key?: string;
  note?: string;
};
type Spec = {
  extension: string;
  prefix: string;
  replacements: Replacement[];
  deferred?: Array<{ file: string; line?: number; string: string; reason: string }>;
};

const results = {
  applied: 0,
  already: 0,
  missing: 0,
  ambiguous: 0,
  deferred: 0,
  files: new Set<string>(),
};
const problems: string[] = [];

const exts = readdirSync(EXT_DIR).filter(
  (d) => statSync(join(EXT_DIR, d)).isDirectory() && (!ONLY || d === ONLY),
);

/** Buffer edits per file so one write happens per file, not one per string. */
const buffers = new Map<string, string>();
function read(path: string): string {
  if (!buffers.has(path)) buffers.set(path, readFileSync(path, "utf8"));
  return buffers.get(path)!;
}
function write(path: string, content: string) {
  buffers.set(path, content);
}

function occurrences(haystack: string, needle: string): number {
  if (!needle) return 0;
  let n = 0;
  let i = 0;
  for (;;) {
    const at = haystack.indexOf(needle, i);
    if (at === -1) return n;
    n++;
    i = at + needle.length;
  }
}

for (const ext of exts) {
  const specPath = join(EXT_DIR, ext, "locale", "i18n-map.json");
  if (!existsSync(specPath)) continue;

  let spec: Spec;
  try {
    spec = JSON.parse(readFileSync(specPath, "utf8"));
  } catch (e: any) {
    problems.push(`${ext}: i18n-map.json is not valid JSON — ${e.message}`);
    continue;
  }

  results.deferred += spec.deferred?.length ?? 0;

  for (const r of spec.replacements || []) {
    const path = join(EXT_DIR, ext, r.file);
    const rel = relative(ROOT, path);

    if (!existsSync(path)) {
      problems.push(`${rel}: file does not exist (key ${r.key ?? "?"})`);
      results.missing++;
      continue;
    }

    const from = REVERT ? r.replace : r.find;
    const to = REVERT ? r.find : r.replace;

    const src = read(path);
    const nFrom = occurrences(src, from);
    const nTo = occurrences(src, to);

    if (nFrom === 0 && nTo > 0) {
      // Already in the target state. Not an error, and the common case on a
      // re-run.
      results.already++;
      if (VERBOSE) console.log(`  skip  ${rel}:${r.line ?? "?"} already applied`);
      continue;
    }
    if (nFrom === 0) {
      problems.push(
        `${rel}:${r.line ?? "?"}: find not present and replace not present — the source moved. ` +
          `Looked for ${JSON.stringify(from.slice(0, 70))}`,
      );
      results.missing++;
      continue;
    }
    if (nFrom > 1) {
      problems.push(
        `${rel}:${r.line ?? "?"}: find occurs ${nFrom} times, refusing to guess which. ` +
          `Extend it until it is unique: ${JSON.stringify(from.slice(0, 70))}`,
      );
      results.ambiguous++;
      continue;
    }

    /*
     * Spliced by index, NOT `src.replace(from, to)`.
     *
     * `String.prototype.replace` treats the replacement as a PATTERN even when
     * the search is a plain string: `$&` inserts the match, `` $` `` inserts
     * everything before it, and `$'` inserts everything AFTER it. The store's
     * code contains `'$' + cents.toFixed(2)`, so `$'` appeared in a replacement
     * and silently appended a second copy of the remainder of the file. Every
     * subsequent `find` then legitimately occurred twice and the run reported
     * 79 "ambiguous" entries — a failure that looks exactly like the lanes
     * having written bad specs, which is what I assumed for a minute.
     */
    const at = src.indexOf(from);
    write(path, src.slice(0, at) + to + src.slice(at + from.length));
    results.applied++;
    results.files.add(rel);
    if (VERBOSE) console.log(`  edit  ${rel}:${r.line ?? "?"}  ${r.key ?? ""}`);
  }
}

if (!DRY) {
  for (const [path, content] of buffers) {
    if (content !== readFileSync(path, "utf8")) writeFileSync(path, content);
  }
}

console.log("");
console.log(`  ${DRY ? "would apply" : REVERT ? "reverted" : "applied"}  ${results.applied}`);
console.log(`  already        ${results.already}`);
console.log(`  deferred       ${results.deferred}   (recorded in the specs, not applied)`);
console.log(`  files touched  ${results.files.size}`);
if (problems.length) {
  console.log(`\n  ${problems.length} PROBLEM(S)\n`);
  for (const p of problems) console.log(`    ${p}`);
}
console.log("");

process.exit(problems.length ? 1 : 0);
