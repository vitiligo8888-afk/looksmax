/**
 * "Uncaught (in promise)" with no stack is not a diagnosis. This captures the
 * full CDP exceptionDetails plus an in-page unhandledrejection listener, so the
 * three console errors the smoke run reported can be attributed to a file and a
 * line instead of guessed at.
 */
import { Browser, BASE, ADMIN_USER, ADMIN_PASS, say, sleep } from "./lib.ts";

const b = await Browser.launch(21921);
b.on("Runtime.exceptionThrown", (p: any) => {
  const d = p.exceptionDetails || {};
  console.log("EXCEPTION", JSON.stringify({
    text: d.text,
    url: d.url,
    line: d.lineNumber,
    desc: d.exception?.description?.slice(0, 400),
    value: d.exception?.value,
    frames: (d.stackTrace?.callFrames || []).slice(0, 6)
      .map((f: any) => `${f.functionName || "(anon)"} ${f.url}:${f.lineNumber}`),
  }, null, 1));
});

console.log("login:", await b.login(ADMIN_USER, ADMIN_PASS, BASE));
await b.goto(BASE + "/");
await b.eval(`window.__lmxRej = [];
  addEventListener('unhandledrejection', e => {
    window.__lmxRej.push(String((e.reason && (e.reason.stack || e.reason.message)) || e.reason));
  }); 1`);

await say(b, "debug " + Date.now());
await sleep(6000);
await b.eval(`(() => { const a = document.querySelector('.LmxIndex .LmxForum-name'); if (a) a.click(); })()`);
await sleep(3000);
await b.eval(`history.back()`);
await sleep(4000);

console.log("in-page rejections:", JSON.stringify(await b.eval(`window.__lmxRej`), null, 1));
console.log("collected:", JSON.stringify(b.consoleErrors));
await b.close();
