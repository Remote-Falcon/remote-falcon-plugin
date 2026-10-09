// Tests for js/listener_status.js (Listener Status panel text).
// Run: node --test tests/js/
const test = require('node:test');
const assert = require('node:assert');
const { formatAgo, capitalize, buildListenerStatusLines } = require('../../js/listener_status.js');

const NOW = 1000000;

function status(fields) {
  return { ok: true, now: NOW, status: { updatedAt: NOW - 5, ...fields } };
}

function texts(lines) {
  return lines.map((l) => l.text);
}

test('formatAgo picks the unit at each boundary', () => {
  assert.strictEqual(formatAgo(0), '0s ago');
  assert.strictEqual(formatAgo(59), '59s ago');
  assert.strictEqual(formatAgo(60), '1 min ago');
  assert.strictEqual(formatAgo(3599), '59 min ago');
  assert.strictEqual(formatAgo(3600), '1 h ago');
  assert.strictEqual(formatAgo(86399), '23 h ago');
  assert.strictEqual(formatAgo(86400), '1 days ago');
});

test('formatAgo returns empty for missing or future times', () => {
  assert.strictEqual(formatAgo(null), '');
  assert.strictEqual(formatAgo(undefined), '');
  assert.strictEqual(formatAgo(-5), '');
});

test('capitalize', () => {
  assert.strictEqual(capitalize('voting'), 'Voting');
  assert.strictEqual(capitalize(''), '');
});

test('stopped listener and missing token come before anything else', () => {
  assert.deepStrictEqual(buildListenerStatusLines(status({}), true, false)[0].level, 'warn');
  assert.match(buildListenerStatusLines(status({}), true, false)[0].text, /listener is stopped/);
  assert.match(buildListenerStatusLines(status({}), false, true)[0].text, /Show Token/);
});

test('no status file yet is neutral, not a warning', () => {
  const lines = buildListenerStatusLines({ ok: false, error: 'no_status', now: NOW }, true, true);
  assert.strictEqual(lines.length, 1);
  assert.strictEqual(lines[0].level, '');
  assert.match(lines[0].text, /No status yet/);
});

test('a confirmed mode is shown as ok', () => {
  const lines = buildListenerStatusLines(status({ viewerControlMode: 'voting', modeConfirmed: true, modeCheckedAt: NOW - 130 }), true, true);
  assert.strictEqual(lines[0].level, 'ok');
  assert.strictEqual(lines[0].text, 'Viewer control mode: Voting (checked with Remote Falcon 2 min ago)');
});

test('an unread mode warns, with a singular for one attempt', () => {
  const one = buildListenerStatusLines(status({ viewerControlMode: 'jukebox', modeConfirmed: false, modeError: { at: NOW, failures: 1 } }), true, true);
  assert.strictEqual(one[0].level, 'warn');
  assert.match(one[0].text, /\(1 attempt\)\. Using Jukebox/);
  const many = buildListenerStatusLines(status({ viewerControlMode: 'jukebox', modeConfirmed: false, modeError: { at: NOW, failures: 4 } }), true, true);
  assert.match(many[0].text, /\(4 attempts\)/);
});

test('fetch results: song, nothing waiting, and unreachable', () => {
  const got = texts(buildListenerStatusLines(status({ lastFetch: { at: NOW - 40, kind: 'vote', sequence: 'Your Idol', error: false } }), true, true));
  assert.ok(got.includes('Last vote check (40s ago): got "Your Idol".'));

  const none = texts(buildListenerStatusLines(status({ lastFetch: { at: NOW - 40, kind: 'request', sequence: null, error: false } }), true, true));
  assert.ok(none.includes('Last request check (40s ago): no requests waiting.'));

  const err = buildListenerStatusLines(status({ lastFetch: { at: NOW - 40, kind: 'vote', sequence: null, error: true } }), true, true);
  const errLine = err.find((l) => l.text.startsWith('Last vote check'));
  assert.strictEqual(errLine.level, 'warn');
  assert.match(errLine.text, /could not reach Remote Falcon/);
});

test('a rejected FPP insert and a playlist warning are flagged', () => {
  const lines = buildListenerStatusLines(status({
    lastInsert: { at: NOW - 60, sequence: 'Monster', ok: false },
    remotePlaylistWarning: "WARNING - Remote playlist 'Show' was not found in FPP.",
  }), true, true);
  const insert = lines.find((l) => l.text.startsWith('FPP did not accept'));
  assert.strictEqual(insert.level, 'warn');
  const playlist = lines.find((l) => l.text.startsWith('Remote playlist'));
  assert.strictEqual(playlist.text, "Remote playlist 'Show' was not found in FPP.");
  assert.strictEqual(playlist.level, 'warn');
});

test('stale status warns only past 90 seconds', () => {
  const fresh = buildListenerStatusLines({ ok: true, now: NOW, status: { updatedAt: NOW - 90 } }, true, true);
  assert.ok(!fresh.some((l) => /has not updated/.test(l.text)));
  const stale = buildListenerStatusLines({ ok: true, now: NOW, status: { updatedAt: NOW - 91 } }, true, true);
  assert.strictEqual(stale[0].level, 'warn');
  assert.match(stale[0].text, /has not updated its status for 1 min/);
});

test('heartbeat ok and failed', () => {
  const ok = texts(buildListenerStatusLines(status({ lastHeartbeat: { at: NOW - 12, ok: true } }), true, true));
  assert.ok(ok.includes('Heartbeat to Remote Falcon: sent 12s ago.'));
  const failed = buildListenerStatusLines(status({ lastHeartbeat: { at: NOW - 12, ok: false } }), true, true);
  assert.strictEqual(failed[failed.length - 1].level, 'warn');
});
