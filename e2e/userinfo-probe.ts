#!/usr/bin/env bun
/**
 * Ask the running page what it actually exposes.
 *
 * Every component this extension binds to is reached through
 * `flarum.core.compat`, a runtime registry whose keys are built by the bundler
 * and are NOT greppable in the minified output. Guessing a key produces a
 * silent no-op: the script loads, binds nothing, and every post renders without
 * a panel with no error anywhere. That failure mode cost the icons lane an
 * afternoon, so the keys are measured from the live page instead.
 *
 *     FORUM_URL=http://127.0.0.1:8888 bun e2e/userinfo-probe.ts
 */
import { CDP } from "./userinfo-cdp";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const { cdp, proc } = await CDP.launch({ port: 21740, shotDir: "/work/flarum/userinfo-shots" });

await cdp.goto(BASE);

const out = await cdp.eval(`(() => {
  const c = (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  const keys = Object.keys(c);
  const want = keys.filter(k => /UserCard|UserPage|PostUser|CommentPost|models\\/User|Post$|extend|Avatar|username/i.test(k));
  const proto = (k) => { try { const M = c[k]; return M && M.prototype ? Object.getOwnPropertyNames(M.prototype).slice(0,40) : (M && M.default && M.default.prototype ? Object.getOwnPropertyNames(M.default.prototype).slice(0,40) : null); } catch(e){ return String(e); } };
  return JSON.stringify({
    total: keys.length,
    matched: want,
    CommentPost: proto('forum/components/CommentPost'),
    UserCard: proto('forum/components/UserCard'),
    UserPage: proto('forum/components/UserPage'),
    PostUser: proto('forum/components/PostUser'),
    UserModel: proto('common/models/User'),
    hasExtend: !!c['common/extend'],
    sampleUserAttrs: (() => { try { const u = c['forum/app'].store.all('users')[0]; return u ? Object.keys(u.data.attributes) : null; } catch(e) { return String(e); } })(),
  }, null, 2);
})()`);

console.log(out);
try { proc.kill(); } catch {}
process.exit(0);
