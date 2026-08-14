/*
 * Appearance controls — the client half.
 *
 * tokens.less ships THREE independent axes and, until this file existed, all
 * three were unreachable: nothing in the forum ever wrote data-scheme,
 * data-density or data-decor, so every `:root[data-*]` block in that file was
 * dead code from the day it was written.
 *
 *   data-scheme    dark (default, true black) | light | neon
 *   data-density   normal (default) | compact | roomy
 *   data-decor     full (default) | subtle | off
 *
 * They are deliberately SEPARATE axes rather than a handful of bundled
 * "themes": someone who wants the light scheme does not necessarily want a
 * roomier layout, and the single most common real request — "turn off the
 * moving background" — is a decor question, not a colour one.
 *
 * ── why the apply step runs in <head>, synchronously ────────────────────────
 * A theme read from storage and applied after first paint is a white flash on
 * every navigation for anyone not on the default. The apply half of this file
 * therefore runs immediately, before <body> is parsed, and touches only
 * documentElement — which exists by then. The UI half waits for the SPA.
 *
 * ── why localStorage and not a user preference ──────────────────────────────
 * A server-side preference rides on the user payload, which is not readable
 * until the SPA boots — i.e. after first paint, which is the exact flash this
 * avoids. It is also per-account, so a logged-out visitor could not hold a
 * choice at all. Per-device is the correct scope for "which colours do MY eyes
 * want". The tradeoff is real and deliberate: the choice does not follow you to
 * another device.
 *
 * ── why the panel is DOM-injected ──────────────────────────────────────────
 * Same reason looksmax-cosmetics decorates /settings instead of routing: this
 * script is its own <script> element, outside the extension bundle, so it has
 * no import of flarum's component classes and cannot extend SettingsPage. One
 * observer over a page that already exists is the whole implementation.
 */
