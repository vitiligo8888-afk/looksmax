/**
 * Baseline: record what the shipped shoutbox actually does, before it changes.
 *
 * Everything here is a measurement, not an assertion — the point is a number to
 * compare against, including the duplicate-render defect.
 */
import { Browser, BASE, ADMIN_USER, ADMIN_PASS, SHOTS, apiState, shotEl, cycles, count, logText, login } from "./lib.ts";

const out = `${SHOTS}/baseline`;

const b = await Browser.launch(21901);
console.log("BASE", BASE);

// logged out first
await b.goto(BASE + "/");
console.log("logged-out api:", JSON.stringify(await apiState(b)));
await cycles(3);
console.log("logged-out .LmxChat-msg count:", await count(b));
console.log("logged-out card present:", await b.eval(`!!document.querySelector('.LmxChat')`));
console.log("shot out:", await shotEl(b, ".LmxChat", `${out}/logged-out.png`));

const who = await login(b, ADMIN_USER, ADMIN_PASS);
console.log("login:", who);

await b.goto(BASE + "/");
b.resetNetwork();
const api = await apiState(b);
console.log("api:", JSON.stringify(api));

for (let i = 1; i <= 4; i++) {
  await cycles(1);
  console.log(`after ${i} cycle(s): DOM=${await count(b)} vs API=${api.n}`);
}

console.log("log text:\n" + (await logText(b)));
console.log("shot in:", await shotEl(b, ".LmxChat", `${out}/logged-in.png`));
await b.shot(`${out}/page.png`);

console.log("console errors:", JSON.stringify(b.consoleErrors.slice(0, 10)));
console.log("bad responses:", JSON.stringify(b.badResponses.slice(0, 10)));
console.log("failed requests:", JSON.stringify(b.failedRequests.slice(0, 10)));

await b.close();
