/* Focused paste acceptance. Run via run_intake.py paste: disposable database,
 * no workers, all browser requests restricted to the isolated fixture origin.
 * Enabled pages override only the HTML flag, independently of backend settings.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE;
assert.ok(base, 'Run with the isolated run_intake.py runner');
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT;

async function main() {
  const browser = await chromium.launch({ headless: true, args: [`--unsafely-treat-insecure-origin-as-secure=${origin}`] });
  const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
  const errors = [], external = [], posts = [], checks = [];
  let stored = 0;
  const mark = message => { checks.push(message); console.log('PASS ' + message); };
  context.on('page', page => {
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      if (request.method() === 'POST') posts.push({ url: request.url(), body: request.postData() });
    });
  });
  await context.route('**/*', route => {
    if (new URL(route.request().url()).origin !== origin) {
      external.push(route.request().url());
      return route.abort();
    }
    return route.continue();
  });
  const counts = async () => {
    const data = await (await context.request.get(base + '/__studio_acceptance_counts')).json();
    assert.equal(data.isolated, true);
    return data.counts;
  };
  async function open(flag) {
    const page = await context.newPage();
    if (flag !== undefined) await page.route(base + '/', async route => {
      const response = await route.fetch();
      const html = await response.text();
      assert.match(html, /data-intake-paste-submit="[01]"/);
      await route.fulfill({ response, body: html.replace(/data-intake-paste-submit="[01]"/, flag === null ? '' : `data-intake-paste-submit="${flag}"`) });
    });
    assert.equal((await page.goto(base + '/')).status(), 200);
    await page.locator('[data-intake-activity]').waitFor({ state: 'visible' });
    await page.evaluate(() => {
      window.pasteSubmits = 0;
      document.querySelector('#intake-form').addEventListener('submit', () => window.pasteSubmits++);
      document.querySelector('#intake-form details').open = true;
    });
    return page;
  }
  // Synthetic paste makes preventDefault observable without simulating a browser
  // default action. Actual clipboard/keyboard paste is covered separately below.
  const paste = (page, selector, value) => page.locator(selector).evaluate((node, value) => {
    const clipboardData = new DataTransfer();
    clipboardData.setData('text/plain', value);
    const event = new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData });
    node.dispatchEvent(event);
    return event.defaultPrevented;
  }, value);
  async function realPaste(page, selector, value) {
    await page.locator(selector).focus();
    await page.evaluate(value => navigator.clipboard.writeText(value), value);
    await page.keyboard.press('Control+V');
  }
  const idle = page => page.waitForFunction(() => !document.querySelector('#intake-form').hasAttribute('aria-busy'));
  async function capture(page, selector, value, status, nativePaste = false) {
    const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    if (nativePaste) await realPaste(page, selector, value);
    else assert.equal(await paste(page, selector, value), true);
    const result = await response;
    if (status) assert.equal(result.status(), status);
    else assert.ok([200, 201, 422].includes(result.status()), 'Candidate reaches normal server validation');
    if (result.status() === 201) stored++;
    const body = new URLSearchParams(result.request().postData());
    assert.equal(body.get('url'), value.trim());
    assert.equal(body.get('payload'), 'MARKER <>&"');
    assert.equal(body.get('annotate'), 'Keep my notes');
    await idle(page);
    assert.equal(await page.locator('#studio-url').getAttribute('type'), 'url');
    return result;
  }
  try {
    assert.deepEqual(await counts(), { finding: 0, screenshot_job: 0, retest_run: 0, evidence: 0, queued: 0 });
    const disabled = await open();
    assert.equal(await disabled.locator('[data-intake]').getAttribute('data-intake-paste-submit'), '0');
    for (const flag of [undefined, null, '0']) {
      const page = flag === undefined ? disabled : await open(flag);
      assert.equal(await paste(page, 'body', 'https://paste.example/disabled'), false);
      await realPaste(page, '#studio-url', 'https://paste.example/manual');
      assert.equal(await page.locator('#studio-url').inputValue(), 'https://paste.example/manual');
      assert.equal(await page.evaluate(() => window.pasteSubmits), 0);
      await page.close();
    }
    assert.equal(posts.length, 0);
    mark('Default, missing and disabled flag preserve manual paste without auto-submit');

    const page = await open('1');
    await page.locator('#studio-payload').fill('MARKER <>&"');
    await page.locator('#studio-notes').fill('Keep my notes');
    const raw = 'https://paste.example/background?q=<svg/onload=alert("raw")>&x=%2F#Exact';
    await capture(page, 'body', ' \t' + raw + '  ', 201);
    assert.equal(await page.locator('#studio-url').inputValue(), '');
    mark('Background paste trims outer whitespace, submits once and preserves URL payload verbatim');
    await page.locator('#studio-notes').fill('Keep my notes');
    await page.locator('#studio-url').fill('https://old.example/replace-entire-value');
    await capture(page, '#studio-url', 'http://paste.example/url-field?case=Exact', 201, true);
    mark('Native clipboard/keyboard URL-field paste replaces the old value and uses the regular AJAX path');

    for (const value of ['www.paste.example/no-scheme', 'paste.example/no-scheme']) {
      await page.locator('#studio-notes').fill('Keep my notes');
      await capture(page, 'body', value);
    }
    mark('Schema-less www/host.domain candidates reach server validation unchanged');
    await page.locator('#studio-notes').fill('Keep my notes');
    await capture(page, 'body', 'https://paste.example:99999/invalid', 422);
    assert.equal(await page.locator('#studio-url').inputValue(), 'https://paste.example:99999/invalid');
    assert.ok((await page.locator('#intake-notice').innerText()).trim());
    assert.ok(await page.locator('[data-save-state="failed"]').count());
    await page.locator('#studio-url').fill('https://paste.example/corrected');
    const correction = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    await page.locator('#studio-url').press('Enter');
    assert.equal((await correction).status(), 201);
    stored++;
    await idle(page);
    mark('Invalid URL shows normal server error, retains the draft and allows correction with Enter');

    await page.evaluate(() => {
      const input = document.createElement('input'); input.id = 'other-input'; document.body.append(input);
      const editor = document.createElement('div'); editor.id = 'editor'; editor.contentEditable = 'true'; editor.innerHTML = '<span>Editable</span>'; document.body.append(editor);
      const frame = document.createElement('iframe'); frame.id = 'remote-browser'; frame.srcdoc = '<textarea id="remote"></textarea>'; document.body.append(frame);
    });
    const submissions = await page.evaluate(() => window.pasteSubmits);
    for (const selector of ['#studio-notes', '#studio-payload', '#other-input', '#editor span', '#remote-browser', '#studio-intake-help']) {
      assert.equal(await paste(page, selector, 'https://paste.example/ignored'), false, selector);
    }
    await realPaste(page, '#studio-notes', 'https://paste.example/note-only');
    assert.equal(await page.locator('#studio-notes').inputValue(), 'https://paste.example/note-only');
    await realPaste(page, '#other-input', 'normal text');
    assert.equal(await page.locator('#other-input').inputValue(), 'normal text');
    await page.locator('#studio-intake-help').evaluate(dialog => dialog.showModal());
    assert.equal(await paste(page, 'body', 'https://paste.example/modal'), false);
    await page.locator('#studio-intake-help').evaluate(dialog => dialog.close());
    await page.frameLocator('#remote-browser').locator('#remote').evaluate(node => {
      const clipboardData = new DataTransfer(); clipboardData.setData('text/plain', 'https://paste.example/remote');
      node.dispatchEvent(new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData }));
    });
    await page.locator('#studio-url').fill('https://paste.example/untouched');
    for (const value of ['ordinary text', 'https://paste.example/one\nhttps://paste.example/two', 'https://paste.example/one\ncontinued', 'ftp://paste.example/file', 'javascript:alert(1)', 'https://', 'hello', 'hello world.example']) {
      assert.equal(await paste(page, 'body', value), false, value);
      assert.equal(await paste(page, '#studio-url', value), false, value);
    }
    assert.equal(await page.locator('#studio-url').inputValue(), 'https://paste.example/untouched');
    assert.equal(await page.evaluate(() => window.pasteSubmits), submissions);
    mark('Notes, other inputs, contenteditable, dialogs, iframe and non-URL/multiline text never auto-submit');

    let release;
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/api/findings', async route => { await gate; await route.continue(); });
    await page.locator('#studio-notes').fill('Keep my notes');
    const pending = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    assert.equal(await paste(page, 'body', 'https://paste.example/pending'), true);
    await page.waitForFunction(() => document.querySelector('#intake-form').getAttribute('aria-busy') === 'true');
    for (const selector of ['body', '#studio-url']) assert.equal(await paste(page, selector, 'https://paste.example/duplicate'), true);
    assert.equal(await page.locator('#studio-url').inputValue(), 'https://paste.example/pending');
    assert.equal(await page.evaluate(() => window.pasteSubmits), submissions + 1);
    await page.locator('#studio-url').fill('https://paste.example/newer-draft');
    await page.locator('#studio-notes').fill('Newer notes');
    release();
    assert.equal((await pending).status(), 201); stored++;
    await idle(page);
    assert.equal(await page.locator('#studio-url').inputValue(), 'https://paste.example/newer-draft');
    assert.equal(await page.locator('#studio-notes').inputValue(), 'Newer notes');
    mark('Pending paste duplicates are suppressed; delayed response preserves newer edits');
    assert.deepEqual(await counts(), { finding: stored, screenshot_job: stored, retest_run: 0, evidence: 0, queued: stored });
    assert.equal(posts.length, submissions + 1);
    assert.ok(posts.every(post => new URL(post.url).pathname === '/api/findings'));
    assert.deepEqual(errors, []); assert.deepEqual(external, []);
    mark('Only expected intake POSTs, queued jobs without workers, no target requests or JS errors');
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ checks, posts, stored, errors, external }, null, 2) + '\n');
  } catch (error) {
    await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({ message: error.message, stack: error.stack, checks, posts, errors, external }, null, 2));
    throw error;
  } finally {
    await context.close(); await browser.close();
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
