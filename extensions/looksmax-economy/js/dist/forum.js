/*
 * The streak/progress widget — gamification depth #5 from the audit.
 *
 * Before this file existed, looksmax-economy had NO js/ directory at all: the
 * points/lifetimePoints/rankSlug it computes rode silently on the user
 * payload (extend.php's UserSerializer attribute) and nothing on the forum
 * ever showed a member their streak, their distance to the next rank, or how
 * much of today's earning caps they had left. A number nobody sees does not
 * make anybody want to come back tomorrow; a visible one does — that is the
 * entire argument for this file.
 *
 * Written the same way every other hand-authored bundle in this codebase is
 * written (see looksmax-userinfo/js/dist/admin.js's header for why there is
 * no build step): plain DOM, no Mithril component tree, wrapped end to end in
 * try/catch by the injector so a failure here costs a missing widget, never
 * the forum.
 *
 * Deliberately NOT a Mithril component overriding a core view (PostStream,
 * IndexPage, …): those are the most-clobbered extension points on this
 * install (see looksmax-guides' own audit notes) and every override is a
 * point of conflict with whichever extension loads after it. A single fixed-
 * position element appended to <body> once, updated by re-writing its own
 * innerHTML, cannot collide with anything.
 */
(function () {
  'use strict';

  var ROOT_ID = 'lmx-economy-widget';
  var tries = 0;

  function t(key, params) {
    try {
      var v = window.app.translator.trans('local-looksmax-economy.forum.widget.' + key, params);
      return Array.isArray(v) ? v.join('') : v;
    } catch (e) {
      return key;
    }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function clamp(n, lo, hi) {
    return Math.max(lo, Math.min(hi, n));
  }

  function barRow(label, count, cap) {
    var pct = cap > 0 ? clamp(Math.round((count / cap) * 100), 0, 100) : 0;
    var full = count >= cap && cap > 0;
    return (
      '<div class="lmx-econ-goal">' +
      '<div class="lmx-econ-goal-label"><span>' + esc(label) + '</span>' +
      '<span class="lmx-econ-goal-count' + (full ? ' is-full' : '') + '">' + esc(count) + ' / ' + esc(cap) + '</span></div>' +
      '<div class="lmx-econ-goal-track"><div class="lmx-econ-goal-fill' + (full ? ' is-full' : '') + '" style="width:' + pct + '%"></div></div>' +
      '</div>'
    );
  }

  function render(root, data) {
    var streak = data.streak || {};
    var rank = data.rank || {};
    var goals = data.goals || {};

    var flameClass = streak.heldToday ? 'lmx-econ-flame is-lit' : 'lmx-econ-flame';

    var body =
      '<div class="lmx-econ-panel">' +
        '<div class="lmx-econ-row lmx-econ-streak">' +
          '<span class="' + flameClass + '">&#128293;</span>' +
          '<div class="lmx-econ-streak-text">' +
            '<strong>' + esc(streak.current || 0) + '</strong> ' +
            '<span>' + esc(t(streak.current === 1 ? 'day_streak_one' : 'day_streak_other')) + '</span>' +
          '</div>' +
        '</div>' +
        (streak.nextMilestone
          ? '<div class="lmx-econ-sub">' + esc(t('to_milestone', { count: streak.toNextMilestone, milestone: streak.nextMilestone })) + '</div>'
          : '') +
        '<div class="lmx-econ-divider"></div>' +
        '<div class="lmx-econ-row lmx-econ-rank">' +
          '<div class="lmx-econ-rank-label">' +
            '<span class="lmx-econ-rank-slug rank--' + esc(rank.rankSlug || 'greycel') + '">' + esc(rank.rankSlug || '') + '</span>' +
            (rank.nextRankSlug
              ? '<span class="lmx-econ-rank-next">' + esc(t('points_to_rank', { count: rank.pointsToNextRank, rank: rank.nextRankSlug })) + '</span>'
              : '<span class="lmx-econ-rank-next">' + esc(t('top_rank')) + '</span>') +
          '</div>' +
          '<div class="lmx-econ-goal-track lmx-econ-rank-track"><div class="lmx-econ-goal-fill" style="width:' + clamp(rank.rankProgressPct || 0, 0, 100) + '%"></div></div>' +
        '</div>' +
        '<div class="lmx-econ-divider"></div>' +
        barRow(t('goal_posts'), goals.postsToday ? goals.postsToday.count : 0, goals.postsToday ? goals.postsToday.cap : 0) +
        barRow(t('goal_reactions'), goals.reactionsToday ? goals.reactionsToday.count : 0, goals.reactionsToday ? goals.reactionsToday.cap : 0) +
      '</div>';

    root.innerHTML =
      '<button type="button" class="lmx-econ-toggle" aria-expanded="false" title="' + esc(t('toggle_title')) + '">' +
        '<span class="' + flameClass + '">&#128293;</span>' +
        '<span class="lmx-econ-toggle-count">' + esc(streak.current || 0) + '</span>' +
      '</button>' +
      body;

    var toggle = root.querySelector('.lmx-econ-toggle');
    var panel = root.querySelector('.lmx-econ-panel');
    toggle.addEventListener('click', function () {
      var open = root.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    // Closing on an outside click keeps the panel from becoming one more
    // thing a member has to remember to dismiss.
    document.addEventListener('click', function (ev) {
      if (!root.contains(ev.target)) {
        root.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function celebrate(text) {
    var toast = document.createElement('div');
    toast.className = 'lmx-econ-toast';
    toast.textContent = text;
    document.body.appendChild(toast);
    // Two rAFs so the initial (pre-transition) state actually paints before
    // the class that animates it in is added — a single rAF can still land
    // in the same frame as the append on some browsers.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        toast.classList.add('is-shown');
      });
    });
    setTimeout(function () {
      toast.classList.remove('is-shown');
      setTimeout(function () { toast.parentNode && toast.parentNode.removeChild(toast); }, 400);
    }, 4200);
  }

  function checkCelebrations(userId, data) {
    try {
      var streak = data.streak || {};
      var rank = data.rank || {};

      var msKey = 'lmxEconMilestone:' + userId;
      var rankKey = 'lmxEconRank:' + userId;

      if (streak.atMilestone) {
        var lastMs = localStorage.getItem(msKey);
        if (String(lastMs) !== String(streak.current)) {
          celebrate(t('celebrate_streak', { count: streak.current }));
        }
        localStorage.setItem(msKey, String(streak.current));
      }

      var lastRank = localStorage.getItem(rankKey);
      if (lastRank && rank.rankSlug && lastRank !== rank.rankSlug) {
        celebrate(t('celebrate_rank', { rank: rank.rankSlug }));
      }
      if (rank.rankSlug) {
        localStorage.setItem(rankKey, rank.rankSlug);
      }
    } catch (e) {
      // localStorage can throw in a locked-down context (private mode quota,
      // etc.) — a missed celebration is not worth breaking the widget over.
    }
  }

  function boot() {
    var app = window.app;
    if (!app || !app.forum || !app.session) {
      if (++tries > 400) return;
      setTimeout(boot, tries < 100 ? 0 : 100);
      return;
    }

    if (!app.session.user) {
      return; // guests have no balance to show
    }

    var userId = app.session.user.id();
    var apiUrl = (app.forum.attribute('apiUrl') || '/api') + '/economy/summary';

    function load() {
      app.request({ method: 'GET', url: apiUrl }).then(function (res) {
        var data = res && res.data;
        if (!data) return;

        var root = document.getElementById(ROOT_ID);
        if (!root) {
          root = document.createElement('div');
          root.id = ROOT_ID;
          root.className = 'lmx-econ-widget';
          document.body.appendChild(root);
        }

        render(root, data);
        checkCelebrations(userId, data);
      }).catch(function () {
        // No summary this load — the rest of the forum is unaffected.
      });
    }

    load();
    // Refreshed on a slow interval rather than after every action: this is a
    // status display, not a live ticker, and polling every post/like/etc.
    // would mean hooking every mutation in every other extension. Five
    // minutes keeps the numbers close enough to right without adding a
    // meaningful request rate.
    setInterval(load, 5 * 60 * 1000);
  }

  boot();
})();
