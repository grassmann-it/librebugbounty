/* Run only with run_review.py's STUDIO_REVIEW_ONLY=preloads disposable fixture. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, request } = require('../../playwright-worker/node_modules/playwright');
const origin = new URL(process.env.STUDIO_BROWSER_BASE || 'http://127.0.0.1:8098').origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-review-preload-settings';
assert.ok(['web', 'localhost', '127.0.0.1'].includes(new URL(origin).hostname));

(async () => {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const api = await request.newContext();
  const contexts = [], outside = [], errors = [], checks = [];
  const snapshot = async () => {
    const response = await api.get(origin + '/__studio_review_fixture');
    assert.equal(response.status(), 200);
    const result = await response.json();
    assert.equal(result.isolated, true);
    assert.ok(result.fixtures._expect.preloadOrder.length >= 40, 'Use the dedicated large preload fixture');
    return result;
  };
  const context = async () => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
    contexts.push(ctx);
    ctx.setDefaultTimeout(30000);
    await ctx.route('**/*', route => {
      if (new URL(route.request().url()).origin === origin) return route.continue();
      outside.push(route.request().url()); return route.abort();
    });
    ctx.on('page', page => page.on('pageerror', error => errors.push(error.message)));
    await ctx.addInitScript(() => {
      const decode = HTMLImageElement.prototype.decode;
      window.__preloadDecoded = [];
      HTMLImageElement.prototype.decode = function () {
        const source = this.currentSrc || this.src;
        return decode.call(this).then(value => { window.__preloadDecoded.push(source); return value; });
      };
    });
    return ctx;
  };
  const closeContext = async ctx => {
    for (const page of ctx.pages()) await page.unrouteAll({ behavior: 'ignoreErrors' });
    await ctx.close();
  };
  const saveCount = async (page, value) => {
    await page.goto(origin + '/settings');
    await page.locator('[name="review_preload_count"]').fill(String(value));
    await Promise.all([page.waitForNavigation(), page.locator('.studio-settings-actions button[type="submit"]').click()]);
    assert.equal(await page.locator('[name="review_preload_count"]').inputValue(), String(value));
  };
  const waitCards = (page, expected) => page.waitForFunction(expected => Number(document.body.dataset.reviewQueueReady) === expected, expected);
  const hints = page => page.locator('[data-review-preloads]').evaluate(element => JSON.parse(element.dataset.reviewPreloads));
  const decoded = async (page, urls) => {
    await page.waitForFunction(urls => urls.every(url => window.__preloadDecoded.includes(new URL(url, location.href).href)), urls, { timeout: 45000 });
  };
  const choose = async (page, expected) => {
    await page.locator('[data-review-confirm]').click();
    await page.waitForFunction(expected => document.body.dataset.currentId === expected && document.body.dataset.reviewArriving !== 'true', expected);
  };
  let page, release = () => {}, failure;
  try {
    const baseline = await snapshot(), f = baseline.fixtures, order = f._expect.preloadOrder;
    const ctx = await context(); page = await ctx.newPage();
    await saveCount(page, 30);
    await page.reload();
    assert.equal(await page.locator('[name="review_preload_count"]').inputValue(), '30', 'Chosen count persists across reload');
    assert.equal(await page.locator('[name="review_preload_count"]').getAttribute('min'), '0');
    assert.equal(await page.locator('[name="review_preload_count"]').getAttribute('max'), '50');
    const downloads = new Map(), previewRequests = [];
    page.on('request', req => {
      const url = new URL(req.url());
      if (url.pathname.startsWith('/artifacts/')) downloads.set(req.url(), (downloads.get(req.url()) || 0) + 1);
      if (url.pathname === '/review/preview') previewRequests.push(req.url());
    });
    await page.goto(origin + '/review?images=ready');
    assert.equal(await page.locator('body').getAttribute('data-review-preload-count'), '30');
    assert.equal(await page.locator('body').getAttribute('data-current-id'), order[0]);
    const initialHints = await hints(page);
    assert.equal(initialHints.length, 30);
    await waitCards(page, 30);
    await decoded(page, initialHints.map(hint => hint.url));
    assert.ok(previewRequests.length >= 3, 'Large buffers are filled in bounded batches');
    assert.ok(previewRequests.every(url => Number(new URL(url).searchParams.get('limit')) <= 10), 'Every preview request stays within the server batch cap');
    assert.ok(initialHints.every(hint => downloads.get(new URL(hint.url, origin).href) === 1), 'Each upcoming stored image is downloaded once');
    assert.deepEqual((await snapshot()).snapshot, baseline.snapshot, 'Warming 30 cards and images changes no cases, evidence or jobs');
    checks.push('Configured 30 persists; 30 upcoming cards and images are ready in bounded batches without case mutation.');
    await page.screenshot({ path: path.join(output, 'thirty-ready.png') });

    const gate = new Promise(resolve => { release = resolve; });
    const posts = []; let active = 0, maxActive = 0;
    await page.route('**/review/*/queued-assessment', async route => {
      const findingId = new URL(route.request().url()).pathname.split('/')[2];
      posts.push(findingId); active++; maxActive = Math.max(maxActive, active);
      try {
        if (posts.length === 1) await gate;
        const response = await route.fetch(); await route.fulfill({ response });
      } finally { active--; }
    });
    const requestsBeforeRating = previewRequests.length;
    await choose(page, order[1]);
    await waitCards(page, 30);
    await decoded(page, (await hints(page)).map(hint => hint.url));
    assert.ok(previewRequests.length > requestsBeforeRating, 'First rating immediately refills while its POST is still held');
    assert.deepEqual(posts, [order[0]], 'First save has not been released');
    assert.equal(await page.locator('body').getAttribute('data-review-pending'), '1');
    assert.deepEqual((await snapshot()).snapshot, baseline.snapshot, 'The next case and refill are usable before the first commit');
    assert.equal(downloads.get(new URL(initialHints[0].url, origin).href), 1, 'The arriving first image reuses the warmed image node');
    await choose(page, order[2]);
    await choose(page, order[3]);
    await waitCards(page, 30);
    assert.deepEqual(posts, [order[0]], 'Later writes wait behind the first write');
    await page.screenshot({ path: path.join(output, 'refilled-three-pending.png') });
    release();
    await page.waitForFunction(() => document.body.dataset.reviewPending === '0');
    assert.equal(maxActive, 1);
    assert.deepEqual(posts, order.slice(0, 3));
    checks.push('First rating advances and refills before save; three rapid decisions remain strictly serialized.');

    const fillingContext = await context(), filling = await fillingContext.newPage();
    let releasePreview = () => {}, releaseFillingSave = () => {}, previewStarted;
    const previewStartedPromise = new Promise(resolve => { previewStarted = resolve; });
    const previewGate = new Promise(resolve => { releasePreview = resolve; });
    const fillingSaveGate = new Promise(resolve => { releaseFillingSave = resolve; });
    const fillingCursors = [];
    await filling.route('**/review/preview?*', async route => {
      fillingCursors.push(new URL(route.request().url()).searchParams.get('after'));
      if (fillingCursors.length === 2) { previewStarted(); await previewGate; }
      const response = await route.fetch(); await route.fulfill({ response });
    });
    await filling.route('**/review/*/queued-assessment', async route => {
      await fillingSaveGate;
      const response = await route.fetch(); await route.fulfill({ response });
    });
    try {
      await filling.goto(`${origin}/review?images=ready&after=${f.preload01.id}`);
      let startDeadline;
      try {
        await Promise.race([previewStartedPromise, new Promise((_, reject) => { startDeadline = setTimeout(() => reject(new Error('Second preview batch did not start')), 30000); })]);
      } finally { clearTimeout(startDeadline); }
      await choose(filling, f.preload03.id);
      releasePreview();
      await waitCards(filling, 30);
      assert.equal(new Set(fillingCursors).size, fillingCursors.length, 'An in-flight successful batch survives advance without refetching the same cursor');
      assert.equal(await filling.locator('body').getAttribute('data-review-pending'), '1');
      releaseFillingSave();
      await filling.waitForFunction(() => document.body.dataset.reviewPending === '0');
      checks.push('Review can advance after the first small batch while the next batch is in flight, without discarding or duplicating that refill.');
    } finally {
      releasePreview(); releaseFillingSave();
      await closeContext(fillingContext);
    }

    const agingContext = await context();
    await agingContext.addInitScript(() => {
      const clock = Date.now;
      window.__preloadClockOffset = 0;
      Date.now = () => clock() + window.__preloadClockOffset;
    });
    const aging = await agingContext.newPage(), agingCursors = [];
    let releaseAging = () => {}, agingStarted;
    const agingStartedPromise = new Promise(resolve => { agingStarted = resolve; });
    const agingGate = new Promise(resolve => { releaseAging = resolve; });
    await aging.route('**/review/preview?*', async route => {
      agingCursors.push(new URL(route.request().url()).searchParams.get('after'));
      if (agingCursors.length === 2) { agingStarted(); await agingGate; }
      const response = await route.fetch(); await route.fulfill({ response });
    });
    try {
      await aging.goto(`${origin}/review?images=ready&after=${f.preload04.id}`);
      let agingDeadline;
      try {
        await Promise.race([agingStartedPromise, new Promise((_, reject) => { agingDeadline = setTimeout(() => reject(new Error('Aging batch did not start')), 30000); })]);
      } finally { clearTimeout(agingDeadline); }
      await aging.evaluate(() => { window.__preloadClockOffset = 30000; });
      releaseAging();
      await waitCards(aging, 30);
      const current = await aging.locator('body').getAttribute('data-current-id');
      const restarted = aging.waitForRequest(req => new URL(req.url()).pathname === '/review/preview' && new URL(req.url()).searchParams.get('after') === current);
      await aging.evaluate(() => { window.__preloadClockOffset = 61000; document.dispatchEvent(new Event('visibilitychange')); });
      await restarted;
      await waitCards(aging, 30);
      assert.equal(agingCursors.filter(cursor => cursor === current).length, 2, 'Expired prefix restarts the full buffer at the current case');
      await choose(aging, f.preload06.id);
      await aging.waitForFunction(() => document.body.dataset.reviewPending === '0');
      checks.push('Different-aged preview batches expire together; the earliest remaining case is never skipped.');
    } finally { releaseAging(); await closeContext(agingContext); }

    for (const corruption of ['html', 'duplicate', 'generation']) {
      const invalidContext = await context(), invalid = await invalidContext.newPage();
      let invalidRequests = 0;
      await invalid.route('**/review/preview?*', async route => {
        invalidRequests++;
        const response = await route.fetch();
        if (invalidRequests !== 1) { await route.fulfill({ response }); return; }
        const batch = await response.json();
        assert.ok(batch.cards.length >= 4);
        if (corruption === 'html') batch.cards[2].html = '<html><body>Invalid preview</body></html>';
        if (corruption === 'duplicate') batch.cards[2] = { ...batch.cards[1] };
        if (corruption === 'generation') batch.cards[2].html = batch.cards[2].html.replace(/(name="queued_card" value=")([^"]+)(")/, (_, before, encoded, after) => {
          const value = JSON.parse(Buffer.from(encoded, 'base64').toString());
          value.generation = 'unexpected-fixture-generation';
          return before + Buffer.from(JSON.stringify(value)).toString('base64') + after;
        });
        await route.fulfill({ response, body: JSON.stringify(batch) });
      });
      const firstInvalid = invalid.waitForResponse(res => new URL(res.url()).pathname === '/review/preview');
      await invalid.goto(`${origin}/review?images=ready&after=${f.preload07.id}`);
      await firstInvalid;
      await invalid.waitForTimeout(800);
      assert.equal(await invalid.locator('body').getAttribute('data-review-queue-ready'), '0', `${corruption}: no partial batch may enter the queue`);
      assert.equal(invalidRequests, 1, `${corruption}: invalid batch does not retry in a loop`);
      if (corruption === 'generation') {
        await choose(invalid, f.preload09.id);
        await waitCards(invalid, 30);
      }
      await closeContext(invalidContext);
    }
    checks.push('Malformed HTML, duplicate IDs and wrong generations reject the whole batch; native review can recover without a polling loop.');

    await saveCount(page, 1);
    const smallContext = await context(), small = await smallContext.newPage();
    await small.goto(origin + '/review?images=ready');
    await waitCards(small, 1);
    assert.equal((await hints(small)).length, 1);
    await small.waitForTimeout(500);
    assert.equal(await small.locator('body').getAttribute('data-review-queue-ready'), '1', 'Configured one does not grow toward the former default');
    checks.push('A one-card buffer obeys its configured capacity.');
    await closeContext(smallContext);

    await saveCount(page, 0);
    const disabledContext = await context(), disabled = await disabledContext.newPage();
    const disabledRequests = [];
    disabled.on('request', req => disabledRequests.push({ path: new URL(req.url()).pathname, method: req.method() }));
    await disabled.goto(origin + '/review?images=ready');
    assert.equal(await disabled.locator('body').getAttribute('data-review-preload-count'), '0');
    assert.deepEqual(await hints(disabled), []);
    await disabled.waitForTimeout(700);
    assert.equal(disabledRequests.filter(req => req.path === '/review/preview').length, 0, 'Zero disables card speculation');
    assert.equal(await disabled.evaluate(() => window.__preloadDecoded.length), 0, 'Zero disables speculative image decoding');
    await choose(disabled, order[4]);
    assert.equal(disabledRequests.filter(req => req.method === 'POST' && req.path.endsWith('/assessment')).length, 1, 'Disabled preload keeps the native assessment path');
    assert.equal(disabledRequests.filter(req => req.path.endsWith('/queued-assessment')).length, 0);
    checks.push('Zero disables card/image speculation while native assessment still saves and advances.');
    await closeContext(disabledContext);

    await saveCount(page, 30);
    const endContext = await context(), end = await endContext.newPage();
    const endRequests = [];
    end.on('request', req => { if (new URL(req.url()).pathname === '/review/preview') endRequests.push(req.url()); });
    await end.goto(`${origin}/review?images=ready&after=${f.preload37.id}`);
    assert.equal(await end.locator('body').getAttribute('data-current-id'), f.preload38.id);
    await waitCards(end, 1);
    await end.waitForTimeout(1200);
    assert.equal(endRequests.length, 1, 'Exhausted queue stops instead of repeatedly trying to fill 30');
    await closeContext(endContext);

    const emptyContext = await context(), empty = await emptyContext.newPage();
    let emptyRequests = 0;
    await empty.route('**/review/preview?*', async route => {
      emptyRequests++;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ cards: [], exhausted: false }) });
    });
    const emptyRequest = empty.waitForRequest(req => new URL(req.url()).pathname === '/review/preview');
    await empty.goto(origin + '/review?images=ready');
    await emptyRequest;
    await empty.waitForTimeout(1200);
    assert.equal(emptyRequests, 1, 'A response without progress does not produce a request loop');
    checks.push('Exhausted and no-progress preview responses stop bounded refill without polling loops.');
    assert.deepEqual(outside, []);
    assert.deepEqual(errors, []);
    const final = (await snapshot()).snapshot;
    assert.deepEqual(final.screenshot_job, baseline.snapshot.screenshot_job);
    assert.deepEqual(final.retest_run, baseline.snapshot.retest_run);
    assert.deepEqual(final.evidence, baseline.snapshot.evidence);
  } catch (error) {
    failure = error;
    if (page && !page.isClosed()) await page.screenshot({ path: path.join(output, 'failure.png') }).catch(() => {});
  } finally {
    release();
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ success: !failure, checks, errors, outside, error: failure?.stack }, null, 2));
    for (const ctx of contexts) await closeContext(ctx);
    await api.dispose(); await browser.close();
  }
  if (failure) throw failure;
  console.log(`PASS ${checks.length} configurable preload scenarios; no outside requests or background target jobs.`);
})();
