/* Explicit queue recovery against the isolated Studio router; no target capture. */
'use strict';
const assert = require('node:assert/strict');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE;
assert.ok(base && ['127.0.0.1', 'localhost'].includes(new URL(base).hostname));

async function main() {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 375, height: 900 } });
    await context.addCookies([{ name: 'lbb_locale', value: 'de', url: base }]);
    const external = [], errors = [];
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin !== base) { external.push(route.request().url()); return route.abort(); }
      return route.continue();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const seed = await context.request.post(base + '/__studio_acceptance_diagnostics');
    assert.equal(seed.status(), 200);
    const fixture = (await seed.json()).result;
    const state = async () => (await (await context.request.get(base + '/__studio_acceptance_diagnostics_state')).json()).result.snapshot;
    const before = await state();
    const query = '/errors?kind=missing&q=Recovered&pageSize=10';
    assert.equal((await page.goto(base + query)).status(), 200);
    assert.equal(await page.locator('[data-error-case]').count(), 1);
    assert.equal(await page.locator('[data-error-case]').getAttribute('data-error-case'), fixture.recovered);
    assert.equal(await page.locator('[data-recovery-one]').count(), 1);
    assert.deepEqual(await state(), before, 'GET does not enqueue or mutate retained data');
    const csrf = await context.request.post(base + '/errors/missing-images/recover', { form: { kind: 'missing' } });
    assert.equal(csrf.status(), 403);
    assert.deepEqual(await state(), before);
    await Promise.all([page.waitForNavigation(), page.locator('[data-recovery-one] button').click()]);
    assert.equal(new URL(page.url()).searchParams.get('q'), 'Recovered');
    assert.match(await page.locator('[data-recovery-result]').textContent(), /Neu eingereiht: 1/);
    assert.equal(await page.locator('[data-error-case]').count(), 1, 'Enqueue is not recovery');
    await Promise.all([page.waitForNavigation(), page.locator('[data-recovery-filtered] button').click()]);
    assert.match(await page.locator('[data-recovery-result]').textContent(), /Bereits aktiv: 1/);
    await page.goto(base + '/errors?kind=missing');
    await Promise.all([page.waitForNavigation(), page.locator('[data-recovery-filtered] button').click()]);
    assert.match(await page.locator('[data-recovery-result]').textContent(), /Übersprungen: 1/);
    const after = await state();
    assert.equal(after.screenshot_job.length, before.screenshot_job.length + 1);
    assert.equal(after.screenshot_job.filter(j => j.active_key === fixture.recovered).length, 1);
    assert.ok(!after.screenshot_job.some(j => j.active_key === fixture.archived));
    assert.deepEqual(after.screenshot_job.slice(0, -1), before.screenshot_job);
    for (const key of Object.keys(before).filter(k => k !== 'screenshot_job')) assert.deepEqual(after[key], before[key], key + ' preserved');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No mobile overflow');
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    console.log('PASS: no-JS mobile single/filtered recovery, CSRF, current filters, double enqueue, discarded skip, pending missing state and retained history');
    await context.close();
  } finally { await browser.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
