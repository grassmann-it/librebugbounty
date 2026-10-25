#!/usr/bin/env node
/**
 * Repeatable live lifecycle smoke test for the headed Playwright worker.
 *
 * Intended run location: inside the `playwright-interactive` service:
 *
 *     PLAYWRIGHT_WORKER_TOKEN=<token> node interactive-smoke.cjs
 *
 * Environment:
 *   PLAYWRIGHT_WORKER_TOKEN       required; sent as Bearer token, never printed
 *   PLAYWRIGHT_WORKER_URL         optional; default http://127.0.0.1:3000
 *   INTERACTIVE_SMOKE_TIMEOUT_MS  optional overall budget; default 180000
 *
 * Safety and scope:
 *   - The only target is a loopback HTTP fixture started by this script: a
 *     benign challenge-looking page with a Continue button that opens a normal
 *     page. No external target, no exploit, no dialog, no assessment changes.
 *   - Every HTTP request is time-bounded and the whole run shares one deadline.
 *   - Output never contains the worker token, session IDs or page content.
 *   - A live session this script did not create is never touched: a 409 on the
 *     first start aborts gracefully without sending any close request.
 *   - Only session IDs created by this run are ever closed, in a finally block.
 *
 * Checks in order (13): public health, unauthenticated mutation 401,
 * generation-bound authorized start 201, second start 409 (single shared
 * desktop session),
 * status 200, live frame PNG, bounded click input, bounded type input, capture
 * PNG + provenance, session retained + input locked + identical second capture,
 * close 200, idempotent second close 200, closed status 404, unknown-id close
 * 404. Close semantics follow the worker contract: close is idempotent for a
 * recent tombstone (200), unknown IDs return 404.
 *
 * Exit codes: 0 pass, 1 check failure, 2 environment/usage problem,
 * 3 worker busy with a foreign session (left untouched).
 */
'use strict';

const http = require('node:http');
const https = require('node:https');
const { randomBytes } = require('node:crypto');

// ---- configuration -------------------------------------------------------

const DEFAULT_BASE_URL = 'http://127.0.0.1:3000';
const DEFAULT_BUDGET_MS = 180000;
const MIN_BUDGET_MS = 30000;
const MAX_BUDGET_MS = 900000;
const REQUEST_TIMEOUT_MS = 20000;
const START_REQUEST_TIMEOUT_MS = 45000;
const CAPTURE_REQUEST_TIMEOUT_MS = 30000;
const CLOSE_REQUEST_TIMEOUT_MS = 15000;
const WORKER_NAV_TIMEOUT_MS = 15000;
const FRAME_ATTEMPTS = 3;
const POLL_ATTEMPTS = 12;
const POLL_INTERVAL_MS = 250;
const MAX_RESPONSE_BYTES = 32 * 1024 * 1024;
const TOTAL_CHECKS = 13;
const SESSION_TTL_MS = 20 * 60 * 1000;
const SESSION_ID_PATTERN = /^[a-f0-9]{48}$/;
const SMOKE_GENERATION = 1;
const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

// Center of the fixed Continue button in the challenge fixture (viewport is
// 1440x900, so these coordinates are inside the worker's click bounds).
const CLICK = { x: 720, y: 430 };
const TYPE_TEXT = 'smoke-ok';

// ---- errors and tiny helpers --------------------------------------------

class SmokeError extends Error {
  constructor(message, exitCode = 1) {
    super(message);
    this.name = 'SmokeError';
    this.exitCode = exitCode;
  }
}

class PreconditionError extends SmokeError {
  constructor(message) {
    super(message, 2);
    this.name = 'PreconditionError';
  }
}

class BusyError extends SmokeError {
  constructor(message) {
    super(message, 3);
    this.name = 'BusyError';
  }
}

function fail(message) {
  throw new SmokeError(message);
}

function expect(condition, message) {
  if (!condition) fail(message);
}

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Strip anything that could identify a session and cap the length so error
// output stays concise and free of tokens, IDs and target content.
function sanitize(value) {
  let text = String(value);
  const token = process.env.PLAYWRIGHT_WORKER_TOKEN;
  if (token) text = text.split(token).join('<redacted>');
  return text
    .replace(/[a-f0-9]{48}/gi, '<session-id>')
    .replace(/Bearer\s+\S+/gi, 'Bearer <redacted>')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 160);
}

