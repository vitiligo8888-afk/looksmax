#!/usr/bin/env bun
/**
 * i18n coverage audit.
 *
 * "Full i18n" is a number, not an adjective, so this exists to produce the
 * number and to keep producing it after every subsequent commit. It walks the
 * extension sources, pulls out every string literal along with the line it sits
 * on, decides which of them a user can actually read, and reports what fraction
 * of those reach the user through the translator.
 *
 *   bun tools/audit.ts                      # human report to stdout
 *   bun tools/audit.ts --json out.json      # machine-readable, for the gate
 *   bun tools/audit.ts --ext looksmax-store # one extension
 *   bun tools/audit.ts --check              # exit 1 if coverage fell below the floor
 *   bun tools/audit.ts --all                # also list what was excluded and why
 *
 * ── Why a hand-rolled lexer and not a regex ──────────────────────────────────
 * The first version grepped for quoted strings. Every one of these files is
 * heavily commented prose, and "the exact figure still has to be available"
 * inside a /* *\/ block looks exactly like a hardcoded UI string to a regex.
 * That version reported 900+ violations of which roughly forty were real, which
 * is a report nobody reads twice. So the scanner walks the character stream and
 * tracks whether it is inside a comment, a string, a template literal or a
 * regex, and only comments-stripped code contributes strings.
 *
 * ── Why every exclusion carries a reason ─────────────────────────────────────
 * A percentage produced by a filter that can silently drop things is worth
 * nothing. `--all` prints every string the scanner decided NOT to count and the
 * rule that decided it, so the denominator can be argued with. If a rule is
 * wrong you can see that it is wrong instead of seeing a smaller number.
 */
import { readdirSync, readFileSync, statSync, writeFileSync, existsSync } from "node:fs";
import { join, relative, extname, basename } from "node:path";

const ROOT = join(import.meta.dir, "..", "..", ".."); // /root/flarum-stack
const EXT_DIR = join(ROOT, "extensions");

const argv = process.argv.slice(2);
const flag = (n: string, d?: string) => {
  const i = argv.indexOf(`--${n}`);
  return i === -1 ? d : argv[i + 1];
};
const has = (n: string) => argv.includes(`--${n}`);

const ONLY = flag("ext");
const JSON_OUT = flag("json");
const SHOW_ALL = has("all");
const CHECK = has("check");
const FLOOR = Number(flag("floor", "97"));

/* ───────────────────────────────────────────────────────────── file walking */

/**
 * Directories whose contents no forum visitor can ever see. Tests, fixtures,
 * one-off scripts and screenshot dumps are all English on purpose and
 * translating them would be noise, so they are not in the denominator at all
 * rather than being excluded string-by-string.
 */
const SKIP_DIRS = new Set([
  "node_modules", "vendor", "data", "tests", "test", "e2e", "bin", "tools",
  "proof", "shots", "deploy", "design", "dist-src", ".git", "fixtures",
]);

/** Vendored or generated payloads. Nothing here is ours to translate. */
const SKIP_FILE = (p: string) =>
  /\.min\.js$/.test(p) ||
  /icons\.json$/.test(p) ||
  /\.map$/.test(p) ||
  /iconify/.test(basename(p));

const EXTS = new Set([".php", ".js", ".less", ".blade.php"]);

function walk(dir: string, out: string[] = []): string[] {
  let entries: string[];
  try {
    entries = readdirSync(dir);
  } catch {
    return out;
  }
  for (const e of entries) {
    const p = join(dir, e);
    let st;
    try {
      st = statSync(p);
    } catch {
      continue;
    }
    if (st.isDirectory()) {
      if (SKIP_DIRS.has(e)) continue;
      walk(p, out);
    } else {
      const ext = e.endsWith(".blade.php") ? ".blade.php" : extname(e);
      if (!EXTS.has(ext)) continue;
      if (SKIP_FILE(p)) continue;
      out.push(p);
    }
  }
  return out;
}

/* ─────────────────────────────────────────────────────────────────── lexing */

type Lit = {
  /** the decoded-ish contents, backslash escapes for quotes resolved */
  value: string;
  line: number;
  /** the ~120 chars of code before the literal on the same logical span */
  before: string;
  /** the ~60 chars after */
  after: string;
  kind: "string" | "template";
};

/**
 * Pull string literals out of PHP or JS while honouring comments.
 *
 * Deliberately not a real parser. It needs to be right about three things —
 * comments are not code, strings inside comments are not strings, and a
 * comment marker inside a string is not a comment — and being wrong about a
 * regex literal only costs a spurious entry that the classifier then drops.
 */
