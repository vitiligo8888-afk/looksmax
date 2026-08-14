/**
 * Minimal CDP client shared by the visual-system checks.
 *
 * The existing e2e scripts each inline their own copy of this. Two other lanes
 * extend that directory, so rather than rewriting theirs this pulls the plumbing
 * into one module that the new checks import. Nothing here is theme specific.
 */
import { mkdirSync, writeFileSync } from "node:fs";

export const CHROME =
  process.env.CHROME_PATH ||
  "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";

export type Viewport = { name: string; width: number; height: number; mobile?: boolean };

export class Browser {
  ws!: WebSocket;
  proc: any;
  id = 0;
  pending = new Map<number, { res: (v: any) => void; rej: (e: any) => void }>();
  handlers = new Map<string, ((p: any) => void)[]>();
  sessionId?: string;
  targetId?: string;

  consoleErrors: string[] = [];
  failedRequests: string[] = [];
  /** 4xx/5xx responses, including images — a 404 avatar is a defect, not noise. */
  badResponses: { url: string; status: number; type: string }[] = [];
  /** every request that never completed, images included */
  failedImages: string[] = [];
  /** requestId -> url, so a loadingFailed event can name the resource */
  reqUrls = new Map<string, string>();

  static async launch(port = 21800, window = "1600,1200") {
    const b = new Browser();
    /*
     * A FRESH profile directory per run, not per port.
     *
     * The previous form was `/tmp/visual-${port}`, which is stable across runs.
     * A killed or crashed Chromium leaves a SingletonLock in it, and the next
     * launch on the same port then exits immediately — and because stdout and
     * stderr were piped and never read, the only symptom was
     * "chromium did not expose CDP on 21800" with no reason attached. Measured
     * on this box: 19 stale /tmp/visual-* directories, and the sweep failing at
     * launch with that bare message. The profile is disposable, so it gets a
     * unique name and is removed on close.
     */
    b.profileDir = `/tmp/visual-${port}-${process.pid}-${Date.now()}`;
    b.proc = Bun.spawn(
      [
        CHROME,
        "--headless=new",
        "--no-sandbox",
        "--disable-gpu",
        "--disable-dev-shm-usage",
        `--user-data-dir=${b.profileDir}`,
        `--remote-debugging-port=${port}`,
        "--remote-allow-origins=*",
        `--window-size=${window}`,
        "--force-device-scale-factor=1",
        "--hide-scrollbars",
        "--font-render-hinting=none",
      ],
      { stdout: "pipe", stderr: "pipe", detached: true },
    );

    let version: any = null;
    for (let i = 0; i < 60; i++) {
      try {
        const r = await fetch(`http://127.0.0.1:${port}/json/version`);
        if (r.ok) {
          version = await r.json();
          break;
        }
      } catch {}
      if (b.proc.exitCode !== null) break; // it died; stop waiting 24 seconds for it
      await Bun.sleep(400);
    }
    if (!version) {
      // Say WHY. A launch failure with no stderr is unactionable, and this one
      // cost a full sweep before the reason (a stale profile lock) was visible.
      let err = "";
      try { err = (await new Response(b.proc.stderr).text()).slice(-600); } catch {}
      try { b.proc.kill(); } catch {}
      throw new Error(
        `chromium did not expose CDP on ${port} (exit=${b.proc.exitCode}) profile=${b.profileDir}\n${err}`,
      );
    }

    b.ws = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise<void>((res, rej) => {
      b.ws.onopen = () => res();
      b.ws.onerror = (e) => rej(new Error(String(e)));
    });
    b.ws.onmessage = (ev) => {
      const m = JSON.parse(String(ev.data));
      if (m.id != null) {
        const p = b.pending.get(m.id);
        if (p) {
          b.pending.delete(m.id);
          m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result);
        }
      } else if (m.method) {
        for (const fn of b.handlers.get(m.method) || []) fn(m.params);
      }
    };

    const t = await b.send("Target.createTarget", { url: "about:blank" });
    b.targetId = t.targetId;
    const a = await b.send("Target.attachToTarget", { targetId: t.targetId, flatten: true });
    b.sessionId = a.sessionId;