function formatBytes(bytes) {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MiB`;
  return `${(bytes / 1024).toFixed(1)} KiB`;
}

function safeOrigin(rawUrl) {
  const url = new URL(rawUrl);
  url.username = '';
  url.password = '';
  return url.origin;
}

// ---- environment ---------------------------------------------------------

function readToken() {
  const token = process.env.PLAYWRIGHT_WORKER_TOKEN || '';
  if (!token) {
    throw new PreconditionError('PLAYWRIGHT_WORKER_TOKEN is required (it is never printed).');
  }
  if (!/^[\x21-\x7e]+$/.test(token)) {
    throw new PreconditionError('PLAYWRIGHT_WORKER_TOKEN must be visible ASCII without spaces.');
  }
  return token;
}

function readBaseUrl() {
  const raw = process.env.PLAYWRIGHT_WORKER_URL || DEFAULT_BASE_URL;
  let url;
  try {
    url = new URL(raw);
  } catch {
    throw new PreconditionError('PLAYWRIGHT_WORKER_URL is not a valid URL.');
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new PreconditionError('PLAYWRIGHT_WORKER_URL must use http or https.');
  }
  return url.origin;
}

function readBudgetMs() {
  const raw = process.env.INTERACTIVE_SMOKE_TIMEOUT_MS;
  if (raw === undefined || raw === '') return DEFAULT_BUDGET_MS;
  const value = Number(raw);
  if (!Number.isFinite(value)) {
    throw new PreconditionError('INTERACTIVE_SMOKE_TIMEOUT_MS must be a number of milliseconds.');
  }
  return Math.min(MAX_BUDGET_MS, Math.max(MIN_BUDGET_MS, Math.round(value)));
}

// ---- local loopback fixture ---------------------------------------------

const CHALLENGE_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Checking your browser</title>
<style>
  body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; }
  .card { position: fixed; left: 320px; top: 120px; width: 800px; padding: 32px;
          background: #fff; border: 1px solid #d8dee6; border-radius: 12px; box-sizing: border-box; }
  h1 { font-size: 28px; margin: 0 0 16px; }
  p { line-height: 1.5; margin: 0 0 12px; }
  button { position: fixed; left: 620px; top: 400px; width: 200px; height: 60px;
           font-size: 18px; cursor: pointer; }
</style>
</head>
<body>
  <div class="card">
    <h1>Checking your browser</h1>
    <p>Verifying you are human before you continue.</p>
    <p>This is a local, harmless smoke-test fixture that only imitates a verification interstitial.</p>
  </div>
  <form action="/normal" method="get">
    <button type="submit">Continue</button>
  </form>
</body>
</html>`;

const NORMAL_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Interactive smoke target</title>
<style>
  body { margin: 0; font-family: Arial, sans-serif; background: #f8fafc; color: #111827; }
  main { padding: 40px; }
  input { font-size: 16px; padding: 8px; width: 320px; }
</style>
</head>
<body>
  <main>
    <h1>Normal page</h1>
    <p>Interactive smoke test reached the target page.</p>
    <label>Message <input id="message" autofocus></label>
  </main>
</body>
</html>`;

function sendHtml(res, html) {
  const body = Buffer.from(html, 'utf8');
  res.writeHead(200, {
    'Content-Type': 'text/html; charset=utf-8',
    'Content-Length': String(body.length),
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

function startFixture() {
  return new Promise((resolve, reject) => {
    const server = http.createServer((req, res) => {
      let pathname;
      try {
        pathname = new URL(req.url, 'http://127.0.0.1').pathname;
      } catch {
        res.writeHead(400, { 'Content-Type': 'text/plain' });
        res.end('bad request');
        return;
      }
      if (req.method !== 'GET' && req.method !== 'HEAD') {
        res.writeHead(405, { 'Content-Type': 'text/plain', Allow: 'GET, HEAD' });
        res.end('method not allowed');
        return;
      }
      if (pathname === '/' || pathname === '/challenge') {
        sendHtml(res, CHALLENGE_HTML);
        return;
      }
      if (pathname === '/normal') {
        sendHtml(res, NORMAL_HTML);
        return;
      }
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('not found');
    });

    server.once('error', reject);
    // Loopback only: the headed Chromium runs in this same container.
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      resolve({
        server,
        port,
        origin: `http://127.0.0.1:${port}`,
        challengeUrl: `http://127.0.0.1:${port}/challenge`,
        normalUrl: `http://127.0.0.1:${port}/normal`,
      });
    });
  });
}