function lex(src: string, lang: "php" | "js"): Lit[] {
  const out: Lit[] = [];
  let i = 0;
  let line = 1;
  const n = src.length;
  // In PHP everything outside <?php ?> is literal output, which is user
  // visible text; blade files are handled separately.
  let inPhp = lang === "js";

  /** the last non-space code char, used to tell a regex from a division */
  let lastCode = "";

  while (i < n) {
    const c = src[i];
    const c2 = src.slice(i, i + 2);

    if (c === "\n") {
      line++;
      i++;
      continue;
    }

    if (lang === "php" && !inPhp) {
      const open = src.indexOf("<?php", i);
      const openShort = src.indexOf("<?=", i);
      const at = open === -1 ? openShort : openShort === -1 ? open : Math.min(open, openShort);
      if (at === -1) break;
      for (let k = i; k < at; k++) if (src[k] === "\n") line++;
      i = at;
      inPhp = true;
      continue;
    }
    if (lang === "php" && c2 === "?>") {
      inPhp = false;
      i += 2;
      continue;
    }

    // comments
    if (c2 === "//" || (lang === "php" && c === "#" && src[i + 1] !== "[")) {
      while (i < n && src[i] !== "\n") i++;
      continue;
    }
    if (c2 === "/*") {
      i += 2;
      while (i < n && src.slice(i, i + 2) !== "*/") {
        if (src[i] === "\n") line++;
        i++;
      }
      i += 2;
      continue;
    }

    // heredoc / nowdoc — treated as one literal
    if (lang === "php" && src.slice(i, i + 3) === "<<<") {
      const m = /^<<<\s*(['"]?)([A-Za-z_]\w*)\1\r?\n/.exec(src.slice(i, i + 200));
      if (m) {
        const tag = m[2];
        const bodyStart = i + m[0].length;
        const endRe = new RegExp(`^[ \\t]*${tag}\\b`, "m");
        const rest = src.slice(bodyStart);
        const em = endRe.exec(rest);
        const bodyEnd = em ? bodyStart + em.index : n;
        const value = src.slice(bodyStart, bodyEnd);
        const startLine = line;
        for (let k = i; k < bodyEnd; k++) if (src[k] === "\n") line++;
        out.push({
          value,
          line: startLine,
          before: src.slice(Math.max(0, i - 120), i).replace(/\s+/g, " "),
          after: "",
          kind: "template",
        });
        i = bodyEnd;
        continue;
      }
    }

    // regex literal in JS: only after an operator or an opening bracket
    if (lang === "js" && c === "/" && /^[=(,:[!&|?{};+\-*%~^]$|^$|^return$|^typeof$/.test(lastCode)) {
      let k = i + 1;
      let ok = false;
      let cls = false;
      while (k < n) {
        const ch = src[k];
        if (ch === "\\") {
          k += 2;
          continue;
        }
        if (ch === "\n") break;
        if (ch === "[") cls = true;
        else if (ch === "]") cls = false;
        else if (ch === "/" && !cls) {
          ok = true;
          break;
        }
        k++;
      }
      if (ok) {
        i = k + 1;
        while (i < n && /[a-z]/.test(src[i])) i++;
        lastCode = "/";
        continue;
      }
    }

    if (c === '"' || c === "'" || (lang === "js" && c === "`")) {
      const quote = c;
      const startLine = line;
      const start = i;
      let k = i + 1;
      let buf = "";
      while (k < n) {
        const ch = src[k];
        if (ch === "\\") {
          const nx = src[k + 1];
          buf += nx === "n" ? "\n" : nx === "t" ? "\t" : nx ?? "";
          k += 2;
          continue;
        }
        if (ch === quote) break;
        if (ch === "\n") {
          if (quote !== "`") {
            // an unterminated single-line string means the lexer lost sync;
            // bail on this literal rather than swallowing the rest of the file
            buf = "";
            break;
          }
          line++;
        }
        buf += ch;
        k++;
      }
      if (k < n && src[k] === quote) {
        out.push({
          value: buf,
          line: startLine,
          before: src.slice(Math.max(0, start - 140), start).replace(/\s+/g, " "),
          after: src.slice(k + 1, k + 70).replace(/\s+/g, " "),
          kind: quote === "`" ? "template" : "string",
        });
        i = k + 1;
        lastCode = "x";
        continue;
      }
      i = start + 1;
      continue;
    }

    if (!/\s/.test(c)) lastCode = /[A-Za-z0-9_$]/.test(c) ? "x" : c;
    i++;
  }
  return out;
}

/** LESS: only `content:` declarations put text on the screen. */
function lexLess(src: string): Lit[] {
  const out: Lit[] = [];
  const lines = src.split("\n");
  let inBlock = false;
  lines.forEach((raw, idx) => {
    let l = raw;
    if (inBlock) {
      const e = l.indexOf("*/");
      if (e === -1) return;
      l = l.slice(e + 2);
      inBlock = false;
    }
    const b = l.indexOf("/*");
    if (b !== -1) {
      const e = l.indexOf("*/", b + 2);
      if (e === -1) {
        inBlock = true;
        l = l.slice(0, b);
      } else l = l.slice(0, b) + l.slice(e + 2);
    }
    l = l.replace(/\/\/.*$/, "");
    const m = /content\s*:\s*(['"])((?:\\.|(?!\1).)*)\1/.exec(l);
    if (m) out.push({ value: m[2], line: idx + 1, before: "content:", after: "", kind: "string" });
  });
  return out;
}

/** Blade: `{{ }}` echoes plus the raw text between tags. */
function lexBlade(raw0: string): Lit[] {
  const out: Lit[] = [];
  // {{-- --}} and <!-- --> are authoring notes, not output. Blank them out
  // rather than deleting them so line numbers stay true to the file.
  // Blank out, rather than delete, so line numbers stay true to the file:
  //   {{-- --}} and <!-- -->  authoring notes, not output
  //   <style>…</style>        the brand lane's error pages inline their whole
  //                           stylesheet, and `font-family: system-ui` reads
  //                           as prose to any text extractor
  //   <script>…</script>      same, for JS
  const blank = (m: string) => m.replace(/[^\n]/g, " ");
  const src = raw0
    .replace(/\{\{--[\s\S]*?--\}\}/g, blank)
    .replace(/<!--[\s\S]*?-->/g, blank)
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, blank)
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, blank);
  const lines = src.split("\n");
  lines.forEach((raw, idx) => {
    const text = raw
      .replace(/\{\{[^}]*\}\}/g, "")
      .replace(/\{!![^}]*!!\}/g, "")
      .replace(/@\w+\([^)]*\)/g, "")
      .replace(/<[^>]*>/g, " ")
      .trim();
    if (text) out.push({ value: text, line: idx + 1, before: "blade-text", after: "", kind: "string" });
  });
  // and any php string literals inside the blade
  for (const l of lex(src, "php")) out.push(l);
  return out;
}

/* ────────────────────────────────────────────────────────── classification */

type Verdict =
  | { class: "translated"; rule: string }
  | { class: "hardcoded"; rule: string }
  | { class: "excluded"; rule: string };

/** `core.forum.header.sign_up_link` and friends. Presence of a key IS coverage. */
const KEY_RE = /^[a-z][a-z0-9_]*(?:-[a-z0-9_]+)*(?:\.[a-z0-9_-]+){2,}$/;

/**
 * Does this literal read as something a person wrote for another person?
 *
 * The test is deliberately generous — anything that survives here still has to
 * clear the exclusion rules — because a missed string is a raw key rendered to
 * a user, which is the failure this whole file exists to prevent.
 */
function looksLikeProse(s: string): boolean {
  const t = s.trim();
  if (t.length < 2) return false;
  const letters = (t.match(/[A-Za-zÀ-ÿ]/g) || []).length;
  if (letters < 2) return false;
  // needs either two words, or one word of real length with a capital or
  // punctuation that identifiers do not carry
  if (/[A-Za-zÀ-ÿ]\s+[A-Za-zÀ-ÿ]/.test(t)) return true;
  if (/^[A-ZÀ-Ý][a-zà-ÿ]{2,}[.!?…]?$/.test(t)) return true;
  return false;
}

const EXCLUDE: Array<[string, (s: string, lit: Lit, file: string) => boolean]> = [
  ["url", (s) => /^(https?:|mailto:|data:|\/\/)/.test(s) || /^[\w.-]+\.(com|org|net|lat|io|dev)(\/|$)/.test(s)],
  ["path", (s) => /^\.{0,2}\//.test(s) || /^[\w.\-\/]+\.(php|js|ts|less|css|png|jpe?g|svg|json|ya?ml|md|txt|webp|gif|woff2?)$/i.test(s)],
  ["fqcn", (s) => /\\\\|\\[A-Z]/.test(s) || /::/.test(s) || /^[A-Z][A-Za-z0-9]*\\/.test(s)],
  ["selector", (s) => /^[.#][A-Za-z][\w-]*/.test(s) || /^[a-z]+(\.[A-Z][\w-]*)+$/.test(s) || /^\s*[.#][\w-]+[\s>,]/.test(s)],
  ["html", (s) => /^<[a-zA-Z!\/]/.test(s)],
  ["mime", (s) => /^[a-z]+\/[a-z0-9.+*-]+$/.test(s)],
  ["sql", (s) => /^\s*(select|insert|update|delete|create|alter|drop|truncate|with)\s/i.test(s) || /\s(from|join|where|group by|order by)\s/i.test(s)],
  ["regex", (s) => /\\[dwsbnWSD]|\(\?[:=!]|\[\^|\{\d+,?\d*\}/.test(s)],
  ["translation-key", (s) => KEY_RE.test(s)],
  ["identifier", (s) => /^[A-Za-z_$][\w$-]*$/.test(s) && !/\s/.test(s)],
  ["date-format", (s) => /^[\w\s:,\/.-]{1,20}$/.test(s) && /^[dDjlNSwzWFmMntLoXxYyaABgGhHisueIOPTZcrU\s:,\/.-]+$/.test(s)],
  ["punctuation", (s) => !/[A-Za-zÀ-ÿ]/.test(s)],
  ["css-value", (s) => /^-?\d|^(none|auto|inherit|initial|unset|hidden|block|flex|grid|absolute|relative|fixed|sticky|nowrap|pointer|bold|normal|center|left|right)$/.test(s)],
  ["env", (s) => /^[A-Z][A-Z0-9_]{2,}$/.test(s)],
  ["header-name", (s) => /^[A-Z][a-z]+(-[A-Z][a-z]+)+$/.test(s)],
  ["emoji", (s) => !/[A-Za-zÀ-ÿ]/.test(s.replace(/[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\uFE0F\u200D]/gu, ""))],
  // `fas fa-dumbbell` reads as two words to the prose test and is an icon.
  ["icon-class", (s) => /^(fa[srlbdt]?|fab|far|fas)\s+fa-[\w-]+(\s+fa-[\w-]+)*$/.test(s.trim()) || /^(ph|bi|mdi|ri|lucide|tabler|heroicons|material-symbols|carbon|solar):[\w-]+$/.test(s.trim())],
  // "LmxChat-retry LmxChat-drop", "noopener nofollow ugc" \u2014 a class or rel
  // list is whitespace-separated identifiers, never a sentence. Requiring one
  // token to carry a BEM `--`/`is-`, an inner capital, or a known rel keyword
  // keeps "Results in" and "Key takeaway" out of this bucket.
  ["class-list", (s) => {
    const t = s.trim();
    if (!/^[A-Za-z][\w-]*(\s+[A-Za-z][\w-]*)+$/.test(t)) return false;
    const toks = t.split(/\s+/);
    const REL = new Set(["nofollow", "noopener", "noreferrer", "ugc", "sponsored", "external"]);
    return toks.every((x) => REL.has(x)) || toks.some((x) => /--|^[a-z]+[A-Z]|^(is|has|js)-|^[A-Z][a-z]+[A-Z]/.test(x));
  }],
  // BBCode grammar definitions: `[QUOTE author={TEXT1?}]{TEXT2}[/QUOTE]`
  ["bbcode-definition", (s) => /^\s*\[\/?[A-Z][A-Z0-9]*[\s\]]/.test(s) || /\{(TEXT|URL|UINT|ANYTHING|SIMPLETEXT|IDENTIFIER|CHOICE|NUMBER|INT|COLOR|EMAIL|FLOAT|HASHMAP|MAP|RANGE|REGEXP)\d*[;}?]/.test(s)],
  // XSLT template fragments and XPath predicates from the formatter config
  ["xml-fragment", (s) => /<\/?\w+[:\s>]|<xsl:|xsl:value-of|&lt;|\bselect="/.test(s)],
  ["xpath", (s) => /^@[\w-]+\s|(^|\s)@[\w-]+\s*(!?=|\band\b|\bor\b)/.test(s)],
  // Bare SQL expressions, which appear as selectRaw()/orderByRaw() arguments
  // and never begin with a SELECT keyword.
  ["sql-expr", (s) => /^\s*(COUNT|SUM|MAX|MIN|AVG|ROUND|COALESCE|CASE|CAST|IFNULL|GROUP_CONCAT|DISTINCT|PRAGMA|UNIX_TIMESTAMP|DATE_FORMAT|JSON_|LOWER|UPPER|CONCAT)\s*[\s(]/i.test(s) || /\)\s+as\s+[a-z_]\w*\s*$/i.test(s) || /\b(ASC|DESC)\s*$/.test(s.trim())],
];

/**
 * Whole files whose strings nobody browsing the forum can read.
 *
 * `src/Console/**` is the big one. An artisan command prints to an operator's
 * terminal, and its output is concatenated across several statements so the
 * per-call sink rules cannot see it. Excluding by path is blunt, so `--all`
 * prints the bucket and it can be argued with. One real exception found while
 * writing this rule: ImportCommand.php:286 writes
 * "_(body not scraped for this thread)_" into a POST BODY, which every visitor
 * reading that thread sees. It is filed in HANDOFF-I18N.md, not hidden here.
 */
const FILE_EXCLUSIONS: Array<[string, RegExp]> = [
  ["cli", /\/src\/Console\//],
  ["migration", /\/migrations\//],
];

/**
 * Contexts that consume a string without ever showing it to a forum visitor.
 * Console output is separated from silently-ignored so it can be argued about:
 * a CLI message is English on purpose, an admin panel label is not.
 */
const DEV_SINKS: Array<[string, RegExp]> = [
  // Both the direct call and a string built up inside one. `console.warn('x: '
  // + n + ' things')` puts the literal several tokens after the `(`, so the
  // anchored form alone misses it — look back to the start of the statement
  // instead, which the last `;` or `{` marks.
  ["console", /console\.(log|warn|error|info|debug|trace)\s*\($/],
  ["console", /console\.(log|warn|error|info|debug|trace)\s*\([^;{}]*$/],
  ["cli-output", /->(info|line|comment|warn|error|question|title|section|writeln|write|note|table|choice|confirm|ask)\s*\($|\$this->output->/],
  ["cli-signature", /(protected \$signature|protected \$description|->addOption\(|->addArgument\(|->setDescription\()/],
  ["dev-exception", /throw new \\?(RuntimeException|InvalidArgumentException|LogicException|DomainException|UnexpectedValueException|Exception)\s*\($/],
  ["log", /(Log::|logger\(\)->|->getLogger\(\)->)\w+\s*\($/],
  ["debug-attr", /(__lmx|window\.__)/],
  ["migration", /Schema::|->table\(|Builder/],
];

const TRANS_CALL =
  /(app\.translator|translator|\$this->translator|\$translator|app\('translator'\)|resolve\(TranslatorInterface::class\))\s*->?\s*(trans|transChoice)\s*\(\s*$|\.trans\(\s*$|__\(\s*$|@lang\(\s*$|\bt\(\s*$/;

/**
 * A literal sitting INSIDE an open translation call.
 *
 * Several lanes settled on `t(key, params, fallback)` — a local helper that
 * calls the translator and falls back to the original English if the key is
 * missing, so a half-deployed locale pack degrades to readable English rather
 * than to `local-looksmax-store.forum.time.days_left`. The fallback argument is
 * an English string literal, and TRANS_CALL cannot see it because it only
 * anchors on the character immediately before the literal:
 *
 *     t('forum.time.days_left', { count: n }, n + ' days left')
 *                                                ^^^^^^^^^^^^ not adjacent to `(`
 *
 * Counting those as untranslated punishes the safer pattern — the lanes that
 * shipped a fallback would score worse than ones that did not. So: if a
 * translation call has been opened and not closed before this literal, and no
 * statement boundary intervenes, the literal is part of a translated
 * expression.
 *
 * Balance-checked rather than regex-matched, because `t('a', {}, x)` and
 * `foo(t('a'), 'a real hardcoded string')` differ only in whether the call is
 * still open by the time the literal appears.
 */
function insideTransCall(before: string): boolean {
  const stmt = before.slice(Math.max(0, before.lastIndexOf(";") + 1));
  const re = /(?:\.trans|\btrans|\bt|\b__|@lang)\s*\(/g;
  let m: RegExpExecArray | null;

  while ((m = re.exec(stmt))) {
    // Is that call still open at the end of the fragment?
    let depth = 0;
    for (let i = m.index + m[0].length - 1; i < stmt.length; i++) {
      if (stmt[i] === "(") depth++;
      else if (stmt[i] === ")") depth--;
      if (depth === 0 && i > m.index) break;
    }
    if (depth > 0) return true;
  }
  return false;
}

function classify(lit: Lit, file: string, lang: string): Verdict {
  const s = lit.value;
  const before = lit.before;

  if (TRANS_CALL.test(before)) return { class: "translated", rule: "translator.trans" };
  if (KEY_RE.test(s.trim())) return { class: "translated", rule: "translation-key" };

  // Order matters here, and getting it wrong inflates the score. This check
  // has to come AFTER the prose test: a translation call's other arguments are
  // full of non-prose literals — the key itself, ICU parameter names, CSS
  // classes in the surrounding vnode — and crediting those as "translated"
  // added 319 strings to both sides of the fraction and moved the headline
  // number up by three points for no work at all. Only prose that is a
  // fallback counts.
  if (!looksLikeProse(s)) return { class: "excluded", rule: "not-prose" };

  for (const [rule, re] of FILE_EXCLUSIONS) if (re.test(file)) return { class: "excluded", rule };

  for (const [rule, fn] of EXCLUDE) if (fn(s, lit, file)) return { class: "excluded", rule };

  for (const [rule, re] of DEV_SINKS) if (re.test(before)) return { class: "excluded", rule };

  // Last, so that a fallback which is also a class list or an icon name is
  // excluded on its own merits rather than credited. Placed earlier it added
  // 184 strings to the denominator and moved the headline number for no work.
  if (insideTransCall(before)) return { class: "translated", rule: "fallback-argument" };

  return { class: "hardcoded", rule: "user-visible prose" };
}

/* ──────────────────────────────────────────────────────────────── the sweep */

type Finding = {
  ext: string;
  file: string;
  line: number;
  value: string;
  class: string;
  rule: string;
  lang: string;
};

/*
 * ── Human verdicts, read back in ────────────────────────────────────────────
 *
 * A machine cannot tell `'no such user'` in a 404 JSON body that nothing
 * renders from `'No such thread.'` in an alert a member reads. Both are prose,
 * both sit in a controller. Deciding needs somebody to open the file and follow
 * the value.
 *
 * That work has been done, per extension, and written down in
 * `locale/i18n-map.json`:
 *
 *   not_translatable  — read, and confirmed no user can see it. Each carries a
 *                       reason. Excluded from the denominator.
 *   deferred          — user-visible, but not a string swap: it is seeded into
 *                       the database, or compiled into cached XSLT, or written
 *                       into a post body at import time. Each carries a design.
 *                       Still counted as hardcoded, because a reader still sees
 *                       English — but reported separately, so "hard problems
 *                       with a written plan" is never confused with "nobody
 *                       looked".
 *
 * Only `not_translatable` moves the number, and every entry that moves it has a
 * named reason that `--all` prints. That is the difference between a filter and
 * an excuse.
 */
type Verdicts = {
  cleared: Map<string, string>;
  deferred: Map<string, string>;
  /** whole files a lane has read and cleared, with the reason */
  clearedFiles: Map<string, string>;
};

/**
 * Every English string this extension ships as a translation VALUE.
 *
 * Some code cannot hold a key. `looksmax-ranks/src/Catalog.php` is the case
 * that forced this: its RANKS/TIERS/BADGES constants are read by two other
 * extensions — `looksmax-userinfo/src/RankSource.php` matches on the lowercased
 * `name`, and `looksmax-store/src/Seed.php` copies `name` and `blurb` into the
 * `store_items` TABLE — so a key in those columns breaks one silently and
 * persists into the database in the other. The lane's answer was a `localize()`
 * seam that every read path runs through, leaving the English literal in place
 * as the fallback.
 *
 * A literal in that position is not an untranslated string; it is the last
 * resort behind a translated one. So a hardcoded literal that exactly equals a
 * value in the extension's own `en.yml` is credited — and reported under its
 * own rule, and counted separately in the summary, because "credited by
 * matching a shipped value" is a weaker claim than "this is a trans() call"
 * and the report should not pretend otherwise.
 *
 * Verified rather than assumed for the case that motivated it:
 *   GET /api/users/1?lang=es → "rankName":"Lumbrera","nextRankName":"Ascendido"
 *   GET /api/users/1?lang=en → "rankName":"Luminary","nextRankName":"Ascended"
 */
function shippedValues(ext: string): Set<string> {
  const out = new Set<string>();
  const file = join(EXT_DIR, ext, "locale", "en.yml");
  if (!existsSync(file)) return out;

  for (const line of readFileSync(file, "utf8").split("\n")) {
    const m = /^[^\S\n]+[\w.+-]+:[^\S\n]*(.+?)[^\S\n]*$/.exec(line);
    if (!m) continue;
    let v = m[1].trim();
    if (v === "" || v === ">-" || v === "|" || v === ">" || v.startsWith("#")) continue;
    if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
      v = v.slice(1, -1);
    }
    // An ICU message is not a literal any code holds.
    if (v.includes("{") || v.startsWith("=>")) continue;
    if (v.length > 1) out.add(v);
  }
  return out;
}

function loadVerdicts(ext: string): Verdicts {
  const cleared = new Map<string, string>();
  const deferred = new Map<string, string>();
  const clearedFiles = new Map<string, string>();
  const spec = join(EXT_DIR, ext, "locale", "i18n-map.json");

  if (!existsSync(spec)) return { cleared, deferred, clearedFiles };

  let json: any;
  try {
    json = JSON.parse(readFileSync(spec, "utf8"));
  } catch {
    return { cleared, deferred, clearedFiles };
  }

  // Whole files somebody has read and cleared. A keyword lexicon is the case
  // this exists for: `looksmax-index/src/Lexicon.php` holds ~200 weighted
  // matching tokens ("growth plates", "aromatase inhibitor", "bone smashing")
  // that are compared against post text and never rendered. Listing them one
  // by one would be 200 lines of spec saying the same thing.
  if (Array.isArray(json.not_translatable_files)) {
    for (const e of json.not_translatable_files) {
      if (typeof e?.file === "string") clearedFiles.set(e.file, e.reason ?? "cleared in i18n-map.json");
    }
  }

  const collect = (list: any, into: Map<string, string>) => {
    if (!Array.isArray(list)) return;
    for (const e of list) {
      const text: string | undefined = e.string ?? e.find ?? e.text ?? e.value;
      if (typeof text !== "string" || !text) continue;
      const file: string = e.file ?? "";
      // Keyed on file + the literal, never on a line number: these files are
      // being edited by other agents while this runs.
      into.set(`${file}::${text.trim()}`, e.reason ?? e.note ?? e.why ?? "recorded in i18n-map.json");
    }
  };

  collect(json.not_translatable, cleared);
  collect(json.notes?.not_translatable, cleared);
  collect(json.deferred, deferred);

  return { cleared, deferred, clearedFiles };
}

const findings: Finding[] = [];
const exts = readdirSync(EXT_DIR).filter(
  (d) => statSync(join(EXT_DIR, d)).isDirectory() && (!ONLY || d === ONLY),
);

for (const ext of exts) {
  const verdicts = loadVerdicts(ext);
  const shipped = shippedValues(ext);

  for (const file of walk(join(EXT_DIR, ext))) {
    const src = readFileSync(file, "utf8");
    const rel = relative(ROOT, file);
    let lits: Lit[];
    let lang: string;
    if (file.endsWith(".blade.php")) {
      lits = lexBlade(src);
      lang = "blade";
    } else if (file.endsWith(".php")) {
      lits = lex(src, "php");
      lang = "php";
    } else if (file.endsWith(".less")) {
      lits = lexLess(src);
      lang = "less";
    } else {
      lits = lex(src, "js");
      lang = "js";
    }
    /** The path a spec would name this file by: relative to the extension. */
    const inExt = relative(join(EXT_DIR, ext), file);

    for (const l of lits) {
      let v = classify(l, file, lang);

      if (v.class === "hardcoded") {
        const trimmed = l.value.trim();
        const fileVerdict = verdicts.clearedFiles.get(inExt);
        if (fileVerdict !== undefined) {
          findings.push({ ext, file: rel, line: l.line, value: l.value, class: "excluded", rule: `reviewed: ${fileVerdict}`, lang });
          continue;
        }
        const cleared = verdicts.cleared.get(`${inExt}::${trimmed}`);
        const deferred = verdicts.deferred.get(`${inExt}::${trimmed}`);

        if (cleared !== undefined) {
          v = { class: "excluded", rule: `reviewed: ${cleared}` };
        } else if (deferred !== undefined) {
          v = { class: "hardcoded", rule: `deferred: ${deferred}` };
        } else if (shipped.has(trimmed)) {
          v = { class: "translated", rule: "shipped-as-fallback" };
        }
      }

      findings.push({ ext, file: rel, line: l.line, value: l.value, class: v.class, rule: v.rule, lang });
    }
  }
}

/* locale/*.yml keys shipped, per extension — the supply side of the number */
function countKeys(dir: string): Record<string, number> {
  const out: Record<string, number> = {};
  if (!existsSync(dir)) return out;
  for (const f of readdirSync(dir)) {
    if (!/\.ya?ml$/.test(f)) continue;
    const src = readFileSync(join(dir, f), "utf8");
    /*
     * Leaf keys only — a line whose value is on the same line.
     *
     * The first version used `/^\s+[\w.+-]+:\s*\S/gm`, and JS `\s` matches a
     * NEWLINE. So `\s*\S` after the colon happily crossed the line break onto
     * the first character of the next line, which counted every PARENT node as
     * a key and swallowed the leaf beneath it. It reported en:40 es:41 for two
     * files with 39 identical keys each, the difference being where a blank
     * line happened to fall. A parity column that invents a missing key is
     * worse than no column. `[^\S\n]` is "whitespace that is not a newline".
     */
    const n = (src.match(/^[^\S\n]+[\w.+-]+:[^\S\n]*\S/gm) || []).length;
    out[f.replace(/\.ya?ml$/, "")] = n;
  }
  return out;
}

const perExt: Record<string, any> = {};
for (const ext of exts) {
  const f = findings.filter((x) => x.ext === ext);
  const hard = f.filter((x) => x.class === "hardcoded");
  const trans = f.filter((x) => x.class === "translated");
  const denom = hard.length + trans.length;
  perExt[ext] = {
    translated: trans.length,
    hardcoded: hard.length,
    coverage: denom ? +((trans.length / denom) * 100).toFixed(1) : 100,
    locales: countKeys(join(EXT_DIR, ext, "locale")),
    files: hard.reduce((a: Record<string, number>, x) => ((a[x.file] = (a[x.file] || 0) + 1), a), {}),
  };
}

const hard = findings.filter((x) => x.class === "hardcoded");
const trans = findings.filter((x) => x.class === "translated");
const denom = hard.length + trans.length;
const coverage = denom ? +((trans.length / denom) * 100).toFixed(1) : 100;

/* ───────────────────────────────────────────────────────────────── reporting */

if (JSON_OUT) {
  writeFileSync(
    JSON_OUT,
    JSON.stringify(
      { generated: new Date().toISOString(), coverage, translated: trans.length, hardcoded: hard.length, perExt, findings: hard },
      null,
      2,
    ),
  );
}

const pad = (s: string, n: number) => s + " ".repeat(Math.max(0, n - s.length));

const byRuleTrans = trans.reduce((a: Record<string, number>, x) => ((a[x.rule] = (a[x.rule] || 0) + 1), a), {});
const deferredCount = hard.filter((x) => x.rule.startsWith("deferred:")).length;

console.log("");
console.log(`  i18n coverage  ${coverage}%   (${trans.length} translated / ${denom} user-visible strings)`);
console.log("");
console.log(
  `  of the ${trans.length} translated: ${byRuleTrans["translation-key"] ?? 0} are keys in a trans() call, ` +
    `${byRuleTrans["translator.trans"] ?? 0} are a translator call, ` +
    `${byRuleTrans["shipped-as-fallback"] ?? 0} are English fallbacks behind a translation seam`,
);
console.log(
  `  of the ${hard.length} hardcoded: ${deferredCount} are deferred with a written design, ` +
    `${hard.length - deferredCount} are unreviewed`,
);
console.log("");
console.log(`  ${pad("extension", 22)}${pad("cov", 8)}${pad("keyed", 8)}${pad("hardcoded", 11)}locale packs`);
console.log(`  ${"─".repeat(70)}`);
for (const ext of Object.keys(perExt).sort((a, b) => perExt[a].coverage - perExt[b].coverage)) {
  const p = perExt[ext];
  if (p.translated + p.hardcoded === 0) continue;
  const packs = Object.entries(p.locales as Record<string, number>)
    .map(([k, v]) => `${k}:${v}`)
    .join(" ") || "—";
  console.log(
    `  ${pad(ext, 22)}${pad(p.coverage + "%", 8)}${pad(String(p.translated), 8)}${pad(String(p.hardcoded), 11)}${packs}`,
  );
}

console.log("");
if (hard.length) {
  console.log("  hardcoded user-visible strings");
  console.log(`  ${"─".repeat(70)}`);
  let cur = "";
  for (const f of hard.sort((a, b) => a.file.localeCompare(b.file) || a.line - b.line)) {
    if (f.file !== cur) {
      cur = f.file;
      console.log(`\n  ${cur}`);
    }
    const v = f.value.length > 78 ? f.value.slice(0, 75) + "…" : f.value;
    console.log(`    :${pad(String(f.line), 6)}${JSON.stringify(v)}`);
  }
  console.log("");
}

if (SHOW_ALL) {
  const byRule: Record<string, number> = {};
  for (const f of findings.filter((x) => x.class === "excluded")) byRule[f.rule] = (byRule[f.rule] || 0) + 1;
  console.log("  excluded from the denominator, by rule");
  console.log(`  ${"─".repeat(70)}`);
  for (const [r, n] of Object.entries(byRule).sort((a, b) => b[1] - a[1])) console.log(`    ${pad(r, 22)}${n}`);
  console.log("");
  console.log("  excluded strings that still read as prose (review these)");
  for (const f of findings.filter((x) => x.class === "excluded" && x.rule !== "not-prose" && looksLikeProse(x.value))) {
    console.log(`    ${f.file}:${f.line}  [${f.rule}] ${JSON.stringify(f.value.slice(0, 70))}`);
  }
  console.log("");
}

if (CHECK) {
  if (coverage < FLOOR) {
    console.log(`  FAIL  coverage ${coverage}% is below the ${FLOOR}% floor — ${hard.length} hardcoded strings`);
    process.exit(1);
  }
  console.log(`  PASS  coverage ${coverage}% >= ${FLOOR}%`);
}
