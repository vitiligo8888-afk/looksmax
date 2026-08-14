#!/usr/bin/env bun
/**
 * What language is the CONTENT actually in?
 *
 *   bun tools/corpus-language.ts /path/to/posts.txt   # one post per line
 *
 * The interface is becoming Spanish-first. Whether that matches what people are
 * actually reading is a separate and measurable question, and it is a product
 * decision that should be made against the real distribution rather than an
 * assumption about the audience.
 *
 * Detection is stopword scoring across the six languages the corpus is known to
 * contain, plus a script check for Cyrillic. That is crude next to a trained
 * classifier and it is the right tool here: forum posts are long enough for
 * stopword frequency to be decisive, the language set is closed and known in
 * advance, and what is wanted is a distribution rather than a per-post label
 * that anything depends on.
 *
 * Posts too short to call get their own bucket instead of being forced into a
 * language. An "undetermined" count that is honest is worth more than a
 * confident number that is wrong, and on a looksmaxing board a large share of
 * posts really are three words long.
 */

const STOP: Record<string, string[]> = {
  es: ["que","de","la","el","en","los","las","un","una","por","con","para","es","no","se","lo","del","como","más","pero","si","mi","te","tu","al","ya","muy","este","esta","son","hay","bien","todo","porque","cuando","también","sobre","hacer","tiene","puede","eso","así","gente","cara","años","tienes","estoy","tengo","nada","aquí"],
  en: ["the","and","you","that","for","are","with","this","have","not","but","your","all","can","just","like","what","was","from","they","been","would","about","get","how","out","some","its","only","them","when","more","will","who","because","people","face","really","think","also","there","should","could","even"],
  ru: ["что","как","это","для","так","все","его","она","они","мне","или","есть","быть","тебя","меня","если","только","уже","при","даже","лица","лицо","очень","можно","надо","тоже","этот","была","было","где"],
  tr: ["bir","bu","ve","için","ile","daha","çok","ama","gibi","olan","olarak","kadar","sonra","ben","sen","var","yok","şey","şu","ne","yüz","biraz","tamam","değil","böyle","zaten","sadece","kendi"],
  de: ["der","die","und","den","das","ist","nicht","mit","auch","auf","für","sich","dem","eine","einen","aber","noch","werden","haben","sein","wie","nur","man","schon","wenn","gesicht","mich","dich","kann","aus"],
  fr: ["les","des","est","une","que","pour","dans","pas","qui","sur","avec","plus","mais","tout","comme","être","fait","bien","son","ses","aux","donc","alors","visage","peut","cette","vous","nous","très"],
};

const CYRILLIC = /[Ѐ-ӿ]/;

export function detect(text: string): string {
  // BBCode tags and HTML are markup, not language, and [QUOTE author=...]
  // carries usernames that skew a short post badly.
  const t = text
    .toLowerCase()
    .replace(/\[[^\]]*\]/g, " ")
    .replace(/<[^>]*>/g, " ")
    .replace(/https?:\/\/\S+/g, " ");

  const words = t.match(/[\p{L}]+/gu) || [];
  if (words.length < 8) return "too-short";

  if (CYRILLIC.test(t)) {
    const cyr = (t.match(/[Ѐ-ӿ]/g) || []).length;
    const lat = (t.match(/[a-z]/g) || []).length;
    if (cyr > lat) return "ru";
  }

  const set = new Set(words);
  const scores: Array<[string, number]> = [];
  for (const [lang, stops] of Object.entries(STOP)) {
    let hits = 0;
    for (const s of stops) if (set.has(s)) hits++;
    // Normalised by list length, so a longer list is not automatically favoured.
    scores.push([lang, hits / stops.length]);
  }
  scores.sort((a, b) => b[1] - a[1]);

  const [best, bestScore] = scores[0];
  const second = scores[1][1];

  if (bestScore < 0.04) return "undetermined";
  // Spanish and French share a great many short function words; without a
  // margin requirement the Spanish count absorbs most of the French.
  if (second / bestScore > 0.75) return "ambiguous";

  return best;
}

if (import.meta.main) {
  const path = process.argv[2];
  if (!path) {
    console.error("usage: bun tools/corpus-language.ts <file with one post per line>");
    process.exit(2);
  }

  const counts: Record<string, number> = {};
  let n = 0;

  const text = await Bun.file(path).text();
  for (const line of text.split("\n")) {
    if (!line.trim()) continue;
    n++;
    const l = detect(line);
    counts[l] = (counts[l] || 0) + 1;
  }

  const rows = Object.entries(counts).sort((a, b) => b[1] - a[1]);
  const pad = (s: string, w: number) => s + " ".repeat(Math.max(0, w - s.length));

  console.log("");
  console.log(`  posts analysed: ${n.toLocaleString("en-US")}`);
  console.log("");
  for (const [lang, c] of rows) {
    console.log(`  ${pad(lang, 14)}${String(c).padStart(8)}  ${((c / n) * 100).toFixed(1).padStart(5)}%`);
  }

  const decided = rows.filter(([l]) => !["too-short", "undetermined", "ambiguous"].includes(l));
  const total = decided.reduce((a, [, c]) => a + c, 0);
  console.log("");
  console.log(`  of the ${total.toLocaleString("en-US")} posts confidently identified:`);
  for (const [lang, c] of decided) {
    console.log(`  ${pad(lang, 14)}${String(c).padStart(8)}  ${((c / total) * 100).toFixed(1).padStart(5)}%`);
  }
  console.log("");
}
