/** Who is 300px wide on a 420px phone — the chat card, or every sidebar card? */
import { BASE, fresh, cycles } from "./lib.ts";

const b = await fresh(21981);
await b.viewport({ name: "mobile", width: 420, height: 844, mobile: true });
await b.goto(BASE + "/");
await cycles(1);
console.log(await b.eval(`(() => {
  const side = document.querySelector('.LmxIndex-side');
  const rows = [...side.children].map(n => ({
    cls: n.className,
    w: Math.round(n.getBoundingClientRect().width),
    cssW: getComputedStyle(n).width,
    maxW: getComputedStyle(n).maxWidth,
    alignSelf: getComputedStyle(n).alignSelf,
  }));
  return JSON.stringify({
    sideW: Math.round(side.getBoundingClientRect().width),
    sideAlign: getComputedStyle(side).alignItems,
    inner: window.innerWidth,
    rows,
  }, null, 1);
})()`));
await b.close();
