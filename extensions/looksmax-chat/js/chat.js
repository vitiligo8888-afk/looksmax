/*
 * Looksmax shoutbox client.
 *
 * Injected as its own <script> element by RenderChat, for two reasons. Flarum
 * concatenates every extension's JS into one bundle, so a top-level throw here
 * would take out every extension after it; and the box lives inside
 * `.LmxIndex-side`, which looksmax-index builds as raw DOM outside Mithril and
 * re-creates whenever a redraw replaces the subtree it hangs off. A Mithril
 * component cannot own a node another extension keeps replacing.
 *
 * The shape that follows from that:
 *
 *   - the card is built ONCE and kept in a variable. Re-mounting moves the same
 *     node back into the sidebar, so the log, the scroll position and the
 *     cursor survive an SPA route change with no reload and no re-fetch.
 *   - exactly one poll is ever in flight, and the cursor only advances to a row
 *     that was actually rendered. The version this replaces started two
 *     overlapping requests at cursor 0 on mount, which is why every message
 *     appeared twice.
 *   - every message id that has been rendered is remembered, so even a server
 *     that re-sends a row cannot duplicate it.
 *   - failures back off, surface as a visible state, and recover with one
 *     request that carries every missed message — never a burst.
 *
 * `window.__lmxChat` exposes the cursor, the DOM count and a ring buffer of the
 * last polls. That is deliberate: the duplicate above was diagnosed from the
 * DOM only, and a cursor you cannot read is a cursor you cannot test.
 */
