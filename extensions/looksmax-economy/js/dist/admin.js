/*
 * Admin: every number the ledger pays or refuses, in one screen.
 *
 * Same hand-authored, separately-injected shape as looksmax-userinfo's admin
 * bundle and for the same reason (see src/InjectAdminScript.php): a top-level
 * throw inside the concatenated admin bundle stops every extension registered
 * after it, and the admin panel is where you go to turn a broken extension
 * off.
 *
 * Every control here writes an `economy.*` setting that src/Config.php reads,
 * falling back to the shipped default (shown as the placeholder) when a field
 * is left blank. Nothing here validates a number beyond what the input type
 * itself enforces — Config::cast() on the PHP side is the real guard, so a
 * bad value typed here degrades to "back to the default", never to a broken
 * page.
 *
 * `registerSetting` is the supported admin API and exists by the time this
 * polls successfully; the poll is what makes the separate <script> safe.
 */
(function () {
  'use strict';

  var EXT = 'local-looksmax-economy';
  var tries = 0;

  function t(key) {
    try {
      var v = window.app.translator.trans('local-looksmax-economy.admin.settings.' + key);
      // trans() returns an ARRAY when the message has placeholders, and an
      // array in a label renders with commas between the fragments.
      return Array.isArray(v) ? v.join('') : v;
    } catch (e) {
      return key;
    }
  }

  function reg(d, setting, type, key, placeholder) {
    var opts = { setting: 'economy.' + setting, type: type, label: t(key) };
    if (placeholder !== undefined) {
      opts.placeholder = String(placeholder);
    }
    d.registerSetting(opts);
  }

  (function attempt() {
    var app = window.app;
    if (!app || !app.extensionData || !app.translator) {
      if (++tries > 400) return;
      setTimeout(attempt, tries < 100 ? 0 : 100);
      return;
    }

    try {
      var d = app.extensionData.for(EXT);

      // --- awards ---------------------------------------------------------
      reg(d, 'award.discussionStarted', 'number', 'award_discussion_started', 5);
      reg(d, 'award.postCreated', 'number', 'award_post_created', 2);
      reg(d, 'award.reactionReceived', 'number', 'award_reaction_received', 4);
      reg(d, 'award.reactionGiven', 'number', 'award_reaction_given', 1);
      reg(d, 'award.bestAnswerAwarded', 'number', 'award_best_answer', 40);
      reg(d, 'award.guidePublished', 'number', 'award_guide_published', 25);
      reg(d, 'award.postReadThrough', 'number', 'award_read_through', 3);
      reg(d, 'award.threadHeld', 'number', 'award_thread_held', 6);
      reg(d, 'award.guideSourced', 'number', 'award_guide_sourced', 15);
      reg(d, 'award.streakDay', 'number', 'award_streak_day', 5);
      reg(d, 'award.streakWeek', 'number', 'award_streak_week', 40);
      reg(d, 'award.importLegacy', 'number', 'award_import_legacy', 1);
      reg(d, 'award.badgeEarned', 'number', 'award_badge_earned', 1);
      reg(d, 'award.rankUp', 'number', 'award_rank_up', 20);
      reg(d, 'award.streakMilestone', 'number', 'award_streak_milestone', 50);

      // --- daily caps -------------------------------------------------------
      reg(d, 'cap.postCreated', 'number', 'cap_post_created', 40);
      reg(d, 'cap.reactionGiven', 'number', 'cap_reaction_given', 60);
      reg(d, 'cap.reactionReceived', 'number', 'cap_reaction_received', 200);
      reg(d, 'cap.postReadThrough', 'number', 'cap_read_through', 150);
      reg(d, 'cap.threadHeld', 'number', 'cap_thread_held', 60);

      // --- streaks ------------------------------------------------------
      reg(d, 'streak.minLength', 'number', 'streak_min_length', 80);
      reg(d, 'streak.weekEvery', 'number', 'streak_week_every', 7);
      reg(d, 'streak.milestones', 'text', 'streak_milestones', '7,30,100,365');

      // --- reactions ------------------------------------------------------
      reg(d, 'reaction.pairDailyCap', 'number', 'reaction_pair_cap', 6);
      reg(d, 'reaction.maxPostAgeDays', 'number', 'reaction_max_age', 90);

      // --- post effort weighting -------------------------------------------
      reg(d, 'post.minMultiplier', 'text', 'post_min_multiplier', 0.25);
      reg(d, 'post.maxMultiplier', 'text', 'post_max_multiplier', 2.0);
      reg(d, 'post.multiplierDivisor', 'number', 'post_multiplier_divisor', 400);

      window.__lmxEconomyAdmin = { bound: true, at: Date.now() };
    } catch (e) {
      if (window.console) console.warn('economy admin: registration failed', e);
      window.__lmxEconomyAdmin = { bound: false, error: String(e) };
    }
  })();
})();
