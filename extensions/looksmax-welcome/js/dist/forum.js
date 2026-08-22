/*
 * First-run identity — the client half.
 *
 * TWO jobs, one file, because they share the same "a new account just showed
 * up" moment:
 *
 *   1. DECORATE core's own SignUpModal / LogInModal with icons, hints and a
 *      password-strength meter. Additive DOM only — see decorateAuthModals()
 *      for exactly what it touches and how defensively it finds the fields it
 *      needs, because this file does not own SignUpModal or LogInModal and
 *      cannot import them (no build step ships this bundle — see the repo's
 *      house rules) to know their markup for certain.
 *   2. THE first-run overlay: a welcome moment plus the "why did you join"
 *      survey, built entirely from scratch (none of the "unknown DOM" risk
 *      above applies here — every node in the overlay is one this file
 *      creates itself), gated purely on server state so it needs no signal
 *      about "did a signup just happen" beyond "is there an unanswered
 *      survey row for the account now logged in".
 *
 * ── why gating never needs to detect "just signed up" ───────────────────────
 * Flarum reloads the page after a successful registration, so the very next
 * paint IS a normal authenticated page load like any other. Rather than hook
 * SignUpModal's success callback (which this file cannot reach without
 * importing a component it does not own), the overlay opens purely from
 * `app.session.user`'s own `lmxJoinSurvey` attribute (see extend.php's
 * UserSerializer mutator): status 'pending' means "an unanswered row exists
 * for this account" and that is ALWAYS true exactly once, right after
 * registration, and never again once answered or skipped. See
 * migrations/2026_08_14_150000_create_lmx_welcome_survey.php for why that
 * row only exists for genuinely new accounts.
 *
 * Ships as its own <script> (src/InjectScript.php) rather than through
 * Extend\Frontend::js(), the house pattern on this install: Flarum
 * concatenates every extension's registered forum.js into one bundle, so a
 * throw anywhere in THIS file must never be able to take the hover card, the
 * composer, or anything else down with it. Every entry point below is
 * try/catch-wrapped for exactly that reason.
 */
