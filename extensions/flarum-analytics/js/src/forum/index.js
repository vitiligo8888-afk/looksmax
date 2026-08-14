import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import PostStream from 'flarum/forum/components/PostStream';

/**
 * Client-side instrumentation.
 *
 * The server already captures every domain event and every discussion view, so
 * this deliberately only collects what the server CANNOT see: how far someone
 * read, how long they stayed, and whether they started writing and gave up.
 *
 * Two delivery bugs are designed out from the start, because both cost us real
 * data on a previous integration:
 *
 *  1. Mount-order race — a queue that starts buffering immediately and only
 *     drains once consent is resolved, rather than dropping events fired before
 *     the tracker mounts. Early events (first pageview, first scroll) are
 *     exactly the ones a naive implementation loses.
 *  2. Terminal events — dwell and scroll-depth fire during pagehide/unload,
 *     where a normal fetch is killed mid-flight. Uses fetch(keepalive) with a
 *     sendBeacon fallback so the last event of a session actually lands.
 */

const queue = [];
let ready = false;
let consent = null;

function endpoint() {
  return app.forum.attribute('apiUrl') + '/analytics/events';
}

function consentGiven() {
  if (consent !== null) return consent;
  const mode = app.forum.attribute('analytics.consent.mode') || 'implicit';
  if (mode === 'implicit') return (consent = true);
  try {
    consent = localStorage.getItem('analytics.consent') === 'granted';
  } catch (e) {
    consent = false;
  }
  return consent;
}

/** Buffer first, decide later — never drop an event for arriving too early. */
export function track(type, props = {}) {
  queue.push({ type, props, at: Date.now() });
  if (ready) drain();
}

function drain(terminal = false) {
  if (!queue.length) return;
  if (!consentGiven()) {
    queue.length = 0;
    return;
  }

  const body = JSON.stringify({ events: queue.splice(0, queue.length) });
  const url = endpoint();

  if (terminal) {
    // keepalive survives the document being torn down; sendBeacon is the
    // fallback for browsers that refuse keepalive on same-origin POST.
    try {
      if (!navigator.sendBeacon || !navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }))) {
        fetch(url, { method: 'POST', body, keepalive: true, headers: { 'Content-Type': 'application/json' } });
      }
    } catch (e) {
      /* nothing more we can do at unload */
    }
    return;
  }

  fetch(url, {
    method: 'POST',
    body,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.session.csrfToken },
  }).catch(() => {
    /* dropped; the server-side stream is the authoritative one */
  });
}

app.initializers.add('local-analytics', () => {
  if (app.forum.attribute('analytics.client.enabled') === false) return;

  ready = true;
  drain();

  let entered = 0;
  let deepest = 0;
  let current = null;

  extend(DiscussionPage.prototype, 'oninit', function () {
    entered = Date.now();
    deepest = 0;
    current = this.attrs?.id ?? null;
  });

  // read depth: the fraction of the thread actually reached, which separates
  // "opened it" from "read it" — the signal ranking actually wants
  extend(PostStream.prototype, 'onupdate', function () {
    const pct = this.stream?.visibleEnd && this.stream?.count?.()
      ? this.stream.visibleEnd / this.stream.count()
      : 0;
    if (pct > deepest) deepest = pct;
  });

  const flushDwell = (terminal) => {
    if (!entered || !current) return;
    const dwell = Date.now() - entered;
    if (dwell < 1500) return; // bounces are noise
    track('discussion.dwell', {
      discussion_id: current,
      dwell_ms: dwell,
      read_pct: Math.round(deepest * 100),
    });
    entered = 0;
    drain(terminal);
  };

  extend(DiscussionPage.prototype, 'onremove', () => flushDwell(false));
  window.addEventListener('pagehide', () => flushDwell(true));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flushDwell(true);
  });

  // composer funnel: started -> submitted, so abandonment is measurable
  extend(app.composer.constructor.prototype, 'show', function () {
    track('composer.opened', { context: this.body?.componentClass?.name ?? null });
  });
});
