/*
 * The forum-side i18n runtime.
 *
 * Two jobs, and they are separate on purpose.
 *
 * 1. `window.lmxI18n` — locale-aware formatting that every other lane can call.
 *    Ten thousand posts is "10,000" in English and "10.000" in Spanish, a join
 *    date is "Mar 2019" and "mar 2019", and "3 hours ago" is "hace 3 horas".
 *    None of that comes out of a translation string; it comes out of Intl, and
 *    Intl needs to be handed the right locale. Before this file every one of
 *    those was hardcoded English formatting sitting next to translated text,
 *    which reads worse than not translating at all.
 *
 * 2. A raw-key sentinel. The whole point of this lane is that no visitor ever
 *    reads `core.forum.post_scrubber.original_post_link`, and that failure is
 *    invisible to HTTP 200, to the LESS build, to `docker ps` and to every
 *    check this project already runs — it has happened twice on this box in one
 *    day. So the page counts them itself and publishes the count on
 *    `window.__lmxI18n`, and e2e/i18n-gate.ts fails the deploy on a non-zero.
 *
 * Shipped as its own <script> rather than through Extend\Frontend::js(), which
 * is the house pattern here: Flarum concatenates every extension's forum.js
 * into one bundle, so a top-level throw anywhere kills every extension after
 * it. An inline element fails alone.
 */
