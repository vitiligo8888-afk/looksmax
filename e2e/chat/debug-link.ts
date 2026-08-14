/** Why the URL in a seeded message did not become a link. */
import { Browser, BASE, sql, cycles, fresh } from "./lib.ts";

console.log(await sql(`SELECT id, username, body FROM chat_messages ORDER BY id DESC LIMIT 8`));

const b = await fresh(21951);
await b.goto(BASE + "/");
await cycles(1);
console.log(await b.eval(`(() => {
  const rows = [...document.querySelectorAll('.LmxChat-msg')].map(n => ({
    who: n.querySelector('.LmxChat-who')?.textContent,
    html: n.querySelector('.LmxChat-text')?.innerHTML?.slice(0, 200),
  }));
  return JSON.stringify(rows, null, 1);
})()`));
console.log("links:", await b.eval(`document.querySelectorAll('.LmxChat-link').length`));
await b.close();
