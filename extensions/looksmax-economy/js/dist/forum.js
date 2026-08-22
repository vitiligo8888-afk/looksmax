/*
 * The streak/progress widget — gamification depth #5 from the audit, extended
 * with quests and achievement progress for the "make progression visible"
 * pass this shipped under.
 *
 * Before this file existed, looksmax-economy had NO js/ directory at all: the
 * points/lifetimePoints/rankSlug it computes rode silently on the user
 * payload (extend.php's UserSerializer attribute) and nothing on the forum
 * ever showed a member their streak, their distance to the next rank, or how
 * much of today's earning caps they had left. A number nobody sees does not
 * make anybody want to come back tomorrow; a visible one does — that is the
 * entire argument for this file, and it is also the argument for the two
 * things added to it here:
 *
 *   QUESTS      daily/weekly checklists that turn "post and something happens
 *               eventually" into "do these three things today" — see
 *               src/Quests.php for the reward/idempotency design. Rendered
 *               from the SAME /api/economy/summary payload the widget already
 *               polls, so this adds zero extra requests to the common case.
 *   NEXT BADGE  "you are 1,204 reactions from Consensus" instead of leaving
 *               it implicit — src/Api/SummaryController.php's `nextBadge`
 *               field, sourced from looksmax-ranks when it is installed.
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
  var busyQuest = null;
  /** Set by boot() once its inner load() exists. claimQuest() reloads through this rather than duplicating the fetch. */
  var reload = null;

  function reduceMotion() {
    try {
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }

  function t(key, params) {
    try {
      var v = window.app.translator.trans('local-looksmax-economy.forum.widget.' + key, params);
      return Array.isArray(v) ? v.join('') : v;
    } catch (e) {
      return key;
    }
  }

  function tq(key, params) {
    try {
      var v = window.app.translator.trans('local-looksmax-economy.forum.quests.' + key, params);
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

  /** One quest row: icon, name, progress bar, and a claim button when it is ready. */
  function questRow(q) {
    var pct = q.target > 0 ? clamp(Math.round((q.progress / q.target) * 100), 0, 100) : 0;
    var name = tq(q.key + '.name');
    var state = q.claimed
      ? '<span class="lmx-econ-quest-state is-claimed">' + esc(tq('claimed')) + '</span>'
      : (q.claimable
        ? '<button type="button" class="lmx-econ-quest-claim" data-quest="' + esc(q.key) + '">' + esc(tq('claim')) + '</button>'
        : '<span class="lmx-econ-quest-state">' + esc(q.progress) + ' / ' + esc(q.target) + '</span>');

    return (
      '<div class="lmx-econ-quest-row' + (q.claimed ? ' is-claimed' : '') + '">' +
        '<iconify-icon icon="' + esc(q.icon || 'ph:target-fill') + '" inline class="lmx-econ-quest-icon"></iconify-icon>' +
        '<div class="lmx-econ-quest-body">' +
          '<div class="lmx-econ-quest-name">' + esc(name) + '<span class="lmx-econ-quest-reward">+' + esc(q.reward) + '</span></div>' +
          '<div class="lmx-econ-goal-track lmx-econ-quest-track"><div class="lmx-econ-goal-fill' + (q.claimed ? ' is-full' : '') + '" style="width:' + pct + '%"></div></div>' +
        '</div>' +
        state +
      '</div>'
    );
  }

  function questSection(title, quests) {
    if (!quests || !quests.length) return '';
    return (
      '<div class="lmx-econ-quest-group">' +
        '<div class="lmx-econ-quest-group-title">' + esc(title) + '</div>' +
        quests.map(questRow).join('') +
      '</div>'
    );
  }

  function render(root, data) {
    var streak = data.streak || {};
    var rank = data.rank || {};
    var goals = data.goals || {};
    var quests = data.quests || [];
    var nextBadge = data.nextBadge || null;

    var flameClass = streak.heldToday ? 'lmx-econ-flame is-lit' : 'lmx-econ-flame';
    var daily = quests.filter(function (q) { return q.scope === 'daily'; });
    var weekly = quests.filter(function (q) { return q.scope === 'weekly'; });
    var claimableCount = quests.filter(function (q) { return q.claimable; }).length;

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
        (nextBadge
          ? '<div class="lmx-econ-next-badge">' +
              '<iconify-icon icon="' + esc(nextBadge.icon || 'ph:trophy-fill') + '" inline></iconify-icon>' +
              '<span>' + esc(t('next_badge', { count: nextBadge.remaining, badge: nextBadge.name })) + '</span>' +
            '</div>'
          : '') +
        '<div class="lmx-econ-divider"></div>' +
        barRow(t('goal_posts'), goals.postsToday ? goals.postsToday.count : 0, goals.postsToday ? goals.postsToday.cap : 0) +
        barRow(t('goal_reactions'), goals.reactionsToday ? goals.reactionsToday.count : 0, goals.reactionsToday ? goals.reactionsToday.cap : 0) +
        (quests.length
          ? '<div class="lmx-econ-divider"></div>' +
            '<div class="lmx-econ-quests">' +
              questSection(tq('daily'), daily) +
              questSection(tq('weekly'), weekly) +
            '</div>'
          : '') +
      '</div>';

    root.innerHTML =
      '<button type="button" class="lmx-econ-toggle" aria-expanded="false" title="' + esc(t('toggle_title')) + '">' +
        '<span class="' + flameClass + '">&#128293;</span>' +
        '<span class="lmx-econ-toggle-count">' + esc(streak.current || 0) + '</span>' +
        (claimableCount > 0 ? '<span class="lmx-econ-toggle-badge">' + esc(claimableCount) + '</span>' : '') +
      '</button>' +
      body;

    var toggle = root.querySelector('.lmx-econ-toggle');
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

    var claimButtons = root.querySelectorAll('.lmx-econ-quest-claim');
    for (var i = 0; i < claimButtons.length; i++) {
      claimButtons[i].addEventListener('click', onClaimClick);
    }
  }

  function onClaimClick(ev) {
    var key = ev.currentTarget.getAttribute('data-quest');
    if (!key || busyQuest) return;
    claimQuest(key);
  }

  function claimQuest(key) {
    var app = window.app;
    if (!app || !app.request) return;
    busyQuest = key;

    app.request({ method: 'POST', url: (app.forum.attribute('apiUrl') || '/api') + '/economy/quests/claim', body: { key: key } })
      .then(function (res) {
        busyQuest = null;
        // The controller answers with a flat object ({ok, reward, quests}),
        // same shape as SummaryController's {data: ...} — never a JSON:API
        // resource — so app.request()'s parsed body is used as-is.
        if (res && res.reward) {
          celebrate(tq('celebrate', { count: res.reward }), 'quest');
        }
        if (reload) reload();
      })
      .catch(function () {
        busyQuest = null;
        // A refused claim (already claimed, target slipped after a delete, or
        // quests disabled mid-request) just falls back to a fresh load — the
        // server is the only source of truth here, never the cached button.
        if (reload) reload();
      });
  }

  /**
   * A toast celebration. `kind` selects the visual only (colour, icon
   * treatment); every kind respects prefers-reduced-motion by skipping the
   * entrance transition and the confetti burst entirely — see the CSS guard
   * in less/forum.less and the `spark()` check below.
   */
  function celebrate(text, kind) {
    var toast = document.createElement('div');
    toast.className = 'lmx-econ-toast lmx-econ-toast--' + (kind || 'default');
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

    if (kind === 'rank' && !reduceMotion()) spark(toast);

    setTimeout(function () {
      toast.classList.remove('is-shown');
      setTimeout(function () { toast.parentNode && toast.parentNode.removeChild(toast); }, 400);
    }, 4800);
  }

  /**
   * A small CSS-driven confetti burst for a rank-up — a handful of DOM nodes
   * with a keyframe animation, never a canvas or a library, and never called
   * at all when prefers-reduced-motion is set (see the caller above and the
   * separate hard stop in CSS for anyone whose OS setting changes mid-session
   * without a reload).
   */
  function spark(toast) {
    var burst = document.createElement('span');
    burst.className = 'lmx-econ-spark';
    for (var i = 0; i < 8; i++) {
      var dot = document.createElement('i');
      dot.style.setProperty('--i', String(i));
      burst.appendChild(dot);
    }
    toast.appendChild(burst);
  }

  function checkCelebrations(userId, data) {
    try {
      var streak = data.streak || {};
      var rank = data.rank || {};
      var badges = data.recentBadges || [];

      var msKey = 'lmxEconMilestone:' + userId;
      var rankKey = 'lmxEconRank:' + userId;
      var badgeKey = 'lmxEconBadge:' + userId;

      if (streak.atMilestone) {
        var lastMs = localStorage.getItem(msKey);
        if (String(lastMs) !== String(streak.current)) {
          celebrate(t('celebrate_streak', { count: streak.current }), 'streak');
        }
        localStorage.setItem(msKey, String(streak.current));
      }

      var lastRank = localStorage.getItem(rankKey);
      if (lastRank && rank.rankSlug && lastRank !== rank.rankSlug) {
        celebrate(t('celebrate_rank', { rank: rank.rankSlug }), 'rank');
      }
      if (rank.rankSlug) {
        localStorage.setItem(rankKey, rank.rankSlug);
      }

      // New achievement since the last time this browser checked. Compared by
      // the newest `awardedAt` timestamp rather than count, so unlocking two
      // badges between polls still celebrates the newest one and does not
      // re-fire for older badges a previous session already saw.
      if (badges.length) {
        var newest = badges[0];
        var lastSeen = localStorage.getItem(badgeKey);
        if (newest.awardedAt && lastSeen !== newest.awardedAt) {
          if (lastSeen) {
            celebrate(t('celebrate_achievement', { name: newest.name }), 'achievement');
          }
          localStorage.setItem(badgeKey, newest.awardedAt);
        }
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

        var wasOpen = root.classList.contains('is-open');
        render(root, data);
        if (wasOpen) root.classList.add('is-open');
        checkCelebrations(userId, data);
      }).catch(function () {
        // No summary this load — the rest of the forum is unaffected.
      });
    }

    // claimQuest() reloads through the module-scope `reload` reference rather
    // than duplicating this fetch.
    reload = load;

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
