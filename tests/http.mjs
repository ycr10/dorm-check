// Run against an isolated empty development instance: node tests/http.mjs [base URL].
import assert from 'node:assert/strict';
import fs from 'node:fs';
const base = process.argv[2] || 'http://127.0.0.1:8765';
let cookie = ''; let csrf = ''; const results = [];
async function request(action, { method = 'GET', body, origin = base, token = csrf, type = 'application/json' } = {}) {
  const headers = { Cookie: cookie, Accept: 'application/json' };
  if (method === 'POST') { if (type) headers['Content-Type'] = type; if (origin) headers.Origin = origin; if (token) headers['X-CSRF-Token'] = token; }
  const response = await fetch(`${base}/api/index.php?action=${action}`, { method, headers, body: method === 'POST' ? (typeof body === 'string' ? body : JSON.stringify(body || {})) : undefined, redirect: 'manual' });
  const setCookie = response.headers.getSetCookie(); if (setCookie.length) cookie = setCookie.map(c => c.split(';')[0]).join('; ');
  const text = await response.text(); let data; try { data = JSON.parse(text); } catch { throw new Error(`Non-JSON API response (${response.status}): ${text.slice(0, 300)}`); }
  if (data.csrf) csrf = data.csrf;
  return { response, data, text, setCookie };
}
async function test(name, work) { try { await work(); results.push({ name, ok: true }); } catch (error) { results.push({ name, ok: false, error: error.message }); } }
await test('public page and local assets render with security headers', async () => {
  const response = await fetch(base); assert.equal(response.status, 200); assert.match(await response.text(), /归寝/); assert.match(response.headers.get('content-security-policy'), /frame-ancestors 'none'/);
  for (const [asset, type] of [['app.js', /javascript/], ['app.css', /css/], ['enhancements.css', /css/]]) { const r = await fetch(`${base}/assets/${asset}`); assert.equal(r.status, 200); assert.match(r.headers.get('content-type'), type); }
});
await test('anonymous session issues private CSRF and HttpOnly SameSite cookie', async () => {
  const r = await request('session'); assert.equal(r.response.status, 200); assert.equal(r.data.data.user, null); assert.match(csrf, /^[a-f0-9]{64}$/); assert.match(r.response.headers.get('cache-control'), /no-store/); assert.match(r.setCookie.join(';'), /HttpOnly/i); assert.match(r.setCookie.join(';'), /SameSite=Lax/i);
});
await test('wrong methods never perform state changes', async () => {
  const r = await request('login'); assert.equal(r.response.status, 405); assert.equal(r.response.headers.get('allow'), 'POST');
  const s = await request('session', { method: 'POST' }); assert.equal(s.response.status, 405);
});
await test('missing CSRF is rejected before login processing', async () => {
  const r = await request('login', { method: 'POST', token: '', body: { student_no: 'unknown', password: 'wrong' } }); assert.equal(r.response.status, 403); assert.equal(r.data.error.code, 'CSRF_INVALID');
});
await test('cross-site writes are rejected even with a valid CSRF token', async () => {
  const r = await request('login', { method: 'POST', origin: 'https://other.invalid', body: {} }); assert.equal(r.response.status, 403); assert.equal(r.data.error.code, 'ORIGIN_INVALID');
});
await test('non-JSON and malformed JSON fail with controlled errors', async () => {
  const r = await request('login', { method: 'POST', type: 'text/plain', body: '{}' }); assert.equal(r.response.status, 415);
  const bad = await request('login', { method: 'POST', body: '{broken' }); assert.equal(bad.response.status, 400); assert.equal(bad.data.error.code, 'INVALID_JSON');
  const array = await request('login', { method: 'POST', body: '[]' }); assert.equal(array.response.status, 400);
});
await test('authenticated reads and mutations reject an anonymous client', async () => {
  for (const action of ['tasks', 'dashboard', 'roster', 'reports', 'audit', 'task_history']) { const r = await request(action); assert.equal(r.response.status, 401, action); assert.equal(r.data.error.code, 'UNAUTHENTICATED'); }
  const r = await request('report_initial', { method: 'POST', body: { role: 'admin', now: '2099-01-01 23:00:00' } }); assert.equal(r.response.status, 401);
});
await test('failed login and activation do not disclose an account or stack trace', async () => {
  const r = await request('login', { method: 'POST', body: { student_no: 'not-real-0099', password: 'incorrect passphrase' } }); assert.equal(r.response.status, 401); assert.equal(r.data.error.code, 'LOGIN_FAILED'); assert.doesNotMatch(r.text, /password_hash|Stack trace|PDOException/);
  const a = await request('activate', { method: 'POST', body: { student_no: 'not-real-0099', code: 'wrong', password: 'a long synthetic test passphrase' } }); assert.equal(a.response.status, 422); assert.equal(a.data.error.code, 'TOKEN_INVALID');
});
await test('private directories, arbitrary PHP and traversal paths are not served', async () => {
  for (const route of ['/config/example.php','/src/Core.php','/storage/test.sqlite','/bin/console.php','/tests/run.php','/router.php','/.gitignore','/assets/../../config/example.php','/%2e%2e/config/example.php']) { const response = await fetch(base + route); assert.equal(response.status, 404, route); }
});
await test('invalid query arrays and unknown action names are controlled', async () => {
  const r = await fetch(`${base}/api/index.php?action[]=session`); assert.equal(r.status, 404);
  const s = await request('unknown_action'); assert.equal(s.response.status, 404);
  const d = await fetch(`${base}/api/index.php?action=tasks&date[]=2026-10-29`); assert.equal(d.status, 400);
});
const report = { passed: results.filter(r => r.ok).length, failed: results.filter(r => !r.ok).length, scope: 'Unauthenticated real HTTP/session/security boundary only; not proof of successful Argon2id login or authenticated browser workflows.', results };
console.log(JSON.stringify(report, null, 2));
if (process.env.DORM_TEST_REPORT) fs.writeFileSync(process.env.DORM_TEST_REPORT, JSON.stringify(report, null, 2) + '\n');
process.exitCode = results.some(r => !r.ok) ? 1 : 0;
