/**
 * Every translation key this extension asks for must exist in EVERY locale.
 *
 *   bun extensions/looksmax-userinfo/e2e/i18n-gate.ts
 *
 * ── Why this file exists ────────────────────────────────────────────────────
 * Flarum does not warn about a missing key. It renders the key itself, in the
 * middle of the page, to every visitor. A rewrite of locale/es.yml dropped the
 * whole `forum.profile` block and the profile sidebar shipped reading
 *
 *     local-looksmax-userinfo.forum.profile.top_tags
 *     local-looksmax-userinfo.forum.profile.recent
 *     local-looksmax-userinfo.forum.profile.recent_meta
 *     local-looksmax-userinfo.forum.profile.standing
 *
 * — four raw keys, live, and nothing anywhere said so. A green deploy, a 200
 * response and a compiled stylesheet all agreed the change was fine.
 *
 * Two checks, both static, both fast enough to run before every deploy:
 *
 *   1. every `t('…')` in js/dist/forum.js and every
 *      `local-looksmax-userinfo.admin.…` in js/dist/admin.js resolves in both
 *      locale files;
 *   2. the two locale files declare the SAME set of keys, so a string added to
 *      one and forgotten in the other is a failure rather than a silent
 *      fallback to English on a Spanish-default forum.
 *
 * The runtime half of the gate lives in card-sweep.ts, which fails on any
 * rendered text matching /^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*\./ — the shape of a
 * leaked key.
 */
import { readFileSync } from "node:fs";

const DIR = new URL("../", import.meta.url).pathname;

/* A deliberately small YAML reader: these files are two levels of mapping and
 * scalar values, nothing else. A dependency would have to be installed into a
 * container that has no node toolchain. */
function flatten(yaml: string): Set<string> {
  const keys = new Set<string>();
  const stack: string[] = [];
  for (const raw of yaml.split("\n")) {
    if (!raw.trim() || raw.trim().startsWith("#")) continue;
    const indent = raw.length - raw.trimStart().length;
    const line = raw.trim();
    const mm = line.match(/^([A-Za-z0-9_+-]+):(.*)$/);
    if (!mm) continue; // continuation line of a folded scalar
    const depth = indent / 2;
    stack.length = depth;
    stack[depth] = mm[1];
    if (mm[2].trim() !== "") keys.add(stack.slice(0, depth + 1).join("."));
  }
  return keys;
}

const es = flatten(readFileSync(DIR + "locale/es.yml", "utf8"));
const en = flatten(readFileSync(DIR + "locale/en.yml", "utf8"));

const PREFIX = "local-looksmax-userinfo.";
const forum = readFileSync(DIR + "js/dist/forum.js", "utf8");
const admin = readFileSync(DIR + "js/dist/admin.js", "utf8");

const used = new Set<string>();
// t('presence.online') / t("card.message", {...}) — the ONLY lookup form in the
// forum bundle, which is exactly why it is greppable.
for (const mm of forum.matchAll(/\bt\(\s*['"]([a-z0-9_.]+)['"]/g)) {
  used.add(PREFIX + "forum." + mm[1]);
}
// t('settings.width') in the admin bundle, which prefixes admin. itself
for (const mm of admin.matchAll(/\bt\(\s*['"]([a-z0-9_.]+)['"]/g)) {
  used.add(PREFIX + "admin." + mm[1]);
}
// any fully-qualified key written out longhand, anywhere
for (const src of [forum, admin]) {
  for (const mm of src.matchAll(/['"]local-looksmax-userinfo\.([a-z0-9_.]+)['"]/g)) {
    used.add(PREFIX + mm[1]);
  }
}
// The two NAMESPACE PREFIXES that t() concatenates a key onto
// ('local-looksmax-userinfo.forum.' + key) are matched by the rule above and
// are not keys. Dropping anything that ends in a dot is exact: no real key can.
for (const key of [...used]) if (key.endsWith(".")) used.delete(key);

const problems: string[] = [];

for (const key of [...used].sort()) {
  const bare = key.slice(PREFIX.length);
  const full = PREFIX.slice(0, -1) + "." + bare;
  if (!es.has(full)) problems.push(`MISSING es.yml   ${bare}`);
  if (!en.has(full)) problems.push(`MISSING en.yml   ${bare}`);
}

for (const key of [...es].sort()) if (!en.has(key)) problems.push(`ONLY IN es.yml   ${key}`);
for (const key of [...en].sort()) if (!es.has(key)) problems.push(`ONLY IN en.yml   ${key}`);

console.log(`keys referenced by JS: ${used.size}   declared es: ${es.size}   en: ${en.size}`);
if (problems.length) {
  console.log(problems.join("\n"));
  console.log(`\nFAIL  ${problems.length} problem(s)`);
  process.exit(1);
}
console.log("PASS  every key the JS asks for resolves in both locales, and the two files agree");
