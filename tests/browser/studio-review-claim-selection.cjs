/* Isolated mixed finding only:
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-claim-selection-RUN STUDIO_CLAIM_SELECTION_FIXTURE=1 php tests/Support/studio_review_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-claim-selection-RUN STUDIO_CLAIM_SELECTION_FIXTURE=1 php -S 0.0.0.0:8098 -t public tests/Support/studio_review_browser_router.php
 *   docker exec -e STUDIO_BROWSER_BASE=http://web:8098 -e STUDIO_BROWSER_OUTPUT=/tmp/librebugbounty-review-claim-selection ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-review-claim-selection.cjs
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, request } = require('../../playwright-worker/node_modules/playwright');

const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8098';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-review-claim-selection';
assert.ok(['web', 'localhost', '127.0.0.1'].includes(new URL(base).hostname));

(async () => {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const contexts = [], outside = [], errors = [];
  const api = await request.newContext();
  const snapshot = async () => {
    const response = await api.get(origin + '/__studio_review_fixture');
    assert.equal(response.status(), 200);
    const data = await response.json();
    assert.equal(data.isolated, true);
    assert.ok(data.fixtures.claimSelection?.claimObservationId, 'Mixed fixture is required');
    return data;
  };
  const context = async (viewport, options = {}) => {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, serviceWorkers: 'block', ...options });
    contexts.push(ctx);
    ctx.setDefaultTimeout(15000);
    ctx.on('page', page => page.on('pageerror', error => errors.push(error.message)));
    await ctx.route('**/*', route => {
      if (new URL(route.request().url()).origin === origin) return route.continue();
      outside.push(route.request().url()); return route.abort();
    });
    return ctx;
  };
  const openCase = async (page, fixture, name = 'claimSelection', previous = 'legacyManual') => {
    await page.goto(`${origin}/review?images=ready&after=${fixture.fixtures[previous].id}`);
    assert.equal(await page.locator('body').getAttribute('data-current-id'), fixture.fixtures[name].id);
    await page.locator('[data-review-claims] input[type=checkbox]').first().waitFor();
  };
  const badge = (page, claim) => page.locator('[data-review-claim]').filter({ has: page.locator(`input[name="claim_selection[${claim}][selected]"]`) });
  const choice = (page, claim) => page.locator(`input[name="claim_selection[${claim}][selected]"]`);
  const assertVisibleBounds = async (page, selector) => {
    const bounds = await page.locator(selector).evaluate(element => {
      const r = element.getBoundingClientRect();
      return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height, viewportWidth: innerWidth, viewportHeight: innerHeight };
    });
    assert.ok(bounds.width > 0 && bounds.height > 0, `${selector} has size`);
    assert.ok(bounds.x >= -1 && bounds.right <= bounds.viewportWidth + 1, `${selector} fits horizontally: ${JSON.stringify(bounds)}`);
    assert.ok(bounds.y >= -1 && bounds.bottom <= bounds.viewportHeight + 1, `${selector} fits vertically: ${JSON.stringify(bounds)}`);
  };
  const assertDockContained = async page => {
    const bounds = await page.locator('[data-review-dock]').evaluate(dock => {
      const rect = element => { const r = element.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, left: r.left, right: r.right }; };
      return {
        dock: rect(dock), panel: rect(dock.querySelector('[data-review-claims]')),
        helper: rect(dock.querySelector('.review-claims-summary')),
        confirm: rect(dock.querySelector('[data-review-confirm]')),
      };
    });
    assert.ok(bounds.panel.top >= bounds.dock.top - 2 && bounds.panel.bottom <= bounds.dock.bottom + 2, `Selection panel stays inside action dock: ${JSON.stringify(bounds)}`);
    assert.ok(bounds.helper.bottom <= bounds.panel.bottom - 1, `Helper stays inside selection panel: ${JSON.stringify(bounds)}`);
    assert.ok(bounds.confirm.top >= bounds.dock.top - 2 && bounds.confirm.bottom <= bounds.dock.bottom + 2, `Verdict stays inside action dock: ${JSON.stringify(bounds)}`);
  };
  let failure;
  try {
    const baseline = await snapshot();
    const ctx = await context({ width: 1920, height: 1080 });
    const page = await ctx.newPage();
    await openCase(page, baseline);
    assert.deepEqual((await snapshot()).snapshot, baseline.snapshot, 'GET only reads the fixture');
    assert.equal(await page.locator('[data-review-claims]').count(), 1);
    assert.ok(await page.locator('[data-review-claim]').count() >= 8, 'Dense mixed case includes claims and behavior observations');
    assert.equal(await page.locator('[data-review-claim]').first().getAttribute('data-review-claim'), 'xss', 'Important claims lead the selector');
    assert.equal(await choice(page, 'xss').isChecked(), true);
    assert.match(await badge(page, 'xss').innerText(), /Bestätigt/);
    assert.equal(await choice(page, 'sql_injection').isChecked(), true);
    assert.match(await badge(page, 'sql_injection').innerText(), /Verdacht/);
    assert.equal(await choice(page, 'component:sql_error_observation').isChecked(), true);
    assert.match(await badge(page, 'component:sql_error_observation').innerText(), /SQL-Fehler.*Beobachtet/s);
    assert.match(await badge(page, 'observation:input_reflected').innerText(), /Eingabe reflektiert/);
    assert.match(await page.locator('[data-review-claim-count]').innerText(), /^\d+\/\d+$/);
    const formData = await page.locator('#review-assessment-form').evaluate(form => [...new FormData(form).entries()]);
    assert.ok(formData.some(([key, value]) => key === 'claim_selection_version' && value === '1'));
    assert.ok(formData.some(([key, value]) => key === 'claim_selection_observation' && value === baseline.fixtures.claimSelection.claimObservationId));
    assert.ok(formData.some(([key, value]) => key === 'claim_selection[component:sql_error_observation][selected]' && value === '1'));
    assert.equal(formData.filter(([key]) => key.startsWith('claims[')).length, 0, 'Legacy editor does not submit conflicting fields');
    assert.equal(formData.filter(([key]) => key.startsWith('claim_selection[') && key.endsWith('[revision]')).length, await page.locator('[data-review-claim]').count(), 'Every offered row has a revision');
    await assertVisibleBounds(page, '[data-review-claims]');
    await assertDockContained(page);
    await page.screenshot({ path: path.join(output, 'claims-classic-1920x1080.png') });
    await page.setViewportSize({ width: 1440, height: 900 });
    await assertVisibleBounds(page, '[data-review-claims]');
    await assertDockContained(page);
    await page.screenshot({ path: path.join(output, 'claims-classic-de-1440x900.png') });
    await page.setViewportSize({ width: 1920, height: 1080 });

    await page.locator('[data-review-focus-toggle]').click();
    assert.equal(await page.locator('body').getAttribute('data-review-focus-mode'), 'true');
    assert.equal(await page.locator('[data-review-inspector]').evaluate(el => el.open), false, 'Inspector starts closed in focus mode');
    assert.equal(await page.locator('[data-review-claims]').isVisible(), true, 'Choices remain outside inspector');
    await assertVisibleBounds(page, '[data-review-claims]');
    await assertDockContained(page);
    await page.screenshot({ path: path.join(output, 'claims-focus-1920x1080.png') });
    await page.setViewportSize({ width: 1440, height: 900 });
    await assertVisibleBounds(page, '[data-review-claims]');
    await assertDockContained(page);
    await page.screenshot({ path: path.join(output, 'claims-focus-de-1440x900.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await assertVisibleBounds(page, '[data-review-claims]');
    await assertDockContained(page);
    await page.screenshot({ path: path.join(output, 'claims-focus-390x844.png') });
    await page.locator('[data-review-focus-toggle]').click();
    await assertVisibleBounds(page, '[data-review-claims]');
    await assertDockContained(page);
    await page.screenshot({ path: path.join(output, 'claims-classic-390x844.png') });
    await page.setViewportSize({ width: 1920, height: 1080 });

    const englishCtx = await context({ width: 1440, height: 900 });
    await englishCtx.addCookies([{ name: 'lbb_locale', value: 'en', url: origin }]);
    const englishPage = await englishCtx.newPage();
    await openCase(englishPage, baseline, 'claimSelectionKeyboard', 'claimSelection');
    assert.match(await englishPage.locator('[data-review-claims] legend').innerText(), /Choose findings.*selected/);
    assert.match(await badge(englishPage, 'component:sql_error_observation').innerText(), /SQL error.*Observed/s);
    assert.match(await badge(englishPage, 'observation:input_reflected').innerText(), /Input reflected/);
    assert.match(await englishPage.locator('.review-claims-summary').innerText(), /Suspected stays suspected/);
    await assertVisibleBounds(englishPage, '[data-review-claims]');
    await assertDockContained(englishPage);
    await englishPage.screenshot({ path: path.join(output, 'claims-classic-en-1440x900.png') });
    await englishPage.locator('[data-review-focus-toggle]').click();
    await assertVisibleBounds(englishPage, '[data-review-claims]');
    await assertDockContained(englishPage);
    await englishPage.screenshot({ path: path.join(output, 'claims-focus-en-1440x900.png') });

    const skipCtx = await context({ width: 1280, height: 800 });
    const skipPage = await skipCtx.newPage();
    await openCase(skipPage, baseline);
    await choice(skipPage, 'error_disclosure').uncheck();
    await choice(skipPage, 'component:sql_error_observation').uncheck();
    await skipPage.locator('[data-review-skip]').click();
    await skipPage.waitForFunction(id => document.body.dataset.currentId !== id, baseline.fixtures.claimSelection.id);
    assert.deepEqual((await snapshot()).snapshot, baseline.snapshot, 'Skip ignores edited choices and does not write claims or rule hints');

    await choice(page, 'error_disclosure').uncheck();
    await choice(page, 'component:sql_error_observation').uncheck();
    assert.equal(await choice(page, 'xss').isChecked(), true);
    await page.locator('input[name="claim_selection[xss][revision]"]').evaluate(input => { input.value = '999'; });
    await page.locator('[data-review-confirm]').click();
    await page.locator('[data-review-error]').waitFor();
    assert.equal(await page.locator('body').getAttribute('data-current-id'), baseline.fixtures.claimSelection.id, 'Validation error retains card');
    assert.equal(await choice(page, 'error_disclosure').isChecked(), false, 'Validation replay retains deselection');
    assert.equal(await choice(page, 'component:sql_error_observation').isChecked(), false);
    assert.equal(await choice(page, 'xss').isChecked(), true);
    assert.deepEqual((await snapshot()).snapshot, baseline.snapshot, 'Invalid revision leaves database unchanged');
    await page.locator('[data-review-confirm]').click();
    await page.waitForFunction(id => document.body.dataset.currentId !== id, baseline.fixtures.claimSelection.id);
    const saved = (await snapshot()).snapshot;
    const findingId = baseline.fixtures.claimSelection.id;
    assert.ok(saved.finding_assessment.some(row => row.finding_id === findingId && row.assessment === 'confirmed'), 'Case verdict saved');
    assert.ok(saved.finding_claim.some(row => row.finding_id === findingId && row.claim === 'xss' && row.state === 'confirmed'), 'Checked historical certainty stays confirmed');
    assert.ok(saved.finding_claim.some(row => row.finding_id === findingId && row.claim === 'sql_injection' && row.state === 'suspected'), 'Checked suspicion stays suspected');
    assert.ok(saved.finding_claim.some(row => row.finding_id === findingId && row.claim === 'error_disclosure' && row.state === 'rejected'), 'Unchecked proposal is rejected for this case');
    assert.ok(saved.cribrum_review_selection.some(row => row.finding_id === findingId && row.option_key === 'component:sql_error_observation' && Number(row.selected) === 0), 'SQL-error observation is separately deselected');
    assert.ok(saved.cribrum_rule_review_hint.some(row => row.finding_id === findingId && row.claim === 'error_disclosure' && row.status === 'pending'), 'Deselected proposal queues a rule-review hint');

    const keyboardCtx = await context({ width: 1280, height: 800 });
    const keyboardPage = await keyboardCtx.newPage();
    await openCase(keyboardPage, baseline, 'claimSelectionKeyboard', 'claimSelection');
    await choice(keyboardPage, 'error_disclosure').uncheck();
    await keyboardPage.locator('[data-review-focus]').focus();
    await keyboardPage.keyboard.press('ArrowRight');
    await keyboardPage.waitForFunction(id => document.body.dataset.currentId !== id, baseline.fixtures.claimSelectionKeyboard.id);
    const keyboardSaved = (await snapshot()).snapshot;
    assert.ok(keyboardSaved.finding_claim.some(row => row.finding_id === baseline.fixtures.claimSelectionKeyboard.id && row.claim === 'error_disclosure' && row.state === 'rejected'), 'Keyboard right submits visible deselection');

    const swipeCtx = await context({ width: 1280, height: 800 }, { hasTouch: true });
    const swipePage = await swipeCtx.newPage();
    await openCase(swipePage, baseline, 'claimSelectionSwipe', 'claimSelectionKeyboard');
    await choice(swipePage, 'error_disclosure').uncheck();
    const strip = swipePage.locator('[data-review-gesture]');
    await strip.scrollIntoViewIfNeeded();
    const box = await strip.boundingBox();
    assert.ok(box && box.width >= 220, 'Swipe target is visible');
    const cdp = await swipeCtx.newCDPSession(swipePage);
    const x = box.x + box.width / 2 - 110, y = box.y + box.height / 2;
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + 110, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + 220, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await cdp.detach();
    await swipePage.waitForFunction(id => document.body.dataset.currentId !== id, baseline.fixtures.claimSelectionSwipe.id);
    const swipeSaved = (await snapshot()).snapshot;
    assert.ok(swipeSaved.finding_claim.some(row => row.finding_id === baseline.fixtures.claimSelectionSwipe.id && row.claim === 'error_disclosure' && row.state === 'rejected'), 'Swipe right submits visible deselection');

    const nativeCtx = await context({ width: 390, height: 844 }, { javaScriptEnabled: false });
    const nativePage = await nativeCtx.newPage();
    await openCase(nativePage, baseline, 'claimSelectionNoJs', 'claimSelectionSwipe');
    await choice(nativePage, 'error_disclosure').uncheck();
    await nativePage.locator('[data-review-confirm]').click();
    assert.notEqual(await nativePage.locator('body').getAttribute('data-current-id'), baseline.fixtures.claimSelectionNoJs.id);
    const nativeSaved = (await snapshot()).snapshot;
    assert.ok(nativeSaved.finding_claim.some(row => row.finding_id === baseline.fixtures.claimSelectionNoJs.id && row.claim === 'error_disclosure' && row.state === 'rejected'), 'Native no-JS form submits dock checkbox');
    assert.equal(outside.length, 0, 'No external requests');
    assert.deepEqual(errors, [], 'No browser errors');
    console.log('PASS mixed Review choices, classic/focus responsive layout, native form, keyboard, swipe, skip, error replay, saved certainty and rule hint');
  } catch (error) { failure = error; throw error; }
  finally {
    await Promise.allSettled(contexts.map(ctx => ctx.close()));
    await api.dispose();
    await browser.close();
    if (failure) console.error('Screenshots in ' + output);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