(function () {
  'use strict';

  // ------------------------------------------------------------------ utils

  function reg() {
    return (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  }

  function app() {
    var r = reg();
    return (r['forum/app'] && r['forum/app'].default) || window.app || null;
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function icon(name, cls) {
    // looksmax-icons registers <iconify-icon>. Without it the tag is an
    // unknown inline element with no box, which degrades to "no glyph"
    // rather than to a broken layout.
    var e = document.createElement('iconify-icon');
    e.setAttribute('icon', name);
    e.setAttribute('inline', '');
    e.setAttribute('aria-hidden', 'true');
    if (cls) e.className = cls;
    return e;
  }

  function payload() {
    if (payload._v !== undefined) return payload._v;
    payload._v = null;
    try {
      var node = document.getElementById('flarum-json-payload');
      if (node) payload._v = JSON.parse(node.textContent);
    } catch (e) {}
    return payload._v;
  }

  function csrf() {
    var p = payload();
    return (p && p.session && p.session.csrfToken) || null;
  }

  /**
   * A translation, with the SPANISH source string as the fallback.
   *
   * Spanish is this forum's default locale and therefore the source
   * language: locale/es.yml holds the strings an author wrote and
   * locale/en.yml holds their translation. Fallbacks below are Spanish for
   * that reason, matching every other lane's forum.js on this install (see
   * looksmax-cosmetics/js/dist/forum.js `t()`).
   */
  function t(key, params, fallback) {
    if (window.lmxI18n && window.lmxI18n.t) return window.lmxI18n.t(key, params || {}, fallback);
    var a = app();
    if (a && a.translator) {
      var out = a.translator.trans(key, params || {});
      if (typeof out === 'string' && out !== key) return out;
    }
    return fallback;
  }

  // --------------------------------------------------------- server state

  function forumCfg() {
    var a = app();
    try {
      return (a && a.forum && a.forum.attribute('lmxWelcome')) || null;
    } catch (e) {
      return null;
    }
  }

  function meUser() {
    var a = app();
    return (a && a.session && a.session.user) || null;
  }

  function surveyState() {
    var me = meUser();
    try {
      return (me && me.data && me.data.attributes && me.data.attributes.lmxJoinSurvey) || null;
    } catch (e) {
      return null;
    }
  }

  // ============================================================ the survey

  var S = {
    open: false,
    busy: false,
    stage: 'survey', // 'survey' | 'done'
    selected: Object.create(null),
    catalog: null, // { enabled, catalog: [...] }
  };

  function selectedKeys() {
    var out = [];
    for (var k in S.selected) if (S.selected[k]) out.push(k);
    return out;
  }

  function splitCatalog(list) {
    var sections = [];
    var intents = [];
    for (var i = 0; i < list.length; i++) {
      (list[i].category === 'section' ? sections : intents).push(list[i]);
    }
    return { sections: sections, intents: intents };
  }

  // ---------------------------------------------------------------- chips

  function chip(item, onChange) {
    var b = el('button', 'LmxWelcomeChip');
    b.type = 'button';
    b.setAttribute('aria-pressed', 'false');
    if (item.color) b.style.setProperty('--chip-color', item.color);

    b.appendChild(icon(item.icon, 'LmxWelcomeChip-icon'));
    b.appendChild(el('span', 'LmxWelcomeChip-label', t(item.labelKey, null, item.key)));
    b.appendChild(el('span', 'LmxWelcomeChip-check'));

    b.addEventListener('click', function () {
      var picked = !S.selected[item.key];
      if (picked) S.selected[item.key] = true;
      else delete S.selected[item.key];

      b.classList.toggle('is-picked', picked);
      b.setAttribute('aria-pressed', picked ? 'true' : 'false');
      onChange();
    });

    return b;
  }

  function group(title, items, onChange) {
    var wrap = el('div', 'LmxWelcomeGroup');
    wrap.appendChild(el('h3', 'LmxWelcomeGroup-title', title));
    var grid = el('div', 'LmxWelcomeGrid');
    for (var i = 0; i < items.length; i++) grid.appendChild(chip(items[i], onChange));
    wrap.appendChild(grid);
    return wrap;
  }

  // ------------------------------------------------------------- the card

  function surveyView() {
    var frag = el('div', 'LmxWelcome-body');

    var closeBtn = el('button', 'LmxWelcome-close');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', t('local-looksmax-welcome.forum.survey.skip', null, 'Ahora no'));
    closeBtn.appendChild(icon('ph:x-bold'));
    closeBtn.addEventListener('click', skip);
    frag.appendChild(closeBtn);

    var me = meUser();
    var name = '';
    try {
      name = me && (me.displayName ? me.displayName() : me.username());
    } catch (e) {}

    var head = el('div', 'LmxWelcome-head');
    head.appendChild(el('div', 'LmxWelcome-eyebrow', t('local-looksmax-welcome.forum.survey.eyebrow', null, 'Bienvenido a Looksmax')));
    head.appendChild(
      el(
        'h2',
        'LmxWelcome-title',
        name
          ? t('local-looksmax-welcome.forum.survey.title_named', { name: name }, '¡Qué bueno tenerte aquí, ' + name + '!')
          : t('local-looksmax-welcome.forum.survey.title', null, '¿Por qué te uniste?')
      )
    );
    head.appendChild(
      el(
        'p',
        'LmxWelcome-sub',
        t(
          'local-looksmax-welcome.forum.survey.sub',
          null,
          'Cuéntanos qué te trae por aquí. Es opcional y toma diez segundos — nos ayuda a mostrarte lo que de verdad te interesa.'
        )
      )
    );
    frag.appendChild(head);

    var idx = splitCatalog((S.catalog && S.catalog.catalog) || []);
    frag.appendChild(group(t('local-looksmax-welcome.forum.survey.group.intent', null, '¿Qué te trae por aquí?'), idx.intents, refreshFooter));
    frag.appendChild(group(t('local-looksmax-welcome.forum.survey.group.section', null, '¿Qué secciones te interesan?'), idx.sections, refreshFooter));

    var error = el('div', 'LmxWelcome-error');
    frag.appendChild(error);

    var footer = el('div', 'LmxWelcome-footer');
    footer.appendChild(el('span', 'LmxWelcome-count'));

    var actions = el('div', 'LmxWelcome-actions');
    var skipBtn = el('button', 'Button LmxWelcome-skip');
    skipBtn.type = 'button';
    skipBtn.textContent = t('local-looksmax-welcome.forum.survey.skip', null, 'Ahora no');
    skipBtn.addEventListener('click', skip);
    actions.appendChild(skipBtn);

    var saveBtn = el('button', 'Button Button--primary LmxWelcome-save');
    saveBtn.type = 'button';
    saveBtn.addEventListener('click', submit);
    actions.appendChild(saveBtn);

    footer.appendChild(actions);
    frag.appendChild(footer);

    // Initial paint of the footer/button labels, same code path a later
    // selection change uses.
    refreshFooter();

    return frag;
  }

  function doneView() {
    var frag = el('div', 'LmxWelcome-body LmxWelcome-done');
    var burst = el('div', 'LmxWelcome-burst');
    burst.appendChild(icon('ph:check-circle-fill', 'LmxWelcome-doneIcon'));
    frag.appendChild(burst);
    frag.appendChild(el('h2', 'LmxWelcome-title', t('local-looksmax-welcome.forum.survey.done_title', null, '¡Listo!')));
    frag.appendChild(
      el(
        'p',
        'LmxWelcome-sub',
        t('local-looksmax-welcome.forum.survey.done_sub', null, 'Vamos a usar esto para mostrarte contenido más relevante.')
      )
    );
    return frag;
  }

  /** Updates the footer WITHOUT rebuilding the chips, so a click never re-triggers their entrance animation. */
  function refreshFooter() {
    var host = document.getElementById('lmx-welcome-host');
    if (!host) return;

    var n = selectedKeys().length;

    var countEl = host.querySelector('.LmxWelcome-count');
    if (countEl) {
      countEl.textContent =
        n > 0
          ? t('local-looksmax-welcome.forum.survey.count', { n: n, count: n }, n + ' seleccionadas')
          : t('local-looksmax-welcome.forum.survey.count_zero', null, 'Elige las que quieras');
    }

    var saveBtn = host.querySelector('.LmxWelcome-save');
    if (saveBtn) {
      saveBtn.disabled = S.busy || n === 0;
      saveBtn.textContent = S.busy
        ? t('local-looksmax-welcome.forum.survey.saving', null, 'Guardando…')
        : t('local-looksmax-welcome.forum.survey.save', null, 'Guardar');
    }

    var skipBtn = host.querySelector('.LmxWelcome-skip');
    if (skipBtn) skipBtn.disabled = S.busy;
  }

  function showError(msg) {
    var host = document.getElementById('lmx-welcome-host');
    var e = host && host.querySelector('.LmxWelcome-error');
    if (!e) return;
    e.textContent = msg;
    e.classList.add('is-shown');
  }

  function hideError() {
    var host = document.getElementById('lmx-welcome-host');
    var e = host && host.querySelector('.LmxWelcome-error');
    if (e) e.classList.remove('is-shown');
  }

  // -------------------------------------------------------------- network

  function headers() {
    var h = { 'Content-Type': 'application/json' };
    var tok = csrf();
    if (tok) h['X-CSRF-Token'] = tok;
    return h;
  }

  function markLocalState(status, answers) {
    var me = meUser();
    try {
      if (me && me.data && me.data.attributes) {
        me.data.attributes.lmxJoinSurvey = { status: status, answers: answers, respondedAt: new Date().toISOString() };
      }
    } catch (e) {}
  }

  function submit() {
    if (S.busy) return;
    S.busy = true;
    hideError();
    refreshFooter();

    var keys = selectedKeys();

    fetch('/api/lmx-welcome/survey', {
      method: 'POST',
      headers: headers(),
      credentials: 'same-origin',
      body: JSON.stringify({ answers: keys }),
    })
      .then(function (r) {
        return r.json().then(function (body) {
          return { ok: r.ok, body: body };
        });
      })
      .then(function (res) {
        S.busy = false;
        if (!res.ok) {
          refreshFooter();
          showError(t('local-looksmax-welcome.forum.survey.error', null, 'No se pudo guardar. Intenta de nuevo.'));
          return;
        }
        markLocalState('completed', (res.body && res.body.answers) || keys);
        S.stage = 'done';
        renderCard();
        setTimeout(closeAnimated, 1600);
      })
      .catch(function () {
        S.busy = false;
        refreshFooter();
        showError(t('local-looksmax-welcome.forum.survey.error', null, 'No se pudo guardar. Intenta de nuevo.'));
      });
  }

  /**
   * Closes immediately (skipping is never a wait) and persists in the
   * background. If the network call fails, the server-side row is simply
   * left 'pending' and the overlay may show again next load — the safe
   * failure direction, matching SignatureController's own "empty is a valid
   * value" posture: the worst case of a failed skip is "asked again", never
   * "stuck open".
   */
  function skip() {
    if (S.skipping) return;
    S.skipping = true;

    markLocalState('skipped', []);
    closeAnimated();

    fetch('/api/lmx-welcome/survey/skip', {
      method: 'POST',
      headers: headers(),
      credentials: 'same-origin',
      body: '{}',
    }).catch(function () {});
  }

  // -------------------------------------------------------------- overlay

  function onKeydown(e) {
    if (e.key === 'Escape' || e.keyCode === 27) skip();
  }

  function renderCard() {
    var host = document.getElementById('lmx-welcome-host');
    if (!host) return;

    var old = host.querySelector('.LmxWelcome-card');
    if (old && old.parentNode) old.parentNode.removeChild(old);

    var card = el('div', 'LmxWelcome-card');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.setAttribute('aria-label', t('local-looksmax-welcome.forum.survey.title', null, '¿Por qué te uniste?'));
    card.appendChild(S.stage === 'done' ? doneView() : surveyView());
    host.appendChild(card);
  }

  function open() {
    if (S.open) return;
    S.open = true;

    var host = el('div', 'LmxWelcome');
    host.id = 'lmx-welcome-host';
    var backdrop = el('div', 'LmxWelcome-backdrop');
    backdrop.addEventListener('click', skip);
    host.appendChild(backdrop);
    document.body.appendChild(host);

    renderCard();
    document.addEventListener('keydown', onKeydown);

    // A beat after mount, so the staged entrance is felt rather than
    // instantaneous — the CSS keyframes below key off `.is-live` /
    // `.is-entering` arriving on a fresh frame, not on the synchronous
    // insert. `.is-entering` is removed after the stagger finishes so a
    // LATER re-render (the survey -> done stage swap) never replays the
    // chips' entrance animation.
    requestAnimationFrame(function () {
      var h = document.getElementById('lmx-welcome-host');
      if (!h) return;
      h.classList.add('is-live');
      h.classList.add('is-entering');
      setTimeout(function () {
        var h2 = document.getElementById('lmx-welcome-host');
        if (h2) h2.classList.remove('is-entering');
      }, 650);
    });
  }

  function closeAnimated() {
    document.removeEventListener('keydown', onKeydown);
    S.open = false;

    var host = document.getElementById('lmx-welcome-host');
    if (!host) return;

    host.style.pointerEvents = 'none';
    host.classList.remove('is-live');

    setTimeout(function () {
      var h = document.getElementById('lmx-welcome-host');
      if (h && h.parentNode) h.parentNode.removeChild(h);
    }, 320);
  }

  // ------------------------------------------------------------ the gate

  var checked = false;

  function maybeOpenSurvey() {
    if (checked) return;

    var cfg = forumCfg();
    if (!cfg || !cfg.enabled || !cfg.catalog || !cfg.catalog.length) return;

    var state = surveyState();
    // No row, or already answered/skipped: never surface it. See the
    // migration's docblock for why "no row" correctly covers every imported
    // legacy account on this install.
    if (!state || state.status !== 'pending') return;

    checked = true;
    S.catalog = cfg;
    open();
  }

  // ======================================================== auth polish

  /**
   * Find the fields inside a signup/login modal without depending on markup
   * this file does not own. `type="password"` is the one attribute Flarum
   * MUST set correctly for password managers and browser autofill to work at
   * all, so it is the one match this code trusts completely; everything else
   * is tried in decreasing order of confidence and falls back to position.
   */
  function fieldsOf(root) {
    var inputs = root.querySelectorAll('input');
    var out = { username: null, email: null, password: null, identification: null };

    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      var name = (input.getAttribute('name') || '').toLowerCase();
      var type = (input.getAttribute('type') || 'text').toLowerCase();
      var ac = (input.getAttribute('autocomplete') || '').toLowerCase();

      if (type === 'password' && !out.password) {
        out.password = input;
        continue;
      }
      if (!out.email && (type === 'email' || name === 'email' || ac === 'email')) {
        out.email = input;
        continue;
      }
      if (!out.username && (name === 'username' || ac === 'username')) {
        out.username = input;
        continue;
      }
      if (!out.identification && (name === 'identification' || ac.indexOf('username') === 0)) {
        out.identification = input;
        continue;
      }
    }

    // Positional fallback: signup's username field is whichever remaining
    // text input was not already claimed.
    if (!out.username) {
      for (var j = 0; j < inputs.length; j++) {
        var cand = inputs[j];
        if (cand !== out.email && cand !== out.password) {
          out.username = cand;
          break;
        }
      }
    }
    // Login's single combined field is whichever text input is not password.
    if (!out.identification) {
      for (var k = 0; k < inputs.length; k++) {
        if (inputs[k] !== out.password) {
          out.identification = inputs[k];
          break;
        }
      }
    }

    return out;
  }

  /**
   * Wraps ONLY the <input> (not its surrounding .Form-group, whose internal
   * structure this file does not control) in a small relative-positioned
   * span, so the icon centers on the input regardless of whatever label or
   * error markup core renders around it.
   */
  function addFieldIcon(input, iconName) {
    if (!input || !input.parentNode) return;
    if (input.getAttribute('data-lmx-welcome-icon') === '1') return;
    input.setAttribute('data-lmx-welcome-icon', '1');

    var wrap = el('span', 'LmxAuthField');
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    wrap.appendChild(icon(iconName, 'LmxAuthField-icon'));
  }

  function addFieldHint(input, text) {
    if (!input) return;
    if (input.getAttribute('data-lmx-welcome-hint') === '1') return;
    input.setAttribute('data-lmx-welcome-hint', '1');

    // .LmxAuthField if addFieldIcon already ran, otherwise the input's own
    // parent — either way, the hint lands directly after whatever wraps the
    // field so it reads as "belongs to this input".
    var host = input.closest ? input.closest('.LmxAuthField') : null;
    var anchor = host || input;
    if (!anchor.parentNode) return;

    var hint = el('div', 'LmxAuthField-hint', text);
    anchor.parentNode.insertBefore(hint, anchor.nextSibling);
  }

  function passwordScore(v) {
    if (!v) return 0;
    var score = 0;
    if (v.length >= 8) score++;
    if (v.length >= 12) score++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    return Math.min(4, score);
  }

  function addPasswordMeter(input) {
    if (!input || !input.parentNode) return;
    if (input.getAttribute('data-lmx-welcome-meter') === '1') return;
    input.setAttribute('data-lmx-welcome-meter', '1');

    var host = input.closest ? input.closest('.LmxAuthField') : null;
    var anchor = host || input;
    if (!anchor.parentNode) return;

    var meter = el('div', 'LmxPwMeter');
    meter.setAttribute('data-score', '0');
    meter.appendChild(el('div', 'LmxPwMeter-bar'));
    var label = el('div', 'LmxPwMeter-label', t('local-looksmax-welcome.forum.auth.pw.empty', null, 'Mínimo 8 caracteres'));
    meter.appendChild(label);
    anchor.parentNode.insertBefore(meter, anchor.nextSibling);

    var labels = [
      t('local-looksmax-welcome.forum.auth.pw.empty', null, 'Mínimo 8 caracteres'),
      t('local-looksmax-welcome.forum.auth.pw.weak', null, 'Débil'),
      t('local-looksmax-welcome.forum.auth.pw.fair', null, 'Aceptable'),
      t('local-looksmax-welcome.forum.auth.pw.good', null, 'Buena'),
      t('local-looksmax-welcome.forum.auth.pw.strong', null, 'Fuerte'),
    ];

    input.addEventListener('input', function () {
      var v = input.value || '';
      var score = v ? passwordScore(v) : 0;
      meter.setAttribute('data-score', String(score));
      label.textContent = v ? labels[score] : labels[0];
    });
  }

  function decorateAuthModals() {
    var signup = document.querySelector('.SignUpModal');
    if (signup && signup.getAttribute('data-lmx-welcome-auth') !== '1') {
      signup.setAttribute('data-lmx-welcome-auth', '1');
      var sf = fieldsOf(signup);
      addFieldIcon(sf.username, 'ph:user-circle-fill');
      addFieldHint(sf.username, t('local-looksmax-welcome.forum.auth.username_hint', null, 'Así te verán los demás. Puedes cambiarlo luego.'));
      addFieldIcon(sf.email, 'ph:envelope-simple-fill');
      addFieldHint(sf.email, t('local-looksmax-welcome.forum.auth.email_hint', null, 'Nunca se muestra públicamente.'));
      addFieldIcon(sf.password, 'ph:lock-key-fill');
      addPasswordMeter(sf.password);
    }

    var login = document.querySelector('.LogInModal');
    if (login && login.getAttribute('data-lmx-welcome-auth') !== '1') {
      login.setAttribute('data-lmx-welcome-auth', '1');
      var lf = fieldsOf(login);
      addFieldIcon(lf.identification, 'ph:user-circle-fill');
      addFieldIcon(lf.password, 'ph:lock-key-fill');
    }
  }

  // ------------------------------------------------------------ lifecycle

  function tick() {
    try {
      decorateAuthModals();
    } catch (e) {}
    try {
      maybeOpenSurvey();
    } catch (e) {}
  }

  function boot() {
    tick();

    if ('MutationObserver' in window) {
      var scheduled = false;
      var mo = new MutationObserver(function () {
        if (scheduled) return;
        scheduled = true;
        // Coalesce a Mithril redraw's worth of mutations into one pass.
        requestAnimationFrame(function () {
          scheduled = false;
          tick();
        });
      });
      mo.observe(document.body, { childList: true, subtree: true });
    }

    // The SPA can swap routes without touching <body>'s children in a way
    // the observer notices, and the session user fills in after the first
    // paint — same fallback shape as looksmax-cosmetics' forum.js.
    var n = 0;
    var iv = setInterval(function () {
      tick();
      if (++n > 20) clearInterval(iv);
    }, 400);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