function stopFixture(fixture) {
  return new Promise((resolve) => {
    if (!fixture) {
      resolve();
      return;
    }
    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      clearTimeout(watchdog);
      resolve();
    };
    // Chromium may hold keep-alive sockets; never let cleanup hang on them.
    const watchdog = setTimeout(finish, 5000);
    watchdog.unref();
    fixture.server.close(finish);
    if (typeof fixture.server.closeAllConnections === 'function') {
      fixture.server.closeAllConnections();
    } else if (typeof fixture.server.closeIdleConnections === 'function') {
      fixture.server.closeIdleConnections();
    }
  });
}

// ---- bounded worker HTTP client -----------------------------------------

function createWorkerClient({ baseUrl, token, deadlineAt, pendingRequests }) {
  return function callWorker(pathname, options = {}) {
    const {
      method = 'GET',
      body,
      auth = true,
      timeoutMs = REQUEST_TIMEOUT_MS,
    } = options;

    return new Promise((resolve, reject) => {
      const remainingMs = deadlineAt - Date.now();
      if (remainingMs <= 0) {
        reject(new SmokeError('overall execution budget exhausted'));
        return;
      }

      const target = new URL(pathname, baseUrl);
      const transport = target.protocol === 'https:' ? https : http;
      const payload = body === undefined
        ? null
        : Buffer.from(JSON.stringify(body), 'utf8');
      const effectiveTimeoutMs = Math.max(1, Math.min(timeoutMs, remainingMs));

      let settled = false;
      const settle = (callback, value) => {
        if (settled) return;
        settled = true;
        pendingRequests.delete(req);
        callback(value);
      };

      const req = transport.request(target, {
        method,
        agent: false,
        headers: {
          Accept: 'application/json, image/png',
          ...(payload
            ? { 'Content-Type': 'application/json', 'Content-Length': String(payload.length) }
            : {}),
          ...(auth && token ? { Authorization: `Bearer ${token}` } : {}),
        },
      }, (res) => {
        const chunks = [];
        let size = 0;
        res.on('data', (chunk) => {
          size += chunk.length;
          if (size > MAX_RESPONSE_BYTES) {
            req.destroy(new SmokeError(`worker response exceeds ${MAX_RESPONSE_BYTES} bytes`));
            return;
          }
          chunks.push(chunk);
        });
        res.on('error', (error) => settle(reject, new SmokeError(`worker response failed: ${error.message}`)));
        res.on('end', () => settle(resolve, {
          status: res.statusCode,
          headers: res.headers,
          body: Buffer.concat(chunks),
        }));
      });

      pendingRequests.add(req);
      req.setTimeout(effectiveTimeoutMs, () => {
        req.destroy(new SmokeError(`worker request timed out after ${effectiveTimeoutMs}ms`));
      });
      req.on('error', (error) => {
        settle(reject, error instanceof SmokeError
          ? error
          : new SmokeError(`worker request failed: ${error.message}`));
      });

      if (payload) req.write(payload);
      req.end();
    });
  };
}

// ---- response helpers ----------------------------------------------------

function workerMessage(res) {
  try {
    const payload = JSON.parse(res.body.toString('utf8'));
    const message = payload && typeof payload.errorMessage === 'string' ? payload.errorMessage : '';
    return message ? ` - worker: ${sanitize(message)}` : '';
  } catch {
    return '';
  }
}

function expectStatus(res, expected, label) {
  if (res.status !== expected) {
    fail(`${label}: expected HTTP ${expected}, got ${res.status}${workerMessage(res)}`);
  }
}

