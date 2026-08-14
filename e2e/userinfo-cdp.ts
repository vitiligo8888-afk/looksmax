/**
 * Shared CDP driver for the user-identity checks.
 *
 * A NEW file rather than an edit to harness.ts: three lanes are extending the
 * e2e directory at the same time and harness.ts is the one file all three
 * would collide on. Everything here is self-contained, so `bun e2e/userinfo.ts`
 * and `bun e2e/harness.ts` can run concurrently — they take different debug
 * ports and different screenshot directories.
 *
 * The bar, unchanged from harness.ts: HTTP 200 proves nothing, every assertion
 * is on rendered DOM or on a database row, console errors fail the run, and
 * screenshots are captured so a layout regression is reviewable rather than
 * asserted into meaninglessness.
 */
import { mkdirSync, writeFileSync } from "node:fs";

export type Check = { name: string; ok: boolean; detail?: string };

export class Runner {
  checks: Check[] = [];
  consoleErrors: string[] = [];
  failedRequests: string[] = [];

  check(name: string, ok: boolean, detail?: any) {
    this.checks.push({
      name,
      ok: !!ok,
      detail: detail === undefined ? undefined : String(detail).slice(0, 300),
    });
    console.log(
      `  ${ok ? "ok  " : "FAIL"} ${name}${detail !== undefined ? `  (${String(detail).slice(0, 140)})` : ""}`
    );
  }

  get failed() {
    return this.checks.filter((c) => !c.ok);
  }

  summary(shotDir: string) {
    console.log(`\n${this.checks.length - this.failed.length}/${this.checks.length} checks passed`);
    writeFileSync(
      `${shotDir}/results.json`,
      JSON.stringify(
        { checks: this.checks, consoleErrors: this.consoleErrors, failedRequests: this.failedRequests },
        null,
        2
      )
    );
    for (const f of this.failed) console.log(`  FAILED: ${f.name}  ${f.detail ?? ""}`);
    return this.failed.length;
  }
}

export class CDP {
  ws!: WebSocket;
  id = 0;
  pending = new Map<number, any>();
  handlers = new Map<string, ((p: any) => void)[]>();
  sessionId?: string;
  shotDir = "/work/flarum/userinfo-shots";

