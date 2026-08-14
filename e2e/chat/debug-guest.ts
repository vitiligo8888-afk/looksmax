/** Does the card mount for a logged-out viewer on the current build? */
import { BASE, fresh, sleep, watchErrors } from "./lib.ts";

const b = await fresh(21971);
const errors = watchErrors(b);
await b.goto(BASE + "/");
for (let i = 0; i < 6; i++) {
  await sleep(2000);
  console.log(i, JSON.stringify(await b.eval(`({
    side: !!document.querySelector('.LmxIndex-side'),
    card: !!document.querySelector('.LmxChat'),
    inSide: !!document.querySelector('.LmxIndex-side .LmxChat'),
    probe: !!window.__lmxChat,
    state: window.__lmxChat ? window.__lmxChat.state() : null,
    polls: window.__lmxChat ? window.__lmxChat.polls.length : -1,
  })`)));
}
console.log("errors:", JSON.stringify(errors));
await b.close();