function readJson(res, label) {
  try {
    return JSON.parse(res.body.toString('utf8'));
  } catch {
    return fail(`${label}: response body is not valid JSON`);
  }
}

function inspectPng(buffer, label) {
  expect(Buffer.isBuffer(buffer) && buffer.length >= 100, `${label}: PNG payload is too short`);
  expect(buffer.subarray(0, 8).equals(PNG_SIGNATURE), `${label}: missing PNG signature`);
  expect(buffer.toString('latin1', 12, 16) === 'IHDR', `${label}: missing IHDR chunk`);
  const width = buffer.readUInt32BE(16);
  const height = buffer.readUInt32BE(20);
  expect(width >= 320 && width <= 4096, `${label}: implausible PNG width ${width}`);
  expect(height >= 200 && height <= 4096, `${label}: implausible PNG height ${height}`);
  expect(buffer.toString('latin1', buffer.length - 8, buffer.length - 4) === 'IEND', `${label}: missing IEND chunk`);
  return { width, height, bytes: buffer.length };
}

function decodePngBase64(value, label) {
  expect(typeof value === 'string' && value.length > 0, `${label}: missing screenshotBase64`);
  expect(value.length % 4 === 0, `${label}: base64 length is not a multiple of 4`);
  expect(/^[A-Za-z0-9+/]+={0,2}$/.test(value), `${label}: screenshotBase64 is not base64`);
  return inspectPng(Buffer.from(value, 'base64'), label);
}

function expectChallenge(challenge, label) {
  expect(challenge && typeof challenge === 'object', `${label}: missing challenge object`);
  expect(['detected', 'cleared', 'unknown'].includes(challenge.state), `${label}: invalid state`);
  expect(challenge.active === true || challenge.active === false || challenge.active === null,
    `${label}: invalid active flag`);
  expect(challenge.snapshot && typeof challenge.snapshot === 'object', `${label}: missing snapshot`);
}

function isNormalPageUrl(value, fixture) {
  if (typeof value !== 'string') return false;
  try {
    const target = new URL(value);
    const expected = new URL(fixture.normalUrl);
    return target.origin === expected.origin && target.pathname === expected.pathname;
  } catch {
    return false;
  }
}

function expectSessionView(view, label) {
  expect(view && typeof view === 'object', `${label}: missing session view`);
  expect(typeof view.id === 'string' && SESSION_ID_PATTERN.test(view.id), `${label}: malformed session id`);
  const createdAt = Date.parse(view.createdAt);
  const expiresAt = Date.parse(view.expiresAt);
  expect(Number.isFinite(createdAt), `${label}: invalid createdAt`);
  expect(Number.isFinite(expiresAt), `${label}: invalid expiresAt`);
  expect(Math.abs((expiresAt - createdAt) - SESSION_TTL_MS) < 1000, `${label}: unexpected session TTL`);
  expect(typeof view.initialUrl === 'string' && view.initialUrl.length > 0, `${label}: missing initialUrl`);
  expect(typeof view.finalUrl === 'string' && view.finalUrl.length > 0, `${label}: missing finalUrl`);
  expect(view.httpStatus === null || typeof view.httpStatus === 'number', `${label}: invalid httpStatus`);
  expect(view.navigationError === null || typeof view.navigationError === 'string',
    `${label}: invalid navigationError`);
  expectChallenge(view.initialChallenge, `${label}.initialChallenge`);
  expectChallenge(view.challenge, `${label}.challenge`);
}

// ---- smoke run -----------------------------------------------------------