  static async launch(opts: { port: number; shotDir: string; chrome?: string; width?: number; height?: number }) {
    const chrome =
      opts.chrome || process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
    mkdirSync(opts.shotDir, { recursive: true });

    const proc = Bun.spawn(
      [
        chrome,
        "--headless=new",
        "--no-sandbox",
        "--disable-gpu",
        "--disable-dev-shm-usage",
        `--user-data-dir=/tmp/e2e-userinfo-${opts.port}`,
        `--remote-debugging-port=${opts.port}`,
        "--remote-allow-origins=*",
        `--window-size=${opts.width || 1600},${opts.height || 1200}`,
        "--force-device-scale-factor=1",
      ],
      { stdout: "pipe", stderr: "pipe" }
    );

    let version: any = null;
    for (let i = 0; i < 50; i++) {
      try {
        const r = await fetch(`http://127.0.0.1:${opts.port}/json/version`);
        if (r.ok) {
          version = await r.json();
          break;
        }
      } catch {}
      await Bun.sleep(400);
    }
    if (!version) throw new Error("chromium did not expose CDP");

    const c = new CDP();
    c.shotDir = opts.shotDir;
    c.ws = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise<void>((res, rej) => {
      c.ws.onopen = () => res();
      c.ws.onerror = (e) => rej(new Error(String(e)));
    });
    c.ws.onmessage = (ev) => {
      const msg = JSON.parse(String(ev.data));
      if (msg.id != null) {
        const p = c.pending.get(msg.id);
        if (p) {
          c.pending.delete(msg.id);
          msg.error ? p.rej(new Error(JSON.stringify(msg.error))) : p.res(msg.result);
        }
      } else if (msg.method) {
        (c.handlers.get(msg.method) || []).forEach((fn) => fn(msg.params));
      }
    };

    const { targetId } = await c.send("Target.createTarget", { url: "about:blank" });
    const { sessionId } = await c.send("Target.attachToTarget", { targetId, flatten: true });
    c.sessionId = sessionId;

    await c.send("Page.enable");
    await c.send("Runtime.enable");
    await c.send("Network.enable");
    // Present a real UA: the analytics view-capture middleware filters obvious
    // bots, and a headless UA gets excluded by our own filter.
    await c.send("Network.setUserAgentOverride", {
      userAgent:
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    });

    return { cdp: c, proc, targetId };
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
      }, 45000);
    });
  }

  async eval(expr: string, awaitPromise = false) {
    const r = await this.send("Runtime.evaluate", {
      expression: expr,
      awaitPromise,
      returnByValue: true,
      timeout: 40000,
    });
    if (r.exceptionDetails) throw new Error(`eval: ${r.exceptionDetails.text}`);
    return r.result?.value;
  }

  async viewport(width: number, height: number, mobile = false) {
    await this.send("Emulation.setDeviceMetricsOverride", {
      width,
      height,
      deviceScaleFactor: 1,
      mobile,
      screenWidth: width,
      screenHeight: height,
    });
  }

  /**
   * Navigate and wait for the SPA to actually boot.
   *
   * Waiting for `load` is not enough — Flarum mounts after it — and waiting for
   * `window.flarum` is not enough either, because the author panel is injected
   * by a separate <script> that binds after app.boot(). So the wait is for the
   * marker that script sets, with a bounded fallback so a page that legitimately
   * has no panel still returns.
   */
  async goto(url: string, waitFor?: string) {
    await this.send("Page.navigate", { url });
    const deadline = Date.now() + 30000;
    let booted = false;
    while (Date.now() < deadline) {
      await Bun.sleep(250);
      const ready = await this.eval(`!!(document.querySelector('#app') && window.flarum)`).catch(() => false);
      if (ready) {
        booted = true;
        break;
      }
    }
    if (!booted) return false;

    if (waitFor) {
      const d2 = Date.now() + 15000;
      while (Date.now() < d2) {
        const hit = await this.eval(`!!document.querySelector(${JSON.stringify(waitFor)})`).catch(() => false);
        if (hit) return true;
        await Bun.sleep(250);
      }
      return false;
    }
    await Bun.sleep(1200);
    return true;
  }

  async shot(name: string) {
    const r = await this.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
    if (r?.data) writeFileSync(`${this.shotDir}/${name}.png`, Buffer.from(r.data, "base64"));
    return `${this.shotDir}/${name}.png`;
  }

  /** Crop to one element, at 2x, so a screenshot can actually be read. */
  async shotOf(selector: string, name: string, pad = 8) {
    const box = await this.eval(
      `(() => { const e = document.querySelector(${JSON.stringify(selector)});
        if (!e) return null; const r = e.getBoundingClientRect();
        return JSON.stringify({x:r.x, y:r.y, w:r.width, h:r.height}); })()`
    );
    if (!box) return null;
    const b = JSON.parse(box);
    if (b.w < 2 || b.h < 2) return null;
    const r = await this.send("Page.captureScreenshot", {
      format: "png",
      captureBeyondViewport: true,
      clip: { x: Math.max(0, b.x - pad), y: Math.max(0, b.y - pad), width: b.w + pad * 2, height: b.h + pad * 2, scale: 2 },
    });
    if (r?.data) {
      writeFileSync(`${this.shotDir}/${name}.png`, Buffer.from(r.data, "base64"));
      return `${this.shotDir}/${name}.png`;
    }
    return null;
  }
}

export const sh = async (cmd: string[]) => {
  const p = Bun.spawn(cmd, { stdout: "pipe", stderr: "pipe" });
  await p.exited;
  return (await new Response(p.stdout).text()).trim();
};

/** Query the forum database directly, to assert on the rows behind the pixels. */
export const sql = async (q: string) => {
  const pass = (await sh(["sh", "-c", `grep '^DB_PASS' ${process.env.STACK_DIR || "/work/flarum"}/.env | cut -d= -f2`])).trim();
  return sh(["docker", "exec", "flarum-db", "mariadb", "-uflarum", "-p" + pass, "flarum", "-N", "-B", "-e", q]);
};