    await b.send("Page.enable");
    await b.send("Runtime.enable");
    await b.send("Network.enable");
    // The view-capture middleware filters obvious bots and headless Chrome
    // advertises itself, so without this our own filter excludes the harness.
    await b.send("Network.setUserAgentOverride", {
      userAgent:
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    });

    b.on("Runtime.consoleAPICalled", (p) => {
      if (p.type === "error")
        b.consoleErrors.push(
          (p.args || []).map((x: any) => x.value ?? x.description).join(" ").slice(0, 240),
        );
    });
    b.on("Runtime.exceptionThrown", (p) => {
      b.consoleErrors.push(p.exceptionDetails?.text ?? "exception");
    });
    // A bare "Font net::ERR_FAILED" names no font and is worth almost nothing,
    // so requests are tracked by id and the URL is attached to the failure. A
    // failed network request is ALWAYS instrumented with what actually failed.
    b.on("Network.requestWillBeSent", (p) => {
      b.reqUrls.set(p.requestId, p.request?.url || "?");
      if (b.reqUrls.size > 4000) b.reqUrls.clear();
    });
    b.on("Network.loadingFailed", (p) => {
      const url = (b.reqUrls.get(p.requestId) || "?").slice(0, 200);
      const line = `${p.type} ${p.errorText} ${url}`;
      if (p.type === "Image") b.failedImages.push(line);
      else b.failedRequests.push(line);
    });
    // An avatar that 404s still "loads" as far as loadingFailed is concerned —
    // the server answered. Only the status code catches it, which is why the
    // standing-page avatars looked like a CSS problem for a while.
    b.on("Network.responseReceived", (p) => {
      const s = p.response?.status ?? 0;
      if (s >= 400) b.badResponses.push({ url: String(p.response.url).slice(0, 160), status: s, type: p.type });
    });

