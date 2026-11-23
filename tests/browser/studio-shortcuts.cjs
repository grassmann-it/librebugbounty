/* Persisted shortcuts, keyboard dispatch and paste preference on an isolated Review fixture. */
'use strict';
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE;
assert.ok(base && ['127.0.0.1', 'localhost', 'web'].includes(new URL(base).hostname));

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const errors = [], external = [], assessments = [];
  context.on('page', page => {
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      if (request.method() === 'POST' && /\/assessment$/.test(new URL(request.url()).pathname)) assessments.push(request.postData());
    });
  });
  await context.route('**/*', route => {
    if (new URL(route.request().url()).origin !== new URL(base).origin) { external.push(route.request().url()); return route.abort(); }
    // This suite verifies native submission and binding refresh; queued saves
    // have their own acceptance suite.
    if (new URL(route.request().url()).pathname === '/review/preview') return route.abort();
    if (/\/interactive\/status$/.test(new URL(route.request().url()).pathname)) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ state: 'available', revision: 0, reconnect: false }) });
    return route.continue();
  });
  const page = await context.newPage();
  const binding = action => page.locator(`[name="shortcut_bindings[${action}]"]`);
  const save = (target = page) => Promise.all([target.waitForNavigation(), target.locator('.studio-settings-form button[type=submit]').click()]);
  const idleFocus = () => page.locator('#review-main').focus();
  const nativeControls = async key => {
    const mode = await page.locator('[data-studio-review]').getAttribute('data-review-focus-mode');
    await page.evaluate(() => {
      const controls = document.createElement('div');
      controls.id = 'native-shortcut-controls';
      controls.innerHTML = '<button type="button">Native button</button><a href="#native">Native link</a><details><summary>Native summary</summary>Details</details>';
      window.nativeShortcutClicks = 0;
      controls.querySelectorAll('button, a').forEach(node => node.addEventListener('click', event => { event.preventDefault(); window.nativeShortcutClicks++; }));
      document.querySelector('#review-main').append(controls);
    });
    await page.locator('#native-shortcut-controls button').focus();
    await page.keyboard.press(key);
    assert.equal(await page.evaluate(() => window.nativeShortcutClicks), 1, key + ' keeps native button activation');
    if (key === 'Enter') {
      await page.locator('#native-shortcut-controls a').focus();
      await page.keyboard.press(key);
      assert.equal(await page.evaluate(() => window.nativeShortcutClicks), 2, 'Enter keeps native link activation');
    }
    await page.locator('#native-shortcut-controls summary').focus();
    await page.keyboard.press(key);
    assert.equal(await page.locator('#native-shortcut-controls details').getAttribute('open'), '', key + ' keeps native summary activation');
    assert.equal(await page.locator('[data-studio-review]').getAttribute('data-review-focus-mode'), mode, 'Native activation does not toggle Review focus mode');
    await page.locator('#native-shortcut-controls').evaluate(node => node.remove());
    await idleFocus();
  };
  try {
    for (const locale of ['de', 'en']) {
      await context.addCookies([{ name: 'lbb_locale', value: locale, url: base }]);
      assert.equal((await page.goto(base + '/settings')).status(), 200);
      assert.equal(await page.locator('html').getAttribute('lang'), locale);
      await page.locator('.studio-shortcut-editor summary').click();
      await page.locator('[data-shortcut-record="shortcut-review-fixed"]').click();
      await page.keyboard.press('A');
      assert.equal(await binding('review.fixed').inputValue(), 'A');
      await binding('review.confirmed').fill('D');
      await binding('review.focus').fill('Alt+Shift+F');
      await binding('nav.inventory').fill('Alt+Shift+B');
      await binding('nav.review').fill('Alt+Shift+R');
      await binding('nav.intake').fill('Alt+Shift+E');
      await binding('nav.export').fill('Ctrl+Shift+1');
      await page.locator('[name=paste_submit]').check();
      await save();
      await page.reload();
      assert.equal(await binding('review.fixed').inputValue(), 'A');
      assert.equal(await binding('nav.inventory').inputValue(), 'Alt+Shift+B');
      assert.equal(await page.locator('[name=paste_submit]').isChecked(), true);
      await page.keyboard.press('Alt+Shift+E');
      await page.waitForURL(base + '/');
      assert.equal(await page.locator('[data-intake]').getAttribute('data-intake-paste-submit'), '1');
      await page.locator('#studio-url').fill('ordinary text');
      await page.keyboard.press('Alt+Shift+B');
      assert.equal(new URL(page.url()).pathname, '/', 'Text entry owns navigation combinations');
      await page.locator('#studio-url').evaluate(node => node.blur());
      await page.keyboard.press('Alt+Shift+B');
      await page.waitForURL(/\/findings/);
      assert.equal(await page.locator('[data-shortcut-action="nav.inventory"] kbd').textContent(), 'Alt+Shift+B');
      await page.keyboard.press('Control+Shift+Digit1');
      await page.waitForURL(/\/export/);
      await page.evaluate(() => sessionStorage.removeItem('lbb.review.focus'));
      await page.keyboard.press('Alt+Shift+R');
      await page.waitForURL(/\/review/);
      await page.locator('[data-studio-review][data-review-enhanced=true]').waitFor();
      assert.equal(await page.locator('[data-review-fixed] kbd').textContent(), 'A');
      assert.equal(await page.locator('[data-review-confirm] kbd').textContent(), 'D');
      await idleFocus();
      await page.keyboard.press('f');
      assert.notEqual(await page.locator('[data-studio-review]').getAttribute('data-review-focus-mode'), 'true', 'Replaced focus key is inactive');
      await page.keyboard.press('Alt+Shift+F');
      assert.equal(await page.locator('[data-studio-review]').getAttribute('data-review-focus-mode'), 'true');
      await page.keyboard.press('Alt+Shift+F');
      const reviewedId = await page.locator('[data-studio-review]').getAttribute('data-current-id');
      const before = assessments.length;
      await page.keyboard.press('ArrowLeft');
      assert.equal(assessments.length, before, 'Replaced arrow does not submit');
      assert.equal((await page.goto(base + '/review?images=missing')).status(), 200);
      await page.locator('[data-studio-review][data-review-enhanced=true]').waitFor();
      await page.locator('[data-review-novnc]').first().evaluate(node => {
        for (let parent = node.parentElement; parent; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
        node.focus();
      });
      await page.keyboard.press('Alt+Shift+B');
      await page.keyboard.press('A');
      assert.equal(new URL(page.url()).pathname, '/review', 'Remote-browser panel owns navigation keys');
      assert.equal(assessments.length, before, 'Remote-browser panel owns decision keys');
      assert.equal((await page.goto(base + '/review')).status(), 200);
      await page.locator('[data-studio-review][data-review-enhanced=true]').waitFor();
      assert.equal(await page.locator('[data-studio-review]').getAttribute('data-current-id'), reviewedId);
      await idleFocus();
      await page.locator('[data-shortcut-help]').click();
      await page.locator('[data-shortcut-dialog][open]').waitFor();
      assert.equal(await page.locator('[data-shortcut-dialog] [data-shortcut-hint="review.fixed"]').textContent(), 'A');
      if (locale === 'de') await page.screenshot({ path: path.join(process.env.STUDIO_BROWSER_OUTPUT, 'shortcut-help.png') });
      await page.keyboard.press('A');
      assert.equal(assessments.length, before, 'Help owns keyboard input');
      await page.keyboard.press('Escape');
      await page.locator('[data-shortcut-dialog]').waitFor({ state: 'detached' });
      await idleFocus();
      await page.evaluate(() => {
        const event = new KeyboardEvent('keydown', { key: 'A', repeat: true, bubbles: true, cancelable: true });
        document.activeElement.dispatchEvent(event);
      });
      assert.equal(assessments.length, before, 'Repeat does not submit');
      const settingsTab = await context.newPage();
      await settingsTab.goto(base + '/settings');
      await settingsTab.locator('.studio-shortcut-editor summary').click();
      await settingsTab.locator('[name="shortcut_bindings[review.fixed]"]').fill('X');
      await settingsTab.locator('[name="shortcut_bindings[review.focus]"]').fill('Space');
      await save(settingsTab);
      await settingsTab.close();
      // This card still owns its rendered binding; its response picks up the
      // latest settings without requiring a full-document navigation.
      await Promise.all([page.waitForResponse(response => response.request().method() === 'POST' && /\/assessment$/.test(new URL(response.url()).pathname)), page.keyboard.press('A')]);
      await page.waitForFunction(() => !document.querySelector('[data-review-submitting="true"]'));
      assert.equal(assessments.length, before + 1);
      const fixture = await (await context.request.get(base + '/__studio_review_fixture')).json();
      assert.equal(fixture.snapshot.finding.find(row => row.id === reviewedId).manual_assessment, 'fixed');
      // AJAX review replacement must retain the configured hint and dispatcher.
      assert.equal(await page.locator('[data-review-fixed] kbd').textContent(), 'X');
      assert.equal(await page.locator('[data-review-focus-toggle] kbd').textContent(), 'Space');
      await idleFocus();
      await page.keyboard.press('A');
      assert.equal(assessments.length, before + 1, 'AJAX replacement adopts bindings saved in another tab');
      await nativeControls('Space');
      await page.keyboard.press('Space');
      assert.equal(await page.locator('[data-studio-review]').getAttribute('data-review-focus-mode'), 'true');
      await page.keyboard.press('Space');
      await page.locator('[data-shortcut-help]').click();
      await page.locator('[data-shortcut-dialog][open]').waitFor();
      await page.keyboard.press('Escape');
      await page.goto(base + '/settings');
      await page.locator('.studio-shortcut-editor summary').click();
      await binding('review.fixed').fill('');
      await binding('review.skip').fill('S');
      await binding('review.focus').fill('Enter');
      await save();
      await page.goto(base + '/review');
      await page.locator('[data-studio-review][data-review-enhanced=true]').waitFor();
      assert.equal(await page.locator('[data-review-fixed] kbd').isVisible(), false);
      await idleFocus();
      await page.keyboard.press('A');
      await page.keyboard.press('X');
      assert.equal(assessments.length, before + 1, 'Empty binding disables the action');
      await nativeControls('Enter');
      await page.goto(base + '/settings');
      await page.locator('.studio-shortcut-editor summary').click();
      await page.locator('[data-shortcut-reset]').click();
      await page.locator('[name=paste_submit]').uncheck();
      await save();
      assert.equal(await binding('review.fixed').inputValue(), 'ArrowLeft');
      assert.equal(await binding('nav.inventory').inputValue(), '');
      assert.equal(await page.locator('[name=paste_submit]').isChecked(), false);
      console.log('PASS persisted bindings, recording, hints/help, navigation combinations, review dispatch/replacement, cross-tab configuration, native activation, input protection, repeat, disable/reset and paste preference (' + locale + ')');
    }
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
