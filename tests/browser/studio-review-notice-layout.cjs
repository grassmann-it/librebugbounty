/* Notice + claim choices must keep their actions and decision countdown visible.
 * Run with automatic fixture/server cleanup:
 *   python tests/browser/run_review_notice.py
 * Or use the isolated Cribrum badge router (never the regular application DB):
 *   STUDIO_BROWSER_ROOT=/tmp/librebugbounty-cribrum-badges-notice-layout php tests/Support/cribrum_badges_browser_router.php init
 *   STUDIO_BROWSER_ROOT=/tmp/librebugbounty-cribrum-badges-notice-layout php -S 127.0.0.1:8099 -t public tests/Support/cribrum_badges_browser_router.php
 *   STUDIO_BROWSER_BASE=http://127.0.0.1:8099 node tests/browser/studio-review-notice-layout.cjs
 * Only the isolated settings form is saved; no finding, job or target is changed.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://127.0.0.1:8099';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-review-notice-layout';
assert.ok(['web', 'localhost', '127.0.0.1'].includes(new URL(base).hostname));

(async () => {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const errors = [], blocked = [], checks = [];
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  await context.addCookies([{ name: 'lbb_locale', value: 'de', url: origin }]);
  await context.route('**/*', route => {
    const req = route.request(), url = new URL(req.url());
    if (url.origin === origin && (req.method() === 'GET' || (req.method() === 'POST' && url.pathname === '/settings'))) return route.continue();
    blocked.push({ url: url.href, method: req.method() });
    return route.abort();
  });
  const snapshot = async () => {
    const response = await page.request.get(origin + '/__cribrum_badges_fixture');
    assert.equal(response.status(), 200);
    const data = await response.json();
    assert.equal(data.isolated, true);
    assert.match(data.paths.database, /^\/tmp\/librebugbounty-cribrum-badges-/);
    return data;
  };
  const visibleInsideDock = async selector => {
    const control = page.locator(selector);
    assert.equal(await control.isVisible(), true, `${selector} is visible`);
    const geometry = await control.evaluate(element => {
      const rect = node => { const r = node.getBoundingClientRect(); return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height }; };
      return { control: rect(element), dock: rect(element.closest('[data-review-dock]')), viewport: { width: innerWidth, height: innerHeight } };
    });
    const { control: c, dock: d, viewport: v } = geometry;
    assert.ok(c.width > 0 && c.height > 0 && c.x >= 0 && c.y >= 0 && c.right <= v.width + 1 && c.bottom <= v.height + 1, `${selector} fits viewport: ${JSON.stringify(geometry)}`);
    assert.ok(c.x >= d.x - 1 && c.y >= d.y - 1 && c.right <= d.right + 1 && c.bottom <= d.bottom + 1, `${selector} fits dock: ${JSON.stringify(geometry)}`);
    return geometry;
  };
  try {
    const fixture = await snapshot();
    // Creation timestamps can tie; use the actual cursor order, not random UUID order.
    const ordered = [...fixture.snapshot.finding].sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id.localeCompare(b.id));
    const position = ordered.findIndex(row => row.id === fixture.fixtures.waf.id);
    assert.ok(position >= 0);
    const query = new URLSearchParams({ kind: 'changed', images: 'all' });
    if (position > 0) query.set('after', ordered[position - 1].id);
    const reviewUrl = `${origin}/review?${query}`;
    await page.goto(origin + '/settings');
    await page.locator('[name="review_decision_delay_seconds"]').selectOption('5');
    await Promise.all([page.waitForNavigation(), page.locator('.studio-settings-actions button[type="submit"]').click()]);
    assert.equal(await page.locator('[name="review_decision_delay_seconds"]').inputValue(), '5');
    const before = await snapshot();
    for (const locale of ['de', 'en']) {
      await context.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
      for (const [width, height] of [[1440, 900], [960, 900], [800, 900], [720, 900], [390, 844]]) {
        for (const focus of [false, true]) {
          await page.setViewportSize({ width, height });
          const response = await page.goto(reviewUrl);
          assert.equal(response.status(), 200);
          assert.equal(await page.locator('body').getAttribute('data-current-id'), fixture.fixtures.waf.id);
          assert.equal(await page.locator('.review-has-notice.review-has-claims').count(), 1);
          if ((await page.locator('body').getAttribute('data-review-focus-mode') === 'true') !== focus) await page.keyboard.press('f');
          await page.waitForFunction(() => document.querySelector('[data-review-delay-status]').textContent.match(/\d/));
          assert.equal(await page.locator('[data-review-confirm]').isDisabled(), true);
          assert.equal(await page.locator('[data-review-keep]').isEnabled(), true);
          assert.equal(await page.locator('[data-review-keep]').evaluate(button => button.form.id), 'review-assessment-form');
          const keep = await visibleInsideDock('[data-review-keep]');
          const delay = await visibleInsideDock('[data-review-delay-status]');
          await visibleInsideDock('[data-review-delay-progress]');
          await visibleInsideDock('[data-review-claims]');
          await visibleInsideDock('[data-review-confirm]');
          await page.locator('[data-review-keep]').click({ trial: true });
          await page.locator('[data-review-keep]').focus();
          assert.equal(await page.locator('[data-review-keep]').evaluate(button => button === document.activeElement), true);
          const documentWidth = await page.evaluate(() => document.documentElement.scrollWidth);
          assert.ok(documentWidth <= width, 'No horizontal document overflow');
          checks.push({ locale, width, height, focus, keep, delay });
          if (locale === 'de' && [1440, 960, 390].includes(width)) await page.screenshot({ path: path.join(output, `notice-${focus ? 'focus' : 'classic'}-${width}.png`) });
        }
      }
    }
    // The real fixture above offers Keep. Stress the same notice row with both
    // additional production labels; this checks wrapping, not notice eligibility.
    const multipleActions = [];
    for (const [width, height] of [[960, 844], [390, 844], [844, 390]]) {
      for (const focus of [false, true]) {
        await page.setViewportSize({ width, height });
        await page.goto(reviewUrl);
        if ((await page.locator('body').getAttribute('data-review-focus-mode') === 'true') !== focus) await page.keyboard.press('f');
        await page.evaluate(() => {
          const keep = document.querySelector('[data-review-keep]');
          for (const [value, text] of [['acknowledge_change', 'Geprüft · Klassifikationshinweis erledigen'], ['acknowledge_waf_block', 'Geprüft · WAF-Hinweis erledigen']]) {
            const action = keep.cloneNode(false);
            action.className = 'review-button';
            action.removeAttribute('data-review-keep');
            action.value = value;
            action.textContent = text;
            action.disabled = document.querySelector('[data-review-confirm]').disabled;
            keep.before(action);
          }
        });
        await visibleInsideDock('[data-review-dock]');
        for (const value of ['keep', 'acknowledge_change', 'acknowledge_waf_block']) await visibleInsideDock(`button[name="assessment"][value="${value}"]`);
        await visibleInsideDock('[data-review-claims]');
        await visibleInsideDock('[data-review-confirm]');
        assert.equal(await page.evaluate(() => document.querySelector('.review-dock-notices').getBoundingClientRect().bottom <= document.querySelector('.review-keypad').getBoundingClientRect().top), true, 'Notice actions do not overlap decision keys');
        multipleActions.push({ width, height, focus });
        await page.screenshot({ path: path.join(output, `notice-multiple-${focus ? 'focus' : 'classic'}-${width}.png`) });
      }
    }
    await page.waitForFunction(() => !document.querySelector('[data-review-confirm]').disabled, null, { timeout: 7000 });
    await visibleInsideDock('[data-review-delay-status]');
    assert.match(await page.locator('[data-review-delay-status]').innerText(), /Ready to assess/);
    assert.deepEqual((await snapshot()).snapshot, before.snapshot, 'Layout checks never change any finding, evidence or job');
    assert.deepEqual(errors, []);
    assert.deepEqual(blocked, []);
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ checks, multipleActions, errors, blocked }, null, 2));
    console.log(`PASS ${checks.length} bilingual classic/focus notice + claims layouts, visible countdown, keyboard and pointer access; ${multipleActions.length} multiple-action layout stress cases; no case mutations`);
  } catch (error) {
    await page.screenshot({ path: path.join(output, 'failure.png') }).catch(() => {});
    throw error;
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