(function () {
  'use strict';

  var app = null;

  function boot() {
    app = (window.flarum && window.flarum.core && window.flarum.core.app) || window.app || null;
    return app;
  }

  /** The locale the server rendered this document in. */
  function locale() {
    var a = app || boot();
    var l = (a && a.data && a.data.locale) || document.documentElement.lang || 'en';
    // `es` is served to a Mexican audience; es-MX and es differ on nothing we
    // format, but asking for es-MX gets the right month abbreviations from
    // browsers that ship regional data and falls back cleanly on those that
    // do not.
    return l === 'es' ? 'es-MX' : l;
  }

  /*
   * Intl constructors are not cheap and these run inside Mithril view
   * functions, which re-run on every redraw. One formatter per (locale, shape)
   * pair, built once.
   */
  var cache = {};
  function fmt(kind, opts) {
    var key = kind + '|' + locale() + '|' + JSON.stringify(opts || {});
    if (cache[key]) return cache[key];
    var made;
    try {
      if (kind === 'n') made = new Intl.NumberFormat(locale(), opts);
      else if (kind === 'd') made = new Intl.DateTimeFormat(locale(), opts);
      else if (kind === 'r') made = new Intl.RelativeTimeFormat(locale(), opts);
      else if (kind === 'l') made = new Intl.ListFormat(locale(), opts);
    } catch (e) {
      made = null;
    }
    cache[key] = made;
    return made;
  }

  function toDate(v) {
    if (v == null) return null;
    if (v instanceof Date) return isNaN(v.getTime()) ? null : v;
    if (typeof v === 'number') return new Date(v);
    // MySQL datetimes arrive as "2019-03-04 11:22:33" in some payloads on this
    // install and as ISO-8601 in others. Safari refuses the former outright.
    var d = new Date(String(v).replace(' ', 'T'));
    return isNaN(d.getTime()) ? null : d;
  }

  var api = {
    locale: locale,

    /** 12431 → "12,431" / "12.431" */
    num: function (n, opts) {
      var f = fmt('n', opts);
      var x = Number(n);
      if (!isFinite(x)) return '';
      return f ? f.format(x) : String(x);
    },

    /**
     * 12431 → "12.4K" / "12,4 mil".
     *
     * `notation: 'compact'` is what makes this locale-correct — Spanish says
     * "mil" and "M", not "k". Older browsers ignore the option and return the
     * full number, which is wide but never wrong.
     */
    compact: function (n) {
      var x = Number(n);
      if (!isFinite(x)) return '';
      if (Math.abs(x) < 1000) return api.num(x);
      return api.num(x, { notation: 'compact', maximumFractionDigits: 1 });
    },

    /** The exact figure, for a title attribute beside a compact one. */
    exact: function (n) {
      return api.num(n);
    },

    pct: function (n, digits) {
      return api.num(Number(n) / 100, {
        style: 'percent',
        maximumFractionDigits: digits == null ? 0 : digits,
      });
    },

    /** "4 March 2019" / "4 de marzo de 2019" */
    date: function (v, opts) {
      var d = toDate(v);
      if (!d) return '';
      var f = fmt('d', opts || { day: 'numeric', month: 'long', year: 'numeric' });
      return f ? f.format(d) : d.toDateString();
    },

    /** "Mar 2019" / "mar 2019" — a tenure signal, not an appointment. */
    monthYear: function (v) {
      return api.date(v, { month: 'short', year: 'numeric' });
    },

    /** "4 Mar 2019, 11:22" */
    dateTime: function (v) {
      return api.date(v, {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
      });
    },

    /**
     * "3 hours ago" / "hace 3 horas".
     *
     * Deliberately not dayjs: core loads a dayjs locale for the active language
     * but our own scripts run outside core's module graph and cannot reach it,
     * and Intl.RelativeTimeFormat has been available in every browser this
     * forum supports since 2020. Units step at the boundary a reader expects,
     * not at exact multiples — 45 minutes reads as an hour, not as 45 minutes.
     */
    rel: function (v) {
      var d = toDate(v);
      if (!d) return '';
      var f = fmt('r', { numeric: 'auto' });
      var s = (d.getTime() - Date.now()) / 1000;
      var abs = Math.abs(s);
      var unit, value;
      if (abs < 45) { unit = 'second'; value = s; }
      else if (abs < 2700) { unit = 'minute'; value = s / 60; }
      else if (abs < 79200) { unit = 'hour'; value = s / 3600; }
      else if (abs < 2160000) { unit = 'day'; value = s / 86400; }
      else if (abs < 31536000) { unit = 'month'; value = s / 2592000; }
      else { unit = 'year'; value = s / 31536000; }
      value = Math[value < 0 ? 'ceil' : 'floor'](value) || (s < 0 ? -1 : 1);
      if (!f) return api.date(v);
      return f.format(value, unit);
    },

    /** "a, b and c" / "a, b y c" */
    list: function (items) {
      var arr = (items || []).filter(Boolean);
      var f = fmt('l', { style: 'long', type: 'conjunction' });
      return f ? f.format(arr) : arr.join(', ');
    },

    /**
     * trans() with a loud failure.
     *
     * app.translator.trans() returns the KEY when a key is missing, which is
     * the whole disaster this lane exists to prevent, and it does so silently.
     * Here the miss is recorded so the gate can fail on it, and the optional
     * fallback keeps a half-deployed extension readable in the meantime.
     */
    t: function (key, params, fallback) {
      var a = app || boot();
      if (!a || !a.translator) return fallback == null ? key : fallback;
      var out = a.translator.trans(key, params || {});
      // Mithril vnodes come back for strings containing tags; only a bare
      // string can be compared to the key.
      if (typeof out === 'string' && out === key) {
        misses[key] = (misses[key] || 0) + 1;
        return fallback == null ? key : fallback;
      }
      return out;
    },
  };

  var misses = {};

  /* ────────────────────────────────────────────────── the raw-key sentinel */

  /*
   * A translation key that reached the screen looks like `a.b.c` and nothing
   * else on this forum does: usernames cannot contain dots in that shape, and
   * the only dotted bare text a post can produce is a domain, which this
   * pattern rejects by requiring at least three segments and no TLD-shaped
   * final part... except that `looksmax.lat.example` would pass. Hence the
   * prefix list: only namespaces this install actually ships are reported, so
   * a false positive is impossible and a real miss is certain.
   */
  var PREFIXES = ['core.', 'flarum-', 'local-', 'lmx.', 'validation.'];
  var KEY = /^[a-z][a-z0-9_]*(?:-[a-z0-9_]+)*(?:\.[a-z0-9_-]+){2,}$/;

  function isRawKey(text) {
    var t = (text || '').trim();
    if (t.length < 8 || t.length > 120 || /\s/.test(t)) return false;
    if (!KEY.test(t)) return false;
    for (var i = 0; i < PREFIXES.length; i++) {
      if (t.indexOf(PREFIXES[i]) === 0) return true;
    }
    return false;
  }

  function scan() {
    var found = [];
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
    var node;
    while ((node = walker.nextNode())) {
      if (isRawKey(node.nodeValue)) {
        var el = node.parentElement;
        found.push({
          key: node.nodeValue.trim(),
          where: el ? el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ')[0] : '') : '?',
        });
      }
    }
    // Attributes a user reads but cannot select: tooltips, placeholders, labels.
    var attrs = ['title', 'placeholder', 'aria-label', 'alt', 'value'];
    var all = document.body.querySelectorAll('[title],[placeholder],[aria-label],[alt]');
    for (var i = 0; i < all.length; i++) {
      for (var a = 0; a < attrs.length; a++) {
        var v = all[i].getAttribute(attrs[a]);
        if (isRawKey(v)) found.push({ key: v.trim(), where: '@' + attrs[a] + ' on ' + all[i].tagName.toLowerCase() });
      }
    }
    return found;
  }

  function publish() {
    var raw = scan();
    window.__lmxI18n = {
      locale: locale(),
      documentLang: document.documentElement.lang,
      available: (app && app.data && app.data.locales) || null,
      rawKeys: raw,
      rawKeyCount: raw.length,
      missingKeys: Object.keys(misses),
      ready: true,
    };
    if (raw.length && /[?&]i18ndebug=1/.test(location.search)) {
      console.warn('[lmx-i18n] ' + raw.length + ' raw translation keys on screen', raw);
    }
  }

  /* ─────────────────────────────────────────── moving the control off the bar */

  /*
   * The header is shared and crowded — search, the credits chip, notifications
   * and the session menu all live in the same 500px — and the operator has
   * already reported the locale control overlapping the wordmark. For a signed
   * in member the language belongs in the session menu next to their other
   * preferences, which costs the header nothing at all.
   *
   * The header control is only hidden once this has demonstrably worked: the
   * CSS keys off a class set at the end of this function, so if core renames
   * the component, or the registry key is wrong, or the extend helper is
   * missing, the failure mode is "the language control is still in the header"
   * and not "there is no way to change language".
   */
  function registry() {
    return (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  }

  /* Both spellings: this install's registry keys carry no `forum/` prefix, but
     that is a measured fact about this build, not a guarantee about the next. */
  function mod(name) {
    var c = registry();
    var found = c[name] !== undefined ? c[name] : c['forum/' + name];
    if (found && found.default && !found.prototype && !found.extend) return found.default;
    return found;
  }

  function switchTo(code) {
    var a = app || boot();
    if (!a) return;
    if (a.session && a.session.user) {
      a.session.user.savePreferences({ locale: code }).then(function () {
        window.location.reload();
      });
    } else {
      document.cookie = 'locale=' + code + '; path=/; max-age=31536000; SameSite=Lax';
      window.location.reload();
    }
  }

  function foldIntoSessionMenu() {
    var a = app || boot();
    if (!a || !a.session || !a.session.user) return false;

    var locales = (a.data && a.data.locales) || {};
    var codes = Object.keys(locales);
    if (codes.length < 2) return false;

    var SessionDropdown = mod('components/SessionDropdown');
    var Button = mod('common/components/Button');
    var Separator = mod('common/components/Separator');
    var m = window.m;

    if (!SessionDropdown || !SessionDropdown.prototype || !Button || !m) return false;

    /*
     * Wrap the method rather than using flarum's `extend()` helper.
     *
     * Measured on this build: `common/utils/extend` is NOT in
     * `flarum.core.compat` at all — `components/SessionDropdown`,
     * `common/components/Button` and `common/components/Separator` are all
     * there under their bare names, and the extend helper is simply absent. So
     * the first version returned false every time, silently, and the fail-safe
     * did its job: the header control stayed visible and nobody lost the
     * ability to change language. It just never moved off the header, which is
     * the whole point of it.
     *
     * `extend()` is nine lines that append to the return value of a method.
     * Doing it directly costs nothing and removes the dependency on a registry
     * key this build does not publish.
     */
    if (SessionDropdown.prototype.__lmxLocale) return true;
    var original = SessionDropdown.prototype.items;
    SessionDropdown.prototype.__lmxLocale = true;

    SessionDropdown.prototype.items = function () {
      var items = original.apply(this, arguments);
      addLocaleItems(items);
      return items;
    };

    function addLocaleItems(items) {
      if (!items || typeof items.add !== 'function') return;
      if (items.has && items.has('lmxLocaleSeparator')) return;

      // Below the account links, above Log Out, which core adds at -100.
      if (Separator) items.add('lmxLocaleSeparator', m(Separator), -85);

      codes.forEach(function (code, i) {
        var active = code === a.data.locale;
        items.add(
          'lmxLocale-' + code,
          m(
            Button,
            {
              icon: active ? 'fas fa-check' : true,
              active: active,
              onclick: function () { switchTo(code); },
            },
            locales[code],
          ),
          -86 - i,
        );
      });
    }

    document.documentElement.classList.add('lmx-locale-in-session');
    return true;
  }

  /*
   * A 34px square reading "ES" is compact but silent on hover. Core sets an
   * aria-label on that button and no title, so a screen reader is already told
   * what it is and a pointer is not. Copying one to the other is a two-line
   * fix that needs no override of core's component.
   */
  function labelHeaderControl() {
    var btn = document.querySelector('.item-locale .Dropdown-toggle');
    if (!btn || btn.getAttribute('title')) return;
    var label = btn.getAttribute('aria-label');
    if (label) btn.setAttribute('title', label);
  }

  window.lmxI18n = api;
  window.__lmxI18n = { ready: false, rawKeyCount: null };

  function start() {
    boot();
    try { foldIntoSessionMenu(); } catch (e) { /* header control stays */ }
    labelHeaderControl();
    publish();
    // The SPA repaints without a navigation, so re-scan after route changes and
    // after the redraws that follow an API response.
    var last = location.href;
    setInterval(function () {
      labelHeaderControl();
      if (location.href !== last) {
        last = location.href;
        setTimeout(publish, 400);
      }
    }, 500);
  }

  if (document.readyState === 'complete') setTimeout(start, 300);
  else window.addEventListener('load', function () { setTimeout(start, 300); });
})();
