/**
 * The box in Spanish.
 *
 * Spanish became the forum's DEFAULT locale while this lane was working, so
 * this is the state most visitors see, not an edge case. Spanish strings run
 * 20-40% longer than English ones and this card is 300px wide, so the risk is
 * not the translation — it is "Inicia sesión para chatear" wrapping, or
 * "Sin conexión — reconectando" pushing the retry button out of its bar.
 *
 * Every check here is a layout check with a Spanish string in it, plus the
 * screenshots to read.
 */
import {
  BASE, SHOTS, ADMIN_USER, ADMIN_PASS,
  fresh, login, sql, shotEl, cycles, sleep, until, Report, watchErrors, ownErrors,
} from "./lib.ts";

const out = `${SHOTS}/spanish`;
const R = new Report();

const b = await fresh(21991);
const errors = watchErrors(b);

// The document locale drives both Flarum's translator and lmxI18n's Intl
// formatting; ?locale is not a thing, so the preference is set the way a user
// sets it — through the account, for a logged-in session.
const l = await login(b, ADMIN_USER, ADMIN_PASS);
R.check("logged in", l.startsWith("ok:"), l);
await b.eval(`window.flarum.core.app.session.user.save({ preferences: { locale: 'es' } })`, true);
await sleep(2500);
await b.goto(BASE + "/");
await cycles(1);

const lang = await b.eval(`document.documentElement.lang`);
R.check("the document is being served in Spanish", lang === "es", `lang=${lang}`);

const strings = await b.eval(`({
  placeholder: document.querySelector('.LmxChat-input')?.placeholder,
  here: document.querySelector('.LmxChat-here')?.textContent,
  title: document.querySelector('.LmxChat-title')?.textContent,
  send: document.querySelector('.LmxChat-send')?.getAttribute('aria-label'),
})`);
R.check("the composer is translated", strings.placeholder === "Di algo…", JSON.stringify(strings));
R.check("the presence label is translated", strings.here === "aquí", JSON.stringify(strings));
R.check("no raw translation keys on screen",
  !JSON.stringify(strings).includes("local-looksmax-chat"), JSON.stringify(strings));

// --- layout with the longer strings ----------------------------------------
const geo = await b.eval(`(() => {
  const card = document.querySelector('.LmxChat').getBoundingClientRect();
  const nodes = [...document.querySelectorAll('.LmxChat *')]
    .filter(n => getComputedStyle(n).position !== 'absolute');
  const over = nodes.filter(n => n.getBoundingClientRect().right > card.right - 1);
  const input = document.querySelector('.LmxChat-input');
  return {
    overflowing: over.map(n => n.className).slice(0, 4),
    placeholderFits: input.scrollWidth <= input.clientWidth + 1,
    headH: Math.round(document.querySelector('.LmxChat h3').getBoundingClientRect().height),
  };
})()`);
R.check("nothing overflows the card in Spanish", geo.overflowing.length === 0, JSON.stringify(geo));
R.check("the Spanish placeholder fits the input", geo.placeholderFits, JSON.stringify(geo));
R.check("the header is still one line", geo.headH <= 30, JSON.stringify(geo));
await shotEl(b, ".LmxChat", `${out}/01-es-desktop.png`);

// --- the longest strings: guest prompt, offline banner ----------------------
const g = await fresh(21992);
await g.goto(BASE + "/");
await cycles(1);
const guestEs = await g.eval(`({
  login: document.querySelector('.LmxChat-login')?.textContent,
  note: document.querySelector('.LmxChat-guest span')?.textContent,
  loginLines: (() => { const el = document.querySelector('.LmxChat-login');
    return el ? Math.round(el.getBoundingClientRect().height / parseFloat(getComputedStyle(el).lineHeight || '16')) : 0; })(),
  fits: (() => { const el = document.querySelector('.LmxChat-login');
    const card = document.querySelector('.LmxChat');
    return !!el && el.getBoundingClientRect().right <= card.getBoundingClientRect().right; })(),
})`);
R.check("the guest prompt is translated and fits",
  guestEs.login === "Inicia sesión para chatear" && guestEs.fits, JSON.stringify(guestEs));
await shotEl(g, ".LmxChat", `${out}/02-es-guest.png`);

// the offline bar carries the longest string plus a button
await g.send("Network.setBlockedURLs", { urls: ["*/api/chat*"] });
await sleep(14000);
const offEs = await g.eval(`(() => {
  const a = document.querySelector('.LmxChat-alert');
  if (!a || a.hidden) return null;
  const card = document.querySelector('.LmxChat').getBoundingClientRect();
  const btn = a.querySelector('.LmxChat-retry');
  return { text: a.textContent.trim(),
           fits: a.getBoundingClientRect().right <= card.right + 1,
           btnVisible: !!btn && btn.getBoundingClientRect().width > 10 &&
                       btn.getBoundingClientRect().right <= card.right,
           lines: Math.round(a.getBoundingClientRect().height) };
})()`);
R.check("the Spanish offline bar keeps its button on the card",
  !!offEs && offEs.fits && offEs.btnVisible && /reconectando/i.test(offEs.text), JSON.stringify(offEs));
await shotEl(g, ".LmxChat", `${out}/03-es-offline.png`);
await g.send("Network.setBlockedURLs", { urls: [] });

// --- mobile, in Spanish ------------------------------------------------------
await b.viewport({ name: "mobile", width: 420, height: 844, mobile: true });
await b.goto(BASE + "/");
await cycles(1);
const mobEs = await b.eval(`(() => {
  const card = document.querySelector('.LmxChat');
  if (!card) return null;
  const r = card.getBoundingClientRect();
  const over = [...card.querySelectorAll('*')]
    .filter(n => getComputedStyle(n).position !== 'absolute')
    .filter(n => n.getBoundingClientRect().right > r.right - 1).length;
  return { w: Math.round(r.width), over, head: document.querySelector('.LmxChat h3').getBoundingClientRect().height };
})()`);
R.check("mobile Spanish: nothing overflows", !!mobEs && mobEs.over === 0, JSON.stringify(mobEs));
await shotEl(b, ".LmxChat", `${out}/04-es-mobile.png`);

// put the account back the way it was found
await b.viewport({ name: "desktop", width: 1600, height: 1000 });
await b.goto(BASE + "/");
await b.eval(`window.flarum.core.app.session.user.save({ preferences: { locale: null } })`, true).catch(() => {});

const own = ownErrors(errors);
R.check("no console errors owned by the chat in Spanish", own.length === 0, JSON.stringify(own.slice(0, 4)));

await b.close();
await g.close();
process.exit(R.summary() ? 0 : 1);