(function () {
  'use strict';

  // Each axis: the <html> attribute, the storage key, the default, and the
  // options. `value: null` means "write no attribute" — the :root defaults in
  // tokens.less ARE that option, so writing an attribute for it would be a lie
  // about which rules are in play.
  var AXES = [
    {
      attr: 'data-scheme',
      key: 'lmx-scheme',
      label: 'Tema',
      help: 'Los colores de todo el foro.',
      options: [
        { value: null, label: 'Negro', hint: 'Predeterminado' },
        { value: 'light', label: 'Claro', hint: '' },
        { value: 'neon', label: 'Verde neón', hint: '' }
      ]
    },
    {
      attr: 'data-density',
      key: 'lmx-density',
      label: 'Densidad',
      help: 'Cuánto contenido entra en pantalla.',
      options: [
        { value: 'compact', label: 'Compacta', hint: 'Más por pantalla' },
        { value: null, label: 'Normal', hint: 'Predeterminado' },
        { value: 'roomy', label: 'Amplia', hint: 'Más espacio' }
      ]
    },
    {
      attr: 'data-decor',
      key: 'lmx-decor',
      label: 'Fondo',
      help: 'Los degradados y la textura del fondo.',
      options: [
        { value: null, label: 'Completo', hint: 'Predeterminado' },
        { value: 'subtle', label: 'Sutil', hint: '' },
        { value: 'off', label: 'Ninguno', hint: 'Más contraste' }
      ]
    }
  ];

  function read(axis) {
    try {
      var v = window.localStorage.getItem(axis.key);
      for (var i = 0; i < axis.options.length; i++) {
        if (axis.options[i].value === v) return v;
      }
    } catch (e) { /* storage blocked (private mode) — use default */ }
    return null;
  }

  function apply(axis, value) {
    if (value === null) {
      document.documentElement.removeAttribute(axis.attr);
    } else {
      document.documentElement.setAttribute(axis.attr, value);
    }
  }

  function write(axis, value) {
    try {
      if (value === null) window.localStorage.removeItem(axis.key);
      else window.localStorage.setItem(axis.key, value);
    } catch (e) { /* non-fatal */ }
    apply(axis, value);
  }

  // ---- apply now, before first paint ---------------------------------------
  for (var i = 0; i < AXES.length; i++) apply(AXES[i], read(AXES[i]));

  // ---- the /settings panel -------------------------------------------------
  // Rendered once per visit. Guarded by an id check rather than a flag, because
  // the SPA tears the page down and rebuilds it on every navigation back to it,
  // and a flag would suppress the second render.
  function buildAxis(axis) {
    var group = document.createElement('div');
    group.className = 'LmxAppearance-axis';

    var head = document.createElement('div');
    head.className = 'LmxAppearance-axisHead';

    var name = document.createElement('span');
    name.className = 'LmxAppearance-axisName';
    name.textContent = axis.label;
    head.appendChild(name);

    var help = document.createElement('span');
    help.className = 'LmxAppearance-axisHelp';
    help.textContent = axis.help;
    head.appendChild(help);

    group.appendChild(head);

    var row = document.createElement('div');
    row.className = 'LmxAppearance-options';

    var current = read(axis);

    axis.options.forEach(function (opt) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'LmxAppearance-option';
      btn.setAttribute('aria-pressed', String(opt.value === current));

      var l = document.createElement('span');
      l.className = 'LmxAppearance-optionLabel';
      l.textContent = opt.label;
      btn.appendChild(l);

      if (opt.hint) {
        var h = document.createElement('span');
        h.className = 'LmxAppearance-optionHint';
        h.textContent = opt.hint;
        btn.appendChild(h);
      }

      btn.addEventListener('click', function () {
        write(axis, opt.value);
        // Re-sync the whole row, not just this button: pressed state is
        // mutually exclusive and the previously pressed one must release.
        var all = row.querySelectorAll('.LmxAppearance-option');
        for (var k = 0; k < all.length; k++) all[k].setAttribute('aria-pressed', 'false');
        btn.setAttribute('aria-pressed', 'true');
      });

      row.appendChild(btn);
    });

    group.appendChild(row);
    return group;
  }

  function injectPanel() {
    var page = document.querySelector('.SettingsPage .container');
    if (!page || document.getElementById('lmx-appearance')) return;

    var box = document.createElement('fieldset');
    box.id = 'lmx-appearance';
    box.className = 'Settings-appearance';

    var legend = document.createElement('legend');
    legend.textContent = 'Apariencia';
    box.appendChild(legend);

    for (var i = 0; i < AXES.length; i++) box.appendChild(buildAxis(AXES[i]));

    var note = document.createElement('p');
    note.className = 'helpText LmxAppearance-note';
    note.textContent = 'Se guarda en este dispositivo.';
    box.appendChild(note);

    page.appendChild(box);
  }

  function start() {
    injectPanel();
    new MutationObserver(function () {
      try { injectPanel(); } catch (e) { /* never throw into the observer */ }
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();

/*
 * Scroll-to-top.
 *
 * A SEPARATE IIFE, not folded into the appearance-controls one above: same
 * reasoning as InjectSignature being its own <script> rather than tacked onto
 * an existing bundle -- a throw in either feature must not take the other
 * down with it, and the two share nothing but the file they are shipped in.
 *
 * Every long list on this forum (a discussion, a tag, search results) had no
 * way back to the top except dragging the scrollbar. The button mounts once
 * on `document.body`, which the SPA never tears down between routes (unlike
 * `.SettingsPage .container` above, which is why THAT feature needs a
 * MutationObserver and this one does not).
 */
(function () {
  'use strict';

  try {
    if (document.getElementById('lmx-totop')) return;

    // Same fallback chain looksmax-search's forum.js uses: the shared
    // formatter/translator singleton first, core's translator second, a
    // literal Spanish string last -- so the label is still correct even if
    // this script runs before either is ready.
    function tr(key, fallback) {
      try {
        if (window.lmxI18n && typeof window.lmxI18n.t === 'function') {
          var s = window.lmxI18n.t(key, {}, fallback);
          if (typeof s === 'string' && s) return s;
        }
      } catch (e) { /* fall through */ }
      try {
        var app = window.flarum && window.flarum.core && window.flarum.core.app;
        if (app && app.translator) {
          var s2 = app.translator.trans(key, {});
          if (typeof s2 === 'string' && s2 && s2 !== key) return s2;
        }
      } catch (e) { /* fall through */ }
      return fallback;
    }

    function prefersReducedMotion() {
      try {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      } catch (e) { return false; }
    }

    function mount() {
      if (!document.body || document.getElementById('lmx-totop')) return;

      var btn = document.createElement('button');
      btn.id = 'lmx-totop';
      btn.type = 'button';
      btn.className = 'LmxToTop';
      btn.setAttribute('aria-label', tr('local-looksmax-theme.forum.totop.label', 'Volver arriba'));
      document.body.appendChild(btn);

      var THRESHOLD = 480;
      var visible = false;
      var ticking = false;

      function sync() {
        ticking = false;
        var shouldShow = (window.scrollY || document.documentElement.scrollTop || 0) > THRESHOLD;
        if (shouldShow === visible) return;
        visible = shouldShow;
        btn.classList.toggle('is-visible', visible);
        // A hidden button must not sit in the tab order between the last
        // piece of content and the footer/header controls that follow it.
        if (visible) btn.removeAttribute('tabindex');
        else btn.setAttribute('tabindex', '-1');
      }

      window.addEventListener('scroll', function () {
        if (ticking) return;
        ticking = true;
        (window.requestAnimationFrame || window.setTimeout)(sync);
      }, { passive: true });

      btn.addEventListener('click', function () {
        try {
          window.scrollTo({ top: 0, left: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        } catch (e) {
          window.scrollTo(0, 0); // older engines: no options object support
        }
      });

      sync();
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mount);
    } else {
      mount();
    }
  } catch (e) { /* never throw into the SPA */ }
})();
