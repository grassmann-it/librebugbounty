/* Run only against tests/Support/studio_review_browser_router.php's disposable fixture. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, request } = require('../../playwright-worker/node_modules/playwright');
const origin = new URL(process.env.STUDIO_BROWSER_BASE || 'http://web:8098').origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-review-queue';
assert.ok(['web', 'localhost', '127.0.0.1'].includes(new URL(origin).hostname));

(async () => {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const api = await request.newContext();
  const contexts = [], outside = [], errors = [], checks = [];
  const snapshot = async () => {
    const response = await api.get(origin + '/__studio_review_fixture');
    assert.equal(response.status(), 200);
    const value = await response.json(); assert.equal(value.isolated, true); return value;
  };
  const context = async (options = {}) => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block', ...options });
    contexts.push(ctx); ctx.setDefaultTimeout(15000);
    await ctx.route('**/*', route => {
      if (new URL(route.request().url()).origin === origin) return route.continue();
      outside.push(route.request().url()); return route.abort();
    });
    ctx.on('page', page => page.on('pageerror', error => errors.push(error.message)));
    return ctx;
  };
  const id = page => page.locator('body').getAttribute('data-current-id');
  const idle = page => page.waitForFunction(() => document.body.dataset.reviewArriving !== 'true');
  const previews = page => page.waitForFunction(() => Number(document.body.dataset.reviewQueueReady) >= 2);
  const choose = async (page, selector, expected) => {
    await page.locator(selector).click();
    await page.waitForFunction(expected => document.body.dataset.currentId === expected, expected);
    await idle(page);
  };
  let page, release = () => {}, failure;
  try {
    const baseline = await snapshot(), f = baseline.fixtures;
    if (process.env.STUDIO_REVIEW_QUEUE_ONLY !== 'claims') {
    const ctx = await context(); page = await ctx.newPage();
    const requests = [];
    page.on('request', r => requests.push({ method: r.method(), url: r.url() }));
    await page.goto(origin + '/review?images=ready');
    await page.waitForFunction(() => Number(document.body.dataset.reviewQueueReady) === 5);
    assert.equal(await id(page), f.main.id);
    assert.equal(await page.locator('[data-review-preloads]').evaluate(el => JSON.parse(el.dataset.reviewPreloads).length), 5);
    assert.equal(requests.filter(r => new URL(r.url).pathname === '/review').length, 1, 'Prefetch never opens a trail-changing /review GET');
    assert.equal(requests.filter(r => r.method === 'POST').length, 0, 'Prefetch never submits a decision');
    assert.deepEqual((await snapshot()).snapshot, baseline.snapshot, 'Preview/screenshot prefetch leaves saved state unchanged');
    checks.push('Five next cards and screenshots are prefetched with no case mutation or trail navigation.');

    const gate = new Promise(resolve => { release = resolve; });
    const posts = []; let active = 0, maxActive = 0;
    await page.route('**/review/*/queued-assessment', async route => {
      const findingId = new URL(route.request().url()).pathname.split('/')[2];
      posts.push(findingId); active++; maxActive = Math.max(maxActive, active);
      try {
        if (findingId === f.main.id) await gate;
        if (findingId === f.readyUnchecked.id) {
          const response = await route.fetch(); assert.equal(response.status(), 200);
          await route.abort('connectionreset');
        } else {
          const response = await route.fetch(); await route.fulfill({ response });
        }
      } finally { active--; }
    });
    await choose(page, '[data-review-confirm]', f.readyError.id);
    assert.equal(await page.locator('body').getAttribute('data-review-pending'), '1');
    assert.deepEqual(posts, [f.main.id]);
    assert.deepEqual((await snapshot()).snapshot, baseline.snapshot, 'Next card is usable while first write is held before commit');
    assert.equal(await page.locator('[data-review-save-queue]').isVisible(), true);
    assert.equal(await page.evaluate(() => {
      const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented;
    }), true, 'Leaving with queued writes has a native warning');
    await page.locator('[data-review-form] [name="queued_token"]').evaluate(input => { input.value = 'invalid-queued-token'; });
    await choose(page, '[data-review-fixed]', f.readyUnchecked.id);
    await choose(page, '[data-review-confirm]', f.readyNoJsFixed.id);
    await choose(page, '[data-review-fixed]', f.readyNoJsConfirmed.id);
    assert.equal(await page.locator('body').getAttribute('data-review-pending'), '4');
    assert.deepEqual(posts, [f.main.id], 'Only one mutation is sent while later decisions wait');
    await page.screenshot({ path: path.join(output, 'four-pending-desktop.png') });
    release();
    await page.waitForFunction(() => document.body.dataset.reviewPending === '0', null, { timeout: 60000 });
    assert.equal(maxActive, 1, 'Assessment writes are serialized');
    assert.deepEqual(posts, [f.main.id, f.readyError.id, f.readyUnchecked.id, f.readyNoJsFixed.id], 'Failure never blocks subsequent queued writes');
    await page.waitForTimeout(350);
    assert.equal(posts.length, 4, 'A lost response is never automatically retried');
    assert.match(await page.locator('[data-review-save-queue-message]').innerText(), /2.*nicht bestätigt/);
    const saved = (await snapshot()).snapshot;
    const assessment = findingId => saved.finding_assessment.filter(row => row.finding_id === findingId);
    assert.equal(assessment(f.main.id).at(-1)?.assessment, 'confirmed');
    assert.equal(assessment(f.readyError.id).length, 0, 'Rejected save leaves its case unassessed');
    assert.equal(assessment(f.readyUnchecked.id).at(-1)?.assessment, 'confirmed', 'Lost receipt may follow a successful commit');
    assert.equal(assessment(f.readyNoJsFixed.id).at(-1)?.assessment, 'fixed');
    const openCases = await page.request.get(origin + '/review?images=ready');
    assert.match(await openCases.text(), new RegExp(`data-current-id="${f.readyError.id}"`), 'Failed case stays in the authoritative review queue');
    checks.push('Four rapid decisions advance before save, serialize strictly, continue after rejection, and never duplicate a lost-response commit.');

    await page.setViewportSize({ width: 720, height: 900 });
    const toast = await page.locator('[data-review-save-queue]').boundingBox();
    assert.ok(toast.x >= 0 && toast.x + toast.width <= 721, 'Failure toast fits half-screen width');
    await page.screenshot({ path: path.join(output, 'failure-half-screen.png') });
    await choose(page, '[data-review-back]', f.readyNoJsFixed.id);
    const reset = (await snapshot()).snapshot.finding_assessment_reset.filter(row => row.finding_id === f.readyNoJsFixed.id);
    assert.equal(reset.length, 1, 'Back uses fresh audited trail and resets the last successful queued assessment');
    checks.push('Back drains and refreshes native trail credentials before resetting the last successful review.');

    await page.reload();
    assert.match(await page.locator('[data-review-save-queue-message]').innerText(), /2.*nicht bestätigt/, 'Unconfirmed outcomes survive reload');
    assert.equal(await page.evaluate(() => {
      const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented;
    }), false, 'No leave warning remains after the queue drains');
    await page.locator('[data-review-save-queue] button').click();
    assert.equal(await page.locator('[data-review-save-queue]').isVisible(), false);
    checks.push('Unconfirmed outcomes remain visible across reload without replay; dismissed feedback does not block further review.');

    await previews(page);
    await choose(page, '[data-review-confirm]', f.readyNoJsConfirmed.id);
    await page.waitForFunction(() => document.body.dataset.reviewPending === '0', null, { timeout: 60000 });
    assert.equal((await snapshot()).snapshot.finding_assessment.filter(row => row.finding_id === f.readyNoJsFixed.id).at(-1)?.assessment, 'confirmed', 'Native Back invalidates old preview generation; freshly loaded queue tokens save successfully');
    await page.locator('[data-review-open-recovery]').first().click();
    await page.locator('[data-review-defer-form] [name="reason"]').selectOption('evidence');
    await page.locator('[data-review-defer-form] [name="note"]').fill('Queued review defer fixture');
    await page.locator('[data-review-defer-form] [name="schedule"]').selectOption('manual');
    const deferredRequest = page.waitForRequest(r => r.method() === 'POST' && new URL(r.url()).pathname.endsWith('/defer'));
    await choose(page, '[data-review-defer-form] button[type="submit"]', f.readyTouchConfirm.id);
    assert.match((await deferredRequest).postData(), /Queued review defer fixture/, 'Defer preserves the captured note while refreshing its own CSRF/trail fields');
    checks.push('A fresh queue still saves after native Back, and Defer synchronizes its own credentials while retaining reason, note and schedule.');

    const brokenContext = await context();
    page = await brokenContext.newPage();
    await page.goto(origin + '/review?images=ready');
    await previews(page);
    let brokenPosts = 0;
    await page.route('**/review/*/queued-assessment', async route => {
      brokenPosts++;
      const operationId = route.request().postData().match(/name="operation_id"\r\n\r\n([^\r]+)/)?.[1];
      assert.ok(operationId);
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, id: new URL(route.request().url()).pathname.split('/')[2], operationId, outcome: 'saved' }) });
    });
    await page.evaluate(() => window.LibreReviewQueue.update(document.body, { advance: () => { throw new Error('Synthetic install failure'); } }));
    await page.locator('[data-review-confirm]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-confirm]').disabled);
    await page.keyboard.press('ArrowRight');
    await page.evaluate(() => document.querySelector('[data-review-form]').requestSubmit(document.querySelector('[data-review-confirm]')));
    await page.waitForTimeout(350);
    assert.equal(brokenPosts, 1, 'Rendering failure blocks both buttons and keyboard from duplicating the original decision');
    assert.equal(await page.locator('[data-review-save-queue-recovery]').isVisible(), true);
    checks.push('A failed next-card installation locks the original decision and exposes recovery without duplicate writes.');
    }

    assert.ok(f.claimSelection?.claimObservationId, 'Queue suite requires STUDIO_CLAIM_SELECTION_FIXTURE=1');
    const mixedContext = await context(); page = await mixedContext.newPage();
    await page.goto(`${origin}/review?images=ready&after=${f.legacyManual.id}`);
    assert.equal(await id(page), f.claimSelection.id);
    await previews(page);
    const mixedBefore = (await snapshot()).snapshot;
    const mixedGate = new Promise(resolve => { release = resolve; });
    let mixedPosts = 0;
    await page.route('**/review/*/queued-assessment', async route => {
      mixedPosts++;
      if (mixedPosts === 1) await mixedGate;
      await route.continue();
    });
    await page.locator('input[name="claim_selection[error_disclosure][selected]"]').uncheck();
    await page.locator('input[name="claim_selection[component:sql_error_observation][selected]"]').uncheck();
    await choose(page, '[data-review-confirm]', f.claimSelectionKeyboard.id);
    assert.equal(await page.locator('input[name="claim_selection[error_disclosure][selected]"]').isChecked(), true, 'Next case keeps its own initial selection');
    await page.locator('input[name="claim_selection[xss][revision]"]').evaluate(input => { input.value = '999999'; });
    await choose(page, '[data-review-confirm]', f.claimSelectionSwipe.id);
    assert.equal(mixedPosts, 1);
    release();
    await page.waitForFunction(() => document.body.dataset.reviewPending === '0', null, { timeout: 60000 });
    const mixedSaved = (await snapshot()).snapshot;
    assert.ok(mixedSaved.finding_claim.some(row => row.finding_id === f.claimSelection.id && row.claim === 'error_disclosure' && row.state === 'rejected'), 'Queued write preserves the captured claim deselection');
    assert.ok(mixedSaved.cribrum_review_selection.some(row => row.finding_id === f.claimSelection.id && row.option_key === 'component:sql_error_observation' && Number(row.selected) === 0), 'Queued write preserves the captured component deselection');
    assert.deepEqual(mixedSaved.finding_claim.filter(row => row.finding_id === f.claimSelectionKeyboard.id), mixedBefore.finding_claim.filter(row => row.finding_id === f.claimSelectionKeyboard.id), 'Stale revision leaves all claims of the rejected queued case unchanged');
    assert.equal(mixedSaved.finding_assessment.filter(row => row.finding_id === f.claimSelectionKeyboard.id).length, 0);
    assert.match(await page.locator('[data-review-save-queue-message]').innerText(), /1.*nicht bestätigt/);
    const mixedOpen = await page.request.get(`${origin}/review?images=ready&after=${f.legacyManual.id}`);
    assert.match(await mixedOpen.text(), new RegExp(`data-current-id="${f.claimSelectionKeyboard.id}"`));
    checks.push('Queued claim selections are immutable snapshots; stale revisions leave claims unchanged and the case in the open review queue with feedback.');

    assert.deepEqual(outside, [], 'No live targets contacted');
    assert.deepEqual(errors, [], 'No browser errors');
    const end = (await snapshot()).snapshot;
    assert.deepEqual(end.screenshot_job, baseline.snapshot.screenshot_job);
    assert.deepEqual(end.retest_run, baseline.snapshot.retest_run);
  } catch (error) {
    failure = error;
    if (page && !page.isClosed()) await page.screenshot({ path: path.join(output, 'failure.png') }).catch(() => {});
  } finally {
    release();
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ success: !failure, checks, errors, outside, error: failure?.stack }, null, 2));
    if (page) await page.unrouteAll({ behavior: 'ignoreErrors' });
    await Promise.all(contexts.map(ctx => ctx.close())); await api.dispose(); await browser.close();
  }
  if (failure) throw failure;
  console.log(JSON.stringify({ success: true, checks, output }, null, 2));
})().catch(error => { console.error(error); process.exitCode = 1; });