async function run() {
  const token = readToken();
  const baseUrl = readBaseUrl();
  const budgetMs = readBudgetMs();
  const startedAt = Date.now();
  const deadlineAt = startedAt + budgetMs;
  const pendingRequests = new Set();
  const callWorker = createWorkerClient({ baseUrl, token, deadlineAt, pendingRequests });

  const state = {
    sessionId: null,
    capture: null,
    fixture: null,
    cleanedUp: false,
  };
  // Only session IDs created by this run are ever closed.
  const ownedSessionIds = new Set();

  let stepNo = 0;
  async function step(name, fn) {
    stepNo += 1;
    const prefix = `ok  ${String(stepNo).padStart(2, '0')} ${name}`;
    try {
      const detail = await fn();
      console.log(detail ? `${prefix} - ${detail}` : prefix);
    } catch (error) {
      console.log(`FAIL ${String(stepNo).padStart(2, '0')} ${name} - ${sanitize(error.message)}`);
      throw error;
    }
  }

  const sessionPath = (operation) => {
    expect(typeof state.sessionId === 'string', `no session id for ${operation}`);
    return `/interactive-sessions/${state.sessionId}/${operation}`;
  };

  async function cleanup() {
    if (state.cleanedUp) return;
    state.cleanedUp = true;

    // Cleanup must work even when the main budget already expired: give it its
    // own short, still-bounded window so an expired run cannot leak the browser
    // session it created.
    const cleanupClient = createWorkerClient({
      baseUrl,
      token,
      deadlineAt: Date.now() + 2 * CLOSE_REQUEST_TIMEOUT_MS + 5000,
      pendingRequests,
    });
    let closed = 0;
    for (const id of ownedSessionIds) {
      try {
        await cleanupClient(`/interactive-sessions/${id}/close`, {
          method: 'POST',
          body: { generation: SMOKE_GENERATION },
          timeoutMs: CLOSE_REQUEST_TIMEOUT_MS,
        });
        closed += 1;
      } catch {
        // Best effort: the worker's 20-minute TTL and sweeper are the backstop.
      }
    }
    ownedSessionIds.clear();
    if (closed > 0) console.log(`cleanup: closed ${closed} session(s) created by this run`);

    for (const req of pendingRequests) req.destroy();
    pendingRequests.clear();
    await stopFixture(state.fixture);
    state.fixture = null;
  }
  cleanupHook = cleanup;

  // Never let a wedged request or socket exceed the budget by much.
  const watchdog = setTimeout(() => {
    for (const req of pendingRequests) {
      req.destroy(new SmokeError('overall execution budget exhausted'));
    }
    if (state.fixture) {
      state.fixture.server.closeAllConnections?.();
    }
  }, Math.max(1, deadlineAt - Date.now() + 5000));
  watchdog.unref();

  try {
    state.fixture = await startFixture();
    console.log(`interactive-smoke: worker=${safeOrigin(baseUrl)} fixture=127.0.0.1:${state.fixture.port} budget=${Math.round(budgetMs / 1000)}s`);

    await step('health is public', async () => {
      let res;
      try {
        res = await callWorker('/health', { auth: false });
      } catch (error) {
        throw new PreconditionError(`worker unreachable at ${safeOrigin(baseUrl)} - ${sanitize(error.message)}`);
      }
      expectStatus(res, 200, 'GET /health');
      const payload = readJson(res, 'GET /health');
      expect(payload.ok === true, 'GET /health: expected {ok:true}');
      return '200 public';
    });

    await step('unauthenticated mutation is rejected', async () => {
      const res = await callWorker('/interactive-sessions', {
        method: 'POST',
        auth: false,
        body: { url: state.fixture.challengeUrl, timeoutMs: WORKER_NAV_TIMEOUT_MS },
      });
      if (res.status === 503) {
        throw new PreconditionError('worker mutations are disabled: PLAYWRIGHT_WORKER_TOKEN is not configured on the worker');
      }
      expectStatus(res, 401, 'POST /interactive-sessions without token');
      return '401 without token';
    });

    await step('authorized start creates one session', async () => {
      const res = await callWorker('/interactive-sessions', {
        method: 'POST',
        body: {
          url: state.fixture.challengeUrl,
          timeoutMs: WORKER_NAV_TIMEOUT_MS,
          generation: SMOKE_GENERATION,
        },
        timeoutMs: START_REQUEST_TIMEOUT_MS,
      });
      if (res.status === 409) {
        throw new BusyError('worker already has a live interactive session (created elsewhere); it was left untouched');
      }
      expectStatus(res, 201, 'POST /interactive-sessions');
      const view = readJson(res, 'POST /interactive-sessions');
      expect(typeof view.id === 'string', 'POST /interactive-sessions: missing session id');
      ownedSessionIds.add(view.id);
      state.sessionId = view.id;
      expectSessionView(view, 'start view');
      expect(view.generation === SMOKE_GENERATION, 'start view: generation mismatch');
      expect(view.initialUrl === state.fixture.challengeUrl, 'start view: initialUrl does not match the fixture');
      expect(view.initialChallenge.state === 'detected' && view.initialChallenge.active === true,
        'start view: fixture challenge page was not detected as protection');
      return '201 session created';
    });

    await step('second start is rejected while the session is live', async () => {
      const res = await callWorker('/interactive-sessions', {
        method: 'POST',
        body: {
          url: state.fixture.challengeUrl,
          timeoutMs: WORKER_NAV_TIMEOUT_MS,
          generation: SMOKE_GENERATION,
        },
        timeoutMs: START_REQUEST_TIMEOUT_MS,
      });
      if (res.status === 201) {
        // Defensive: never leak a session even if capacity semantics change.
        const view = readJson(res, 'second POST /interactive-sessions');
        if (typeof view.id === 'string') ownedSessionIds.add(view.id);
        fail('second POST /interactive-sessions: expected 409, got 201 (worker admitted a second session)');
      }
      expectStatus(res, 409, 'second POST /interactive-sessions');
      return '409 capacity reached';
    });

    await step('session status is live', async () => {
      const res = await callWorker(sessionPath('status'));
      expectStatus(res, 200, 'GET status');
      const view = readJson(res, 'GET status');
      expectSessionView(view, 'status view');
      expect(view.id === state.sessionId, 'status view: session id changed');
      expect(view.initialUrl === state.fixture.challengeUrl, 'status view: initialUrl mismatch');
      expect(view.finalUrl === state.fixture.challengeUrl, 'status view: unexpected finalUrl before input');
      expect(view.httpStatus === 200, `status view: expected fixture httpStatus 200, got ${String(view.httpStatus)}`);
      expect(view.navigationError === null, 'status view: unexpected navigationError');
      expect(view.initialChallenge.state === 'detected' && view.initialChallenge.active === true,
        'status view: initial challenge provenance lost');
      return '200 live';
    });

    await step('live frame is a plausible PNG', async () => {
      let res;
      for (let attempt = 0; attempt < FRAME_ATTEMPTS; attempt += 1) {
        res = await callWorker(sessionPath('frame'));
        if (res.status === 200 || attempt === FRAME_ATTEMPTS - 1) break;
        await delay(POLL_INTERVAL_MS);
      }
      expectStatus(res, 200, 'GET frame');
      expect(String(res.headers['content-type'] || '').startsWith('image/png'), 'GET frame: content type is not image/png');
      const png = inspectPng(res.body, 'GET frame');
      return `200 ${png.width}x${png.height} ${formatBytes(png.bytes)}`;
    });

    await step('bounded click input reaches the normal page', async () => {
      const res = await callWorker(sessionPath('input'), {
        method: 'POST',
        body: { action: 'click', x: CLICK.x, y: CLICK.y, generation: SMOKE_GENERATION },
      });
      expectStatus(res, 200, 'POST input click');
      let view = readJson(res, 'POST input click');
      expectSessionView(view, 'click view');
      // The fixture navigation is local; poll briefly instead of assuming it
      // settled inside the worker's fixed post-input wait. A challenge
      // inspection can also transiently report unknown mid-navigation.
      const settledOnTarget = (candidate) => isNormalPageUrl(candidate.finalUrl, state.fixture)
        && candidate.challenge.state === 'cleared'
        && candidate.challenge.active === false;
      for (let attempt = 0; attempt < POLL_ATTEMPTS && !settledOnTarget(view); attempt += 1) {
        await delay(POLL_INTERVAL_MS);
        const poll = await callWorker(sessionPath('status'));
        expectStatus(poll, 200, 'GET status after click');
        view = readJson(poll, 'GET status after click');
        expectSessionView(view, 'status view after click');
      }
      expect(isNormalPageUrl(view.finalUrl, state.fixture),
        `click input: did not navigate to the normal fixture page (last path ${
          new URL(view.finalUrl).pathname
        }, challenge ${String(view.challenge.state)})`);
      expect(view.challenge.state === 'cleared' && view.challenge.active === false,
        'click input: normal page was not reported as cleared');
      return `200 clicked ${CLICK.x},${CLICK.y}`;
    });

    await step('bounded type input is accepted', async () => {
      const res = await callWorker(sessionPath('input'), {
        method: 'POST',
        body: { action: 'type', text: TYPE_TEXT, generation: SMOKE_GENERATION },
      });
      expectStatus(res, 200, 'POST input type');
      const view = readJson(res, 'POST input type');
      expectSessionView(view, 'type view');
      expect(isNormalPageUrl(view.finalUrl, state.fixture), 'type input: session left the normal page');
      return '200 typed';
    });

    await step('capture returns PNG and provenance', async () => {
      const res = await callWorker(sessionPath('capture'), {
        method: 'POST',
        body: { generation: SMOKE_GENERATION, expectedCandidateId: null },
        timeoutMs: CAPTURE_REQUEST_TIMEOUT_MS,
      });
      expectStatus(res, 200, 'POST capture');
      const result = readJson(res, 'POST capture');
      state.capture = res.body;

      const png = decodePngBase64(result.screenshotBase64, 'capture');
      expect(Number.isFinite(Date.parse(result.capturedAt)), 'capture: invalid capturedAt');
      expect(result.httpStatus === 200, `capture: expected httpStatus 200, got ${String(result.httpStatus)}`);
      expect(result.dialogSeen === false, 'capture: unexpected dialog observation');
      expect(result.captureMethod === 'interactive-desktop-page', `capture: unexpected captureMethod ${String(result.captureMethod)}`);
      expect(isNormalPageUrl(result.finalUrl, state.fixture), 'capture: finalUrl is not the normal page');

      const meta = result.metadata;
      expect(meta && typeof meta === 'object', 'capture: missing metadata');
      expect(meta.interactive === true, 'capture: metadata.interactive is not true');
      expect(meta.manual === true, 'capture: metadata.manual is not true');
      expect(meta.contextMode === 'fresh-retry', 'capture: metadata.contextMode is not fresh-retry');
      expect(meta.browserName === 'chromium', 'capture: metadata.browserName is not chromium');
      expect(meta.initialUrl === state.fixture.challengeUrl, 'capture: metadata.initialUrl mismatch');
      expect(isNormalPageUrl(meta.sourceURL, state.fixture), 'capture: metadata.sourceURL mismatch');
      expect(isNormalPageUrl(meta.finalUrl, state.fixture), 'capture: metadata.finalUrl mismatch');
      expect(['current-page', 'retained-dialog'].includes(meta.captureSelection), 'capture: invalid captureSelection');
      expect(Number.isFinite(Date.parse(meta.captureInspectionAt)), 'capture: invalid captureInspectionAt');
      expect(meta.navigationError === null, 'capture: unexpected navigationError');

      expectChallenge(meta.initialChallenge, 'capture metadata.initialChallenge');
      expectChallenge(meta.currentChallenge, 'capture metadata.currentChallenge');
      expect(meta.initialChallenge.state === 'detected' && meta.initialChallenge.active === true,
        'capture: initial challenge provenance is not the fixture interstitial');
      expect(meta.currentChallenge.state === 'cleared' && meta.currentChallenge.active === false,
        'capture: current challenge provenance is not the cleared target page');
      expect(meta.initialChallengeState === meta.initialChallenge.state, 'capture: initialChallengeState inconsistent');
      expect(meta.currentChallengeState === meta.currentChallenge.state, 'capture: currentChallengeState inconsistent');
      expect(meta.initialChallengeDetected === true, 'capture: initialChallengeDetected is not true');
      expect(meta.challengeDetected === false, 'capture: challengeDetected is not false');
      expect(meta.challengeCleared === true, 'capture: challengeCleared is not true');

      for (const key of ['initialHttp', 'currentHttp', 'captureInspectionHttp']) {
        const entry = meta[key];
        expect(entry && typeof entry === 'object', `capture: missing metadata.${key}`);
        expect('url' in entry && 'status' in entry && 'cfMitigated' in entry,
          `capture: metadata.${key} lacks url/status/cfMitigated`);
      }
      expect(meta.initialHttp.status === 200, 'capture: initialHttp.status is not 200');
      expect(meta.currentHttp.status === 200, 'capture: currentHttp.status is not 200');

      return `200 ${png.width}x${png.height} ${formatBytes(png.bytes)} provenance ok`;
    });

    await step('capture keeps session, locks input, repeats identically', async () => {
      const status = await callWorker(sessionPath('status'));
      expectStatus(status, 200, 'GET status after capture');

      const locked = await callWorker(sessionPath('input'), {
        method: 'POST',
        body: { action: 'key', key: 'Escape', generation: SMOKE_GENERATION },
      });
      expectStatus(locked, 409, 'POST input after capture');

      const repeat = await callWorker(sessionPath('capture'), {
        method: 'POST',
        body: { generation: SMOKE_GENERATION, expectedCandidateId: null },
        timeoutMs: CAPTURE_REQUEST_TIMEOUT_MS,
      });
      expectStatus(repeat, 200, 'second POST capture');
      expect(repeat.body.equals(state.capture), 'second capture: result is not byte-identical');
      return '200 live, input 409, capture identical';
    });

    await step('close succeeds and is idempotent', async () => {
      const first = await callWorker(sessionPath('close'), {
        method: 'POST',
        body: { generation: SMOKE_GENERATION },
        timeoutMs: CLOSE_REQUEST_TIMEOUT_MS,
      });
      expectStatus(first, 200, 'POST close');
      const firstBody = readJson(first, 'POST close');
      expect(firstBody.closed === true, 'POST close: expected closed:true');
      expect(firstBody.id === state.sessionId, 'POST close: response id mismatch');
      ownedSessionIds.delete(state.sessionId);

      const second = await callWorker(sessionPath('close'), {
        method: 'POST',
        body: { generation: SMOKE_GENERATION },
        timeoutMs: CLOSE_REQUEST_TIMEOUT_MS,
      });
      expectStatus(second, 200, 'second POST close');
      const secondBody = readJson(second, 'second POST close');
      expect(secondBody.closed === true && secondBody.id === state.sessionId,
        'second POST close: tombstone retry is not idempotent');
      return '200 closed, 200 on retry';
    });

    await step('status after close is 404', async () => {
      const res = await callWorker(sessionPath('status'));
      expectStatus(res, 404, 'GET status after close');
      return '404 closed session';
    });

    await step('unknown session close is 404', async () => {
      const unknownId = randomBytes(24).toString('hex');
      const res = await callWorker(`/interactive-sessions/${unknownId}/close`, {
        method: 'POST',
        body: { generation: SMOKE_GENERATION },
        timeoutMs: CLOSE_REQUEST_TIMEOUT_MS,
      });
      expectStatus(res, 404, 'POST close for unknown session');
      return '404 unknown session';
    });

    const elapsedSeconds = ((Date.now() - startedAt) / 1000).toFixed(1);
    console.log(`interactive-smoke: PASS ${TOTAL_CHECKS}/${TOTAL_CHECKS} checks in ${elapsedSeconds}s`);
  } finally {
    clearTimeout(watchdog);
    await cleanup();
  }
}

// Signal handlers still release our session and the fixture before exiting.
let cleanupHook = null;
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    const exitCode = signal === 'SIGINT' ? 130 : 143;
    Promise.resolve(cleanupHook ? cleanupHook() : null).finally(() => process.exit(exitCode));
  });
}

run()
  .then(() => {
    process.exitCode = 0;
  })
  .catch((error) => {
    const exitCode = error instanceof SmokeError ? error.exitCode : 1;
    const label = exitCode === 3 ? 'ABORTED' : exitCode === 2 ? 'CANNOT RUN' : 'FAILED';
    const detail = error instanceof BusyError
      ? `${sanitize(error.message)} (no close request was sent for it)`
      : sanitize(error && error.message ? error.message : error);
    console.error(`interactive-smoke: ${label} - ${detail} (exit ${exitCode})`);
    process.exitCode = exitCode;
  });