(function () {
  'use strict';

  var API = '/api/chat';
  var POLL_ACTIVE = 3000;     // visible tab
  var POLL_HIDDEN = 15000;    // backgrounded tab: still current, 5x cheaper
  var POLL_MIN_GAP = 900;     // no two polls closer than this, whatever asks
  var BACKOFF_START = 3000;
  // 15s, not 30. Backoff protects the server from a client that cannot reach
  // it; it must not make a recovered network feel broken. Measured: with a 30s
  // ceiling the box sat on "Offline — reconnecting" for 24 seconds after the
  // network came back, which reads as a dead feature.
  var BACKOFF_MAX = 15000;
  var GROUP_WINDOW = 5 * 60 * 1000;   // same author inside this = one block
  var KEEP_NODES = 150;
  var TIME_TICK = 20000;

  var boot = window.__lmxChatBoot || null;

  var S = {
    since: 0,
    seen: Object.create(null),
    online: null,
    members: [],
    guests: 0,
    canPost: false,
    canModerate: false,
    you: null,
    youId: null,
    youAvatar: null,
    maxLength: 400,
    skew: 0,              // serverTime - Date.now()
    inFlight: false,
    lastPoll: 0,
    timer: null,
    backoff: 0,
    failures: 0,
    pinned: true,
    unread: 0,
    booted: false
  };

  var card = null, logWrap = null, log = null, empty = null, form = null,
      input = null, sendBtn = null, remain = null, alertBox = null,
      jump = null, guestBox = null, onlineEl = null, dotEl = null, liveEl = null;
  var pendingSeq = 0;
  var timeTimer = null;

  var probe = { polls: [], sends: [] };

  // -------------------------------------------------------------- utilities

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function icon(name) {
    var i = document.createElement('i');
    i.className = name;
    return i;
  }

  /**
   * A translated string, or the English one.
   *
   * The i18n lane put locale/en.yml and locale/es.yml in this extension and
   * exposes `window.lmxI18n.t(key, params, fallback)`, which records a miss so
   * its gate can fail on an untranslated key instead of shipping `a.b.c` to a
   * reader. Every literal below goes through here with the English text as the
   * fallback, so a half-deployed locale file degrades to readable English
   * rather than to key soup.
   */
  function t(key, params, fallback) {
    var i18n = window.lmxI18n;
    if (i18n && i18n.t) {
      var out = i18n.t('local-looksmax-chat.forum.' + key, params || {}, fallback);
      if (typeof out === 'string') return out;
    }
    return fallback;
  }

  /** A number in the reader's locale: 1,024 in English, 1.024 in Spanish. */
  function num(n) {
    var i18n = window.lmxI18n;
    return i18n && i18n.num ? i18n.num(n) : String(n);
  }

  /** Per-browser id, so two browsers count as two guests and ten tabs do not. */
  function cid() {
    try {
      var v = localStorage.getItem('lmx-chat-cid');
      if (!v) {
        v = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
        localStorage.setItem('lmx-chat-cid', v);
      }
      return v;
    } catch (e) {
      // private mode / storage disabled: fall back to a per-page id
      if (!S._cid) S._cid = Math.random().toString(36).slice(2);
      return S._cid;
    }
  }

  /**
   * Flarum's CSRF token.
   *
   * The app object has it once the SPA has booted; the embedded payload has it
   * before that. A POST without it is answered 400 csrf_token_mismatch, which
   * is exactly what a bare fetch() got, and why nothing could be posted.
   */
  function csrf() {
    var app = window.flarum && window.flarum.core && window.flarum.core.app;
    if (app && app.session && app.session.csrfToken) return app.session.csrfToken;
    try {
      var node = document.getElementById('flarum-json');
      if (node) {
        var j = JSON.parse(node.textContent);
        if (j && j.session && j.session.csrfToken) return j.session.csrfToken;
      }
    } catch (e) {}
    return '';
  }

  function now() { return Date.now() + S.skew; }

  function rel(iso) {
    // not `t` — that is the translator, and shadowing it here is a trap
    var at = Date.parse(iso);
    if (!at) return '';

    // "hace 2 minutos", not "2m". The compact ladder below is four English
    // tokens rendered on every row of the busiest surface on the forum, which
    // is exactly the "3 hours ago beside Spanish text" defect; Intl does this
    // properly and lmxI18n.rel() is already the house wrapper for it.
    //
    // The instant is skew-corrected BEFORE it is handed over. These are server
    // timestamps and the client clock can be minutes out — S.skew is
    // serverTime - Date.now(), which is what now() adds — while lmxI18n.rel()
    // measures against the raw Date.now(). Shifting the timestamp by the same
    // skew makes the two agree.
    var i18n = window.lmxI18n;
    if (i18n && i18n.rel) return i18n.rel(new Date(at + S.skew));

    var d = Math.max(0, now() - at) / 1000;
    if (d < 45) return 'now';
    if (d < 3600) return Math.round(d / 60) + 'm';
    if (d < 86400) return Math.round(d / 3600) + 'h';
    return Math.round(d / 86400) + 'd';
  }

  /** The long form, for the title attribute — localised where possible. */
  function full(iso) {
    var i18n = window.lmxI18n;
    if (i18n && i18n.rel && i18n.date) {
      var r = i18n.rel(iso);
      var d = i18n.date(iso, { dateStyle: 'medium', timeStyle: 'short' });
      if (r && d) return r + ' — ' + d;
      if (d) return d;
    }
    var when = new Date(iso);
    return isNaN(when) ? '' : when.toLocaleString();
  }

  /** Flarum's own avatar palette, so a fallback initial does not look foreign. */
  var AV_COLORS = ['#e8c07d', '#7aa2f7', '#9ece6a', '#f7768e', '#bb9af7', '#7dcfff', '#e0af68', '#73daca'];
  function avatarColor(name) {
    var h = 0;
    for (var i = 0; i < (name || '').length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
    return AV_COLORS[h % AV_COLORS.length];
  }

  // ------------------------------------------------------------- text render

  var EMOJI = {
    ':)': '🙂', ':-)': '🙂', ':(': '🙁', ':-(': '🙁', ':D': '😀', ':P': '😛',
    ';)': '😉', ':O': '😮', ':/': '😕', '<3': '❤️', ':fire:': '🔥',
    ':skull:': '💀', ':100:': '💯', ':eyes:': '👀', ':sob:': '😭', ':cry:': '😢',
    ':heart:': '❤️', ':thumbsup:': '👍', ':thumbsdown:': '👎', ':ok:': '👌',
    ':clown:': '🤡', ':brain:': '🧠', ':muscle:': '💪', ':moon:': '🌙',
    ':smile:': '😄', ':laugh:': '😂', ':wink:': '😉', ':cool:': '😎',
    ':think:': '🤔', ':pray:': '🙏', ':wave:': '👋', ':star:': '⭐',
    ':check:': '✅', ':x:': '❌', ':rocket:': '🚀', ':gem:': '💎'
  };
  // The token must stand alone. Without the boundaries, `:/` inside `https://`
  // matched and every pasted link rendered as "https😕/example.com" — measured,
  // not hypothetical.
  var EMOJI_RE = /(^|\s)(:[a-z0-9_+-]+:|:-?\)|:-?\(|:D|:P|;\)|:O|:\/|<3)(?=\s|$)/g;
  var URL_RE = /\bhttps?:\/\/[^\s<>"')\]]+/gi;
  var MENTION_RE = /@([A-Za-z0-9._-]{2,30})/g;

  function emojify(text) {
    return text.replace(EMOJI_RE, function (m, pre, tok) {
      var key = EMOJI[tok] !== undefined ? tok : tok.toLowerCase();
      return pre + (EMOJI[key] !== undefined ? EMOJI[key] : tok);
    });
  }

  /**
   * Build the message body as nodes.
   *
   * Never innerHTML. The body is whatever somebody typed, it is stored raw, and
   * one innerHTML here is a stored XSS on the front page of the forum.
   */
  function renderText(host, body) {
    var text = String(body == null ? '' : body);

    // Links are cut out FIRST and never touched again: a URL contains an @ that
    // is not a mention and a `:/` that is not a smiley.
    var parts = [];
    var last = 0, m;
    URL_RE.lastIndex = 0;
    while ((m = URL_RE.exec(text)) !== null) {
      if (m.index > last) parts.push({ t: 'text', v: text.slice(last, m.index) });
      parts.push({ t: 'url', v: m[0] });
      last = m.index + m[0].length;
    }
    if (last < text.length) parts.push({ t: 'text', v: text.slice(last) });

    var plain = '';
    parts.forEach(function (p) {
      if (p.t === 'url') {
        var a = el('a', 'LmxChat-link');
        a.href = p.v;
        a.target = '_blank';
        a.rel = 'noopener nofollow ugc';
        var pretty = p.v.replace(/^https?:\/\//, '').replace(/\/$/, '');
        a.textContent = pretty.length > 42 ? pretty.slice(0, 40) + '…' : pretty;
        a.title = p.v;
        host.appendChild(a);
        return;
      }

      var chunk = emojify(p.v), at = 0, mm;
      plain += chunk;
      MENTION_RE.lastIndex = 0;
      while ((mm = MENTION_RE.exec(chunk)) !== null) {
        if (mm.index > at) host.appendChild(document.createTextNode(chunk.slice(at, mm.index)));
        var link = el('a', 'LmxChat-mention', '@' + mm[1]);
        link.href = '/u/' + encodeURIComponent(mm[1]);
        host.appendChild(link);
        at = mm.index + mm[0].length;
      }
      if (at < chunk.length) host.appendChild(document.createTextNode(chunk.slice(at)));
    });

    // an all-emoji line reads as a reaction, so let it be one
    var stripped = parts.some(function (p) { return p.t === 'url'; }) ? '' : plain.replace(/\s/g, '');
    if (stripped && /^(?:\p{Extended_Pictographic}|️|‍)+$/u.test(stripped) && stripped.length <= 12) {
      host.classList.add('is-jumbo');
    }
  }

  // ------------------------------------------------------------------ render

  function avatarNode(msg) {
    var a = el('a', 'LmxChat-avatar');
    a.href = '/u/' + encodeURIComponent(msg.username || '');
    a.setAttribute('tabindex', '-1');
    a.setAttribute('aria-hidden', 'true');

    if (msg.avatarUrl) {
      var img = el('img', 'Avatar');
      img.src = msg.avatarUrl;
      img.alt = '';
      img.loading = 'lazy';
      // a broken avatar file must degrade to the initial, not to a torn icon
      img.addEventListener('error', function () {
        if (img.parentNode) img.parentNode.replaceChild(initial(msg.username), img);
      });
      a.appendChild(img);
    } else {
      a.appendChild(initial(msg.username));
    }
    return a;
  }

  function initial(username) {
    var s = el('span', 'Avatar', (username || '?').charAt(0).toUpperCase());
    s.style.background = avatarColor(username);
    return s;
  }

  function messageNode(msg, pending) {
    var li = el('li', 'LmxChat-msg');
    if (msg.id) li.setAttribute('data-mid', String(msg.id));
    if (msg.nonce) li.setAttribute('data-nonce', msg.nonce);
    if (msg.userId != null) li.setAttribute('data-uid', String(msg.userId));
    li.setAttribute('data-ts', msg.createdAt || '');
    if (pending) li.classList.add('is-pending');
    if (S.youId != null && msg.userId === S.youId) li.classList.add('is-own');

    li.appendChild(avatarNode(msg));

    var bubble = el('div', 'LmxChat-bubble');
    var meta = el('div', 'LmxChat-meta');

    var who = el('a', 'LmxChat-who', msg.username);
    who.href = '/u/' + encodeURIComponent(msg.username || '');
    // looksmax-ranks decorates this node; the attribute is what keeps it
    // reading the account name rather than whatever the text says
    who.setAttribute('data-lmx-user', msg.username || '');
    meta.appendChild(who);

    var time = el('time', 'LmxChat-time',
      pending ? t('sending', null, 'sending…') : rel(msg.createdAt));
    if (msg.createdAt) {
      time.setAttribute('datetime', msg.createdAt);
      time.title = full(msg.createdAt);
    }
    meta.appendChild(time);

    var del = el('button', 'LmxChat-del');
    del.type = 'button';
    del.title = t('delete', null, 'Delete message');
    del.setAttribute('aria-label', del.title);
    del.appendChild(icon('fas fa-xmark'));
    del.addEventListener('click', function () { remove(li); });
    meta.appendChild(del);

    bubble.appendChild(meta);

    var text = el('div', 'LmxChat-text');
    renderText(text, msg.body);
    bubble.appendChild(text);

    li.appendChild(bubble);
    applyOwnership(li, msg);
    return li;
  }

  /** Whether the delete affordance applies to this row, for this viewer. */
  function applyOwnership(li, msg) {
    var mine = S.youId != null && msg.userId === S.youId;
    li.classList.toggle('can-delete', !!(msg.id && (mine || S.canModerate)));
  }

  /** Collapse the avatar and name when the same person speaks twice in a row. */
  function regroup() {
    var prev = null;
    for (var i = 0; i < log.children.length; i++) {
      var n = log.children[i];
      var uid = n.getAttribute('data-uid');
      var ts = Date.parse(n.getAttribute('data-ts')) || 0;
      var cont = !!prev &&
        prev.getAttribute('data-uid') === uid && uid !== null &&
        Math.abs(ts - (Date.parse(prev.getAttribute('data-ts')) || 0)) < GROUP_WINDOW;
      n.classList.toggle('is-cont', cont);
      prev = n;
    }
  }

  function atBottom() {
    return log.scrollHeight - log.scrollTop - log.clientHeight < 28;
  }

  function toBottom(smooth) {
    try {
      log.scrollTo({ top: log.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    } catch (e) {
      log.scrollTop = log.scrollHeight;
    }
    S.pinned = true;
    S.unread = 0;
    paintJump();
    paintMask();
  }

  function paintJump() {
    if (!jump) return;
    if (S.unread > 0 && !S.pinned) {
      jump.hidden = false;
      jump.firstChild.textContent = t('unread', { count: S.unread }, S.unread + ' new');
    } else {
      jump.hidden = true;
    }
  }

  function paintEmpty() {
    empty.hidden = log.children.length > 0;
    paintMask();
  }

  /** The top fade only means something when something is scrolled past. */
  function paintMask() {
    log.classList.toggle('is-attop', log.scrollTop < 4);
  }

  function paintTimes() {
    var nodes = log.querySelectorAll('.LmxChat-msg:not(.is-pending) .LmxChat-time');
    for (var i = 0; i < nodes.length; i++) {
      var ts = nodes[i].getAttribute('datetime');
      if (ts) nodes[i].textContent = rel(ts);
    }
  }

  // ------------------------------------------------------------------- state

  function resetLog() {
    log.innerHTML = '';
    S.seen = Object.create(null);
    S.since = 0;
    S.unread = 0;
  }

  /**
   * Fold a payload into the view.
   *
   * The cursor moves to `cursor` from the server, which is the id of a row the
   * server actually put in this response — never MAX(id), which would skip
   * anything a page limit cut off.
   */
  function apply(data) {
    if (!data) return;

    if (data.serverTime) {
      var stamp = Date.parse(data.serverTime);
      if (stamp) S.skew = stamp - Date.now();
    }

    if (data.truncated) {
      // further behind than one response can carry: rebuild rather than splice
      // a hole into the log
      resetLog();
    }

    var wasPinned = S.pinned || atBottom();
    var added = 0;

    (data.messages || []).forEach(function (m) {
      if (!m || m.id == null) return;
      if (S.seen[m.id]) return;                  // belt: never render an id twice
      S.seen[m.id] = 1;
      log.appendChild(messageNode(m, false));
      added++;
      if (m.id > S.since) S.since = m.id;        // braces: cursor only moves forward
      if (!wasPinned && !(S.youId != null && m.userId === S.youId)) S.unread++;
    });

    if (typeof data.cursor === 'number' && data.cursor > S.since) S.since = data.cursor;

    (data.deleted || []).forEach(function (id) {
      var n = log.querySelector('.LmxChat-msg[data-mid="' + id + '"]');
      if (n) n.remove();
    });

    while (log.children.length > KEEP_NODES) log.removeChild(log.firstChild);

    S.canPost = !!data.canPost;
    S.canModerate = !!data.canModerate;
    S.you = data.you;
    S.youId = data.youId == null ? null : data.youId;
    if (data.maxLength) S.maxLength = data.maxLength;

    if (added || data.deleted) regroup();
    paintPresence(data);
    paintPost();
    paintEmpty();

    if (added) {
      if (wasPinned) toBottom(S.booted);
      else paintJump();
    }
    S.booted = true;
  }

  function paintPresence(data) {
    if (data.online == null) return;
    var changed = data.online !== S.online;
    S.online = data.online;
    S.members = data.members || [];
    S.guests = data.guests || 0;

    onlineEl.textContent = num(S.online);
    dotEl.classList.toggle('is-idle', S.online === 0);
    if (changed) {
      onlineEl.classList.remove('is-bumped');
      // reflow, so the animation restarts when the number changes again
      void onlineEl.offsetWidth;
      onlineEl.classList.add('is-bumped');
    }

    var i18n = window.lmxI18n;
    var some = S.members.slice(0, 12);
    var names = i18n && i18n.list ? i18n.list(some) : some.join(', ');
    var guests = S.guests
      ? t('presence.guests', { count: S.guests }, S.guests + ' guest' + (S.guests === 1 ? '' : 's'))
      : '';
    liveEl.title = S.online === 0
      ? t('presence.nobody', null, 'Nobody here')
      : names + (guests ? (names ? ' + ' : '') + guests : '');
  }

  function paintPost() {
    form.hidden = !S.canPost;
    guestBox.hidden = S.canPost;
    input.setAttribute('maxlength', String(S.maxLength));
    var mine = log.querySelectorAll('.LmxChat-msg');
    for (var i = 0; i < mine.length; i++) {
      var uid = mine[i].getAttribute('data-uid');
      var own = S.youId != null && uid === String(S.youId);
      mine[i].classList.toggle('is-own', own);
      mine[i].classList.toggle('can-delete', !!(mine[i].getAttribute('data-mid') && (own || S.canModerate)));
    }
  }

  function paintRemain() {
    var left = S.maxLength - input.value.length;
    remain.hidden = left > 60;
    // the counter floats over the right end of the input; the input has to give
    // that space back or the text runs underneath it (invisible to a DOM
    // assertion, obvious in the screenshot)
    form.classList.toggle('has-count', !remain.hidden);
    remain.textContent = num(left);
    remain.classList.toggle('is-low', left <= 20);
    sendBtn.disabled = input.value.trim().length === 0;
  }

  // ------------------------------------------------------------------ alerts

  var alertTimer = null;
  function setAlert(kind, text, retryLabel) {
    clearTimeout(alertTimer);
    alertBox.hidden = false;
    alertBox.className = 'LmxChat-alert is-' + kind;
    alertBox.innerHTML = '';
    alertBox.appendChild(icon(kind === 'error' ? 'fas fa-plug-circle-exclamation' : 'fas fa-circle-info'));
    alertBox.appendChild(el('span', 'LmxChat-alert-text', text));
    if (retryLabel) {
      var b = el('button', 'LmxChat-retry', retryLabel);
      b.type = 'button';
      b.addEventListener('click', function () { S.backoff = 0; S.failures = 0; poll('retry'); });
      alertBox.appendChild(b);
    }
    card.classList.toggle('is-offline', kind === 'error');
  }

  function hideAlert() {
    clearTimeout(alertTimer);
    alertBox.hidden = true;
    alertBox.innerHTML = '';
    card.classList.remove('is-offline');
  }

  /**
   * A transient warning must not swallow a standing one. "Not sent" is shown
   * for six seconds; if the connection is still down when it expires, the
   * offline banner has to come back rather than leave the box looking healthy
   * while nothing is arriving.
   */
  function refreshAlert() {
    if (S.failures >= 2) setAlert('error', t('offline', null, 'Offline — reconnecting'), t('retry', null, 'Retry'));
    else hideAlert();
  }

  function clearAlert(soon) {
    if (soon) {
      clearTimeout(alertTimer);
      alertTimer = setTimeout(refreshAlert, soon);
      return;
    }
    hideAlert();
  }

  // ------------------------------------------------------------------ polling

  function interval() {
    return document.hidden ? POLL_HIDDEN : POLL_ACTIVE;
  }

  function schedule(ms) {
    clearTimeout(S.timer);
    S.timer = setTimeout(tick, Math.max(0, ms));
  }

  function tick() {
    try {
      // mount() polls when it actually attaches the card, so this must not poll
      // again — "two entry points both fetch" is exactly the shape of the bug
      // this rewrite exists to remove
      if (mount()) return;
      if (!card || !card.isConnected) {
        // off the index: the card is parked, the state is kept, nothing is
        // fetched. Coming back re-inserts this same node with its log intact.
        schedule(1000);
        return;
      }
      poll('tick');
    } catch (e) {
      schedule(POLL_ACTIVE);
    }
  }

  function poll(reason) {
    if (S.inFlight) return;                       // exactly one request in flight
    var gap = Date.now() - S.lastPoll;
    if (gap < POLL_MIN_GAP && reason !== 'send') {
      schedule(POLL_MIN_GAP - gap);
      return;
    }

    S.inFlight = true;
    S.lastPoll = Date.now();
    var asked = S.since;

    var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var to = setTimeout(function () { if (ctl) ctl.abort(); }, 12000);

    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (ctl) opts.signal = ctl.signal;

    fetch(API + '?since=' + asked + '&cid=' + encodeURIComponent(cid()), opts)
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (j) {
        clearTimeout(to);
        S.inFlight = false;
        S.failures = 0;
        S.backoff = 0;
        record(reason, asked, j, 'ok');
        apply(j);
        clearAlert();
        schedule(interval());
      })
      .catch(function (e) {
        clearTimeout(to);
        S.inFlight = false;
        S.failures++;
        S.backoff = Math.min(BACKOFF_MAX, S.backoff ? S.backoff * 2 : BACKOFF_START);
        record(reason, asked, null, String(e && e.message ? e.message : e));
        // one failure is a blip; say so only when it is a state
        if (S.failures >= 2) {
          setAlert('error', t('offline', null, 'Offline — reconnecting'), t('retry', null, 'Retry'));
        }
        schedule(S.backoff + Math.floor(Math.random() * 400));
      });
  }

  function record(reason, asked, payload, status) {
    probe.polls.push({
      t: new Date().toISOString(),
      reason: reason,
      asked: asked,
      cursor: S.since,
      got: payload ? (payload.messages || []).map(function (m) { return m.id; }) : [],
      online: payload ? payload.online : null,
      status: status,
      dom: log ? log.children.length : 0
    });
    if (probe.polls.length > 60) probe.polls.shift();
  }

  // -------------------------------------------------------------------- send

  /** My own avatar, so an optimistic row does not swap picture when it lands. */
  function myAvatar() {
    if (S.youAvatar) return S.youAvatar;
    try {
      var u = window.flarum.core.app.session.user;
      S.youAvatar = (u && u.avatarUrl && u.avatarUrl()) || null;
    } catch (e) {
      S.youAvatar = null;
    }
    return S.youAvatar;
  }

  function send(text) {
    var nonce = 'p' + (++pendingSeq);
    var node = messageNode({
      nonce: nonce,
      userId: S.youId,
      username: S.you,
      avatarUrl: myAvatar(),
      body: text,
      createdAt: new Date(now()).toISOString()
    }, true);
    log.appendChild(node);
    regroup();
    paintEmpty();
    toBottom(true);

    post(text, node);
  }

  function post(text, node) {
    var headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    var token = csrf();
    if (token) headers['X-CSRF-Token'] = token;

    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify({ body: text, cid: cid(), since: S.since })
    })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { return { r: r, j: j }; });
      })
      .then(function (res) {
        probe.sends.push({ t: new Date().toISOString(), status: res.r.status, body: text.slice(0, 40) });
        if (res.r.ok) {
          node.remove();                 // the real row arrives in this payload
          apply(res.j);
          clearAlert();
          return;
        }
        failSend(node, text, res.j, res.r.status);
      })
      .catch(function (e) {
        probe.sends.push({ t: new Date().toISOString(), status: 'network', body: text.slice(0, 40) });
        failSend(node, text,
          { error: 'network', detail: t('error.network', null, 'Not sent — check your connection.') }, 0);
      });
  }

  function failSend(node, text, j, status) {
    // The server sends both a machine `error` and a human `detail`. Prefer the
    // translated string for the code we know, and fall back to the server's
    // own wording — which is right for a refusal this client has not heard of.
    var detail = t('api.' + ((j && j.error) || ''), { max: S.maxLength },
      (j && j.detail) || t('error.send', null, 'Could not send.'));
    node.classList.remove('is-pending');
    node.classList.add('is-failed');
    var time = node.querySelector('.LmxChat-time');
    if (time) time.textContent = t('failed', null, 'failed');

    var bubble = node.querySelector('.LmxChat-bubble');
    if (bubble && !bubble.querySelector('.LmxChat-failnote')) {
      var note = el('div', 'LmxChat-failnote');
      note.appendChild(el('span', null, detail));
      var again = el('button', 'LmxChat-retry', t('retry', null, 'Retry'));
      again.type = 'button';
      again.addEventListener('click', function () {
        note.remove();
        node.classList.remove('is-failed');
        node.classList.add('is-pending');
        var t2 = node.querySelector('.LmxChat-time');
        if (t2) t2.textContent = t('sending', null, 'sending…');
        post(text, node);
      });
      var drop = el('button', 'LmxChat-retry LmxChat-drop', t('discard', null, 'Discard'));
      drop.type = 'button';
      drop.addEventListener('click', function () { node.remove(); paintEmpty(); });
      note.appendChild(again);
      note.appendChild(drop);
      bubble.appendChild(note);
      // the note is two lines tall and lands at the bottom of a scrolled log,
      // so without this the Retry button is below the fold — measured, it was
      // a 3px sliver in the screenshot
      if (S.pinned) toBottom(true);
    }

    if (status === 403) {
      // the session went away underneath us
      S.canPost = false;
      paintPost();
    }
    setAlert('warn', detail, null);
    clearAlert(6000);
  }

  function remove(li) {
    var id = li.getAttribute('data-mid');
    if (!id) { li.remove(); return; }
    li.classList.add('is-removing');
    var headers = { Accept: 'application/json' };
    var token = csrf();
    if (token) headers['X-CSRF-Token'] = token;

    fetch(API + '/' + encodeURIComponent(id), { method: 'DELETE', credentials: 'same-origin', headers: headers })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        li.remove();
        regroup();
        paintEmpty();
      })
      .catch(function () {
        li.classList.remove('is-removing');
        setAlert('warn', t('error.delete', null, 'Could not delete that message.'), null);
        clearAlert(5000);
      });
  }

  // ------------------------------------------------------------------- build

  function build() {
    var c = el('section', 'LmxCard LmxChat');
    c.setAttribute('data-lmx-chat', '');

    var head = el('h3', null);
    head.appendChild(icon('fas fa-comments'));
    head.appendChild(el('span', 'LmxChat-title', t('title', null, 'Chat')));

    liveEl = el('button', 'LmxChat-live');
    liveEl.type = 'button';
    liveEl.setAttribute('aria-label', t('presence.aria', null, 'People here now'));
    dotEl = el('span', 'LmxChat-dot');
    onlineEl = el('span', 'LmxChat-online', '0');
    liveEl.appendChild(dotEl);
    liveEl.appendChild(onlineEl);
    liveEl.appendChild(el('span', 'LmxChat-here', t('presence.here', null, 'here')));
    liveEl.addEventListener('click', function () { poll('presence'); });
    head.appendChild(liveEl);
    c.appendChild(head);

    alertBox = el('div', 'LmxChat-alert');
    alertBox.hidden = true;
    alertBox.setAttribute('role', 'status');
    c.appendChild(alertBox);

    logWrap = el('div', 'LmxChat-logwrap');
    log = el('ol', 'LmxChat-log');
    log.setAttribute('role', 'log');
    log.setAttribute('aria-live', 'polite');
    log.setAttribute('aria-label', t('log_aria', null, 'Chat messages'));
    log.addEventListener('scroll', function () {
      var b = atBottom();
      if (b !== S.pinned) S.pinned = b;
      if (b) { S.unread = 0; }
      paintJump();
      paintMask();
    });
    logWrap.appendChild(log);

    empty = el('div', 'LmxChat-empty');
    empty.appendChild(icon('fas fa-comment-dots'));
    empty.appendChild(el('p', null, t('empty_title', null, 'No messages yet.')));
    empty.appendChild(el('span', null,
      t('empty_hint', null, 'Say something — everyone on the index sees it.')));
    logWrap.appendChild(empty);

    jump = el('button', 'LmxChat-jump');
    jump.type = 'button';
    jump.hidden = true;
    jump.appendChild(el('span', null, t('unread', { count: 0 }, '0 new')));
    jump.appendChild(icon('fas fa-arrow-down'));
    jump.addEventListener('click', function () { toBottom(true); });
    logWrap.appendChild(jump);

    c.appendChild(logWrap);

    form = el('form', 'LmxChat-form');
    form.setAttribute('autocomplete', 'off');
    input = el('input', 'LmxChat-input');
    input.type = 'text';
    input.setAttribute('placeholder', t('placeholder', null, 'Say something…'));
    input.setAttribute('aria-label', t('input_aria', null, 'Chat message'));
    input.setAttribute('maxlength', String(S.maxLength));
    remain = el('span', 'LmxChat-remain');
    remain.hidden = true;
    sendBtn = el('button', 'LmxChat-send');
    sendBtn.type = 'submit';
    sendBtn.disabled = true;
    sendBtn.setAttribute('aria-label', t('send_aria', null, 'Send message'));
    sendBtn.appendChild(icon('fas fa-paper-plane'));
    form.appendChild(input);
    form.appendChild(remain);
    form.appendChild(sendBtn);

    input.addEventListener('input', paintRemain);
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var text = input.value.trim();
      if (!text) return;
      input.value = '';
      paintRemain();
      send(text);
    });
    c.appendChild(form);

    guestBox = el('div', 'LmxChat-guest');
    guestBox.hidden = true;
    var loginBtn = el('button', 'LmxChat-login', t('login', null, 'Log in to chat'));
    loginBtn.type = 'button';
    loginBtn.addEventListener('click', openLogin);
    guestBox.appendChild(loginBtn);
    guestBox.appendChild(el('span', null, t('guest_note', null, 'Members can post here.')));
    c.appendChild(guestBox);

    return c;
  }

  function openLogin() {
    try {
      var f = window.flarum && window.flarum.core;
      var app = f && f.app;
      var compat = f && f.compat;
      var Modal = compat && (compat['components/LogInModal'] || compat['forum/components/LogInModal']);
      if (app && app.modal && Modal) {
        app.modal.show(Modal);
        return;
      }
    } catch (e) {}
    var b = document.querySelector('.Header-secondary .Button--link, .Header-secondary button');
    if (b) b.click();
  }

  // ------------------------------------------------------------------- mount

  /**
   * Put the card where it belongs, without rebuilding it.
   *
   * looksmax-index re-creates `.LmxIndex-side` from a template on its own timer,
   * so the card gets orphaned regularly. Re-inserting the same node is what
   * makes that invisible: no reset, no refetch, no flash of an empty log.
   */
  function mount() {
    var side = document.querySelector('.LmxIndex-side');
    if (!side) return false;
    if (card && card.parentNode === side) return false;

    if (!card) {
      card = build();
      if (boot) {
        apply(boot);   // paint the server-rendered state before the first poll
        boot = null;
      }
    }

    var anchor = side.querySelector('.LmxCard--motd');
    if (anchor && anchor.nextSibling) side.insertBefore(card, anchor.nextSibling);
    else if (anchor) side.appendChild(card);
    else side.insertBefore(card, side.firstChild);

    // re-inserted node: the browser resets scrollTop on a detached element
    if (S.pinned) toBottom(false);
    poll('mount');
    return true;
  }

  // ------------------------------------------------------------------- boot

  function start() {
    mount();
    schedule(interval());

    // React the moment looksmax-index swaps the sidebar in, instead of waiting
    // out a poll interval. Debounced to one check per frame.
    var queued = false;
    try {
      new MutationObserver(function () {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () {
          queued = false;
          try { mount(); } catch (e) {}
        });
      }).observe(document.body, { childList: true, subtree: true });
    } catch (e) {}

    window.addEventListener('popstate', function () { try { mount(); } catch (e) {} });

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        // one catch-up request carrying everything missed — never a burst
        schedule(0);
      } else {
        schedule(POLL_HIDDEN);
      }
    });
    window.addEventListener('online', function () {
      S.backoff = 0;
      S.failures = 0;
      schedule(0);
    });

    // Somebody touching the page is a reason to try again now. A network can
    // come back without `online` firing at all (a proxy, a captive portal, a
    // blocked host), and waiting out the backoff in front of a user who is
    // clearly there is the difference between "reconnecting" and "broken".
    function nudge() {
      if (!S.failures || S.inFlight) return;
      if (Date.now() - S.lastPoll < 2500) return;
      schedule(0);
    }
    window.addEventListener('focus', nudge);
    document.addEventListener('pointerdown', nudge, true);
    document.addEventListener('keydown', nudge, true);
    window.addEventListener('offline', function () {
      setAlert('error', t('offline', null, 'Offline — reconnecting'), t('retry', null, 'Retry'));
    });

    timeTimer = setInterval(function () { try { paintTimes(); } catch (e) {} }, TIME_TICK);
  }

  // Instrumentation. The duplicate this replaces was invisible to the network
  // tab and obvious the moment the cursor was printed next to the DOM count.
  window.__lmxChat = {
    get since() { return S.since; },
    state: function () {
      return {
        since: S.since,
        dom: log ? log.children.length : 0,
        seen: Object.keys(S.seen).length,
        online: S.online,
        members: S.members,
        guests: S.guests,
        canPost: S.canPost,
        canModerate: S.canModerate,
        you: S.you,
        pinned: S.pinned,
        unread: S.unread,
        inFlight: S.inFlight,
        backoff: S.backoff,
        failures: S.failures,
        mounted: !!(card && card.isConnected)
      };
    },
    polls: probe.polls,
    sends: probe.sends,
    poll: function () { poll('manual'); },
    send: function (text) { if (S.canPost) send(text); },
    card: function () { return card; }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { try { start(); } catch (e) {} });
  } else {
    try { start(); } catch (e) {}
  }
})();