    return b;
  }

  /** Clear everything recorded so far, so a per-surface report is per-surface. */
  resetNetwork() {
    this.consoleErrors = [];
    this.failedRequests = [];
    this.badResponses = [];
    this.failedImages = [];
    this.reqUrls.clear();
  }

  /**
   * Log in through Flarum's own session API from inside the page.
   *
   * Driving the modal is what a user does, but it is also three selectors and a
   * redraw that any theme change can break, which makes every logged-in
   * assertion hostage to the login form. `app.session.login` is the same code
   * path the modal submits to, and the cookie it sets is what the rest of the
   * run needs. The modal itself is still screenshot as a surface.
   */
  async login(identification: string, password: string, base: string) {
    await this.goto(base + "/");
    const r = await this.eval(
      `(async () => {
         const app = window.flarum && window.flarum.core && window.flarum.core.app;
         if (!app) return 'no-app';
         if (app.session && app.session.user) return 'already';
         try {
           await app.session.login({ identification: ${JSON.stringify(identification)}, password: ${JSON.stringify(password)} });
           return 'ok';
         } catch (e) {
           // Flarum rejects with a Response or an error payload, and String(e)
           // on either is "[object Object]" — which is how a login failure
           // reported nothing at all. Dig the real reason out.
           let d = '';
           try {
             if (e && typeof e.json === 'function') d = JSON.stringify(await e.json());
             else if (e && e.response) d = JSON.stringify(e.response);
             else if (e && e.message) d = e.message;
             else d = JSON.stringify(e, Object.getOwnPropertyNames(e || {}));
           } catch (x) { d = 'undecodable: ' + String(x); }
           return 'fail status=' + (e && e.status !== undefined ? e.status : '?') + ' ' + d.slice(0, 400);
         }
       })()`,
      true,
    );
    if (r !== "ok" && r !== "already") return r;
    // session.login resolves before the page reloads with the new session
    await Bun.sleep(1500);
    await this.goto(base + "/");
    const who = await this.eval(
      `(() => { const u = window.flarum.core.app.session.user; return u ? u.username() : null; })()`,
    );
    return who ? `ok:${who}` : "no-session-after-reload";
  }

  on(method: string, fn: (p: any) => void) {
    const list = this.handlers.get(method) || [];
    list.push(fn);
    this.handlers.set(method, list);
  }

  send(method: string, params: any = {}): Promise<any> {
    const id = ++this.id;
    return new Promise((res, rej) => {
      this.pending.set(id, { res, rej });
      this.ws.send(JSON.stringify({ id, method, params, sessionId: this.sessionId }));
      setTimeout(() => {
        if (this.pending.delete(id)) rej(new Error(`timeout ${method}`));
      }, 60000);
    });
  }

  async eval(expr: string, awaitPromise = false) {
    const r = await this.send("Runtime.evaluate", {
      expression: expr,
      awaitPromise,
      returnByValue: true,
      timeout: 50000,
    });
    if (r.exceptionDetails) {
      // exceptionDetails.text is almost always the useless string "Uncaught".
      // The actual message and stack are on the thrown object's description,
      // and without them an audit that throws reports nothing at all — which is
      // worse than a failing audit, because the gate then passes vacuously.
      const d = r.exceptionDetails;
      const detail =
        d.exception?.description ||
        d.exception?.value ||
        [d.text, d.lineNumber != null ? `line ${d.lineNumber}:${d.columnNumber}` : ""].filter(Boolean).join(" ");
      throw new Error(`eval: ${String(detail).slice(0, 600)}`);
    }
    return r.result?.value;
  }

  async viewport(v: Viewport) {
    await this.send("Emulation.setDeviceMetricsOverride", {
      width: v.width,
      height: v.height,
      deviceScaleFactor: 1,
      mobile: !!v.mobile,
      screenWidth: v.width,
      screenHeight: v.height,
    });
    if (v.mobile) {
      await this.send("Emulation.setTouchEmulationEnabled", { enabled: true, maxTouchPoints: 5 });
    }
  }

  /** Navigate and wait for the Flarum SPA to have actually booted. */
  async goto(url: string, settle = 1400) {
    await this.send("Page.navigate", { url });
    const deadline = Date.now() + 30000;
    let ready = false;
    while (Date.now() < deadline) {
      await Bun.sleep(250);
      ready = await this.eval(`!!(document.querySelector('#app') && window.flarum)`).catch(() => false);
      if (ready) break;
    }
    if (ready) {
      // Wait for the loading indicator to clear and images to have layout, so
      // screenshots are not of a half-painted page.
      await this.eval(
        `new Promise(r => { const t = Date.now();
           (function poll(){
             const loading = document.querySelector('.LoadingIndicator:not(.LoadingIndicator--block)');
             const imgs = [...document.images].filter(i => !i.complete);
             if ((!loading && imgs.length === 0) || Date.now() - t > 8000) return r(1);
             setTimeout(poll, 200);
           })(); })`,
        true,
      ).catch(() => {});
      // Iconify renders through a custom element that fetches its SVG from an
      // API after the page has "loaded", so a screenshot taken on load shows
      // empty boxes where icons will be. Five forum icons looked like missing
      // glyphs in a review shot for exactly this reason and nothing was wrong
      // with them. Wait for the shadow roots to actually contain an <svg>.
      await this.eval(
        `new Promise(r => { const t = Date.now();
           (function poll(){
             const all = [...document.querySelectorAll('iconify-icon')];
             const done = all.filter(e => e.shadowRoot && e.shadowRoot.querySelector('svg')).length;
             if (all.length === 0 || done === all.length || Date.now() - t > 6000) return r(done + '/' + all.length);
             setTimeout(poll, 150);
           })(); })`,
        true,
      ).catch(() => {});
      await Bun.sleep(settle);
    }
    return ready;
  }

  async shot(path: string, opts: any = {}) {
    const r = await this.send("Page.captureScreenshot", {
      format: "png",
      captureBeyondViewport: false,
      ...opts,
    });
    if (r?.data) {
      mkdirSync(path.split("/").slice(0, -1).join("/"), { recursive: true });
      writeFileSync(path, Buffer.from(r.data, "base64"));
      return true;
    }
    return false;
  }

  /** Screenshot the full scrollable page, not just the viewport. */
  async fullShot(path: string) {
    const m = await this.send("Page.getLayoutMetrics");
    const h = Math.min(Math.ceil(m.cssContentSize?.height || 2000), 12000);
    const w = Math.ceil(m.cssContentSize?.width || 1600);
    return this.shot(path, {
      captureBeyondViewport: true,
      clip: { x: 0, y: 0, width: w, height: h, scale: 1 },
    });
  }

  async close() {
    try {
      await this.send("Target.closeTarget", { targetId: this.targetId });
    } catch {}
    try {
      this.proc.kill();
    } catch {}
  }
}
