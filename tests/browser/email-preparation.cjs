/*
 * Synthetic UI contract test: renders only the prepare-email template, serves
 * static assets on loopback, and mocks every preview/generate response. It
 * never boots the app, opens a case database, or calls OpenAI.
 * Run: node tests/browser/email-preparation.cjs
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const {existsSync} = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {execFileSync} = require('node:child_process');
const {chromium} = require('../../playwright-worker/node_modules/playwright');

const root = path.resolve(__dirname, '../..');
const output = path.resolve(process.env.EMAIL_UI_OUTPUT || path.join(root, 'var/email-preparation-ui-20261124'));
assert.equal(path.dirname(output), path.join(root, 'var'));
assert.match(path.basename(output), /^email-preparation-ui-[a-zA-Z0-9_-]+$/);
const php = String.raw`
require getcwd().'/vendor/autoload.php';
$locale = getenv('EMAIL_FIXTURE_LOCALE') ?: 'de';
$i18n = new \App\Service\UiTranslator($locale);
$t = fn(string $key, array $params = []): string => $i18n->trans($key, $params);
$finding = (new \App\Entity\Finding())->setDomain((new \App\Entity\Domain())->setHostname('security-review.example.test'));
$csrfToken = 'synthetic-csrf-token';
$initialState = ['eligible'=>getenv('EMAIL_FIXTURE_ELIGIBLE') !== '0', 'error'=>'Synthetic case needs review.', 'configured'=>getenv('EMAIL_FIXTURE_CONFIGURED') !== '0', 'model'=>'gpt-6.2-sol', 'language'=>$locale, 'contact'=>['channel'=>'email','destination'=>'security@example.test']];
require getcwd().'/templates/studio/prepare-email.php';
`;
const render = (locale, configured = true, eligible = true) => execFileSync('php', ['-r', php], {cwd: root, encoding: 'utf8', env: {...process.env, EMAIL_FIXTURE_LOCALE: locale, EMAIL_FIXTURE_CONFIGURED: configured ? '1' : '0', EMAIL_FIXTURE_ELIGIBLE: eligible ? '1' : '0'}});
const html = Object.fromEntries(['de', 'en'].map(locale => [locale, render(locale)]));
const checks = [];
const mark = text => { checks.push(text); console.log('PASS ' + text); };
const errors = [];
const external = [];
const posts = [];
let generateResponse = {status: 200, body: {ok: true, subject: 'Security report for example.test', body: 'Confirmed XSS.\n\nhttps://example.test/?q=%22+%3Cscript%3E\n\nPlease review this finding.', recipient: 'security@example.test', channel: 'email', model: 'gpt-6.2-sol', language: 'en'}};
let holdGenerate = null;
let releaseGenerate = null;
const injection = '</pre><script>window.EMAIL_INJECTION = true</script>';
const payload = {model: 'gpt-6.2-sol', store: false, input: [{role: 'user', content: JSON.stringify({host: 'example.test', findings: ['confirmed XSS', injection]})}]};
const previewResponse = {ok: true, fingerprint: 'fixture-revision-1', request: payload, contact: {channel: 'email', destination: 'security@example.test'}, model: 'gpt-6.2-sol', language: 'en', eligible: true, summary: {host: 'security-review.example.test', components: [{label: 'Cross-site scripting', certainty: 'confirmed'}, {label: injection, certainty: 'observed'}]}};

async function main() {
  await fs.mkdir(output, {recursive: true});
  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname.startsWith('/fixture/')) {
      const locale = url.pathname.endsWith('/en') ? 'en' : 'de';
      response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
      response.end(url.search ? render(locale, url.searchParams.get('configured') !== '0', url.searchParams.get('eligible') !== '0') : html[locale]);
      return;
    }
    if (request.method !== 'GET' || !/^\/(css|js)\/[a-zA-Z0-9_.-]+$/.test(url.pathname)) { response.writeHead(404); response.end(); return; }
    try {
      const content = await fs.readFile(path.join(root, 'public', url.pathname));
      response.writeHead(200, {'Content-Type': url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css'});
      response.end(content);
    } catch (_) { response.writeHead(404); response.end(); }
  });
  let browser;
  try {
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    const origin = `http://127.0.0.1:${server.address().port}`;
    const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined);
    browser = await chromium.launch({headless: true, ...(executablePath ? {executablePath} : {})});
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}, acceptDownloads: true});
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], {origin});
    await context.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== origin) { external.push(request.url()); await route.abort(); return; }
      if (request.method() !== 'POST') { await route.continue(); return; }
      const data = request.postDataJSON();
      posts.push({path: url.pathname, data});
      assert.equal(data._token, 'synthetic-csrf-token');
      if (url.pathname.endsWith('/preview')) { await route.fulfill({status: 200, json: previewResponse}); return; }
      assert.ok(url.pathname.endsWith('/generate'), 'Only prepare-email local endpoints may be posted');
      if (holdGenerate) await holdGenerate;
      await route.fulfill({status: generateResponse.status, json: generateResponse.body});
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    const q = name => page.locator(`[data-email-${name}]`);
    const waitStatus = async () => page.waitForFunction(() => document.querySelector('[data-email-preparation]').getAttribute('aria-busy') === 'false');
    const preview = async () => { await q('preview').click(); await waitStatus(); assert.equal(await q('preview-content').isVisible(), true); };
    const generate = async () => { await q('generate').click(); await waitStatus(); };
    const unloadBlocked = () => page.evaluate(() => {
      const event = new Event('beforeunload', {cancelable: true});
      window.dispatchEvent(event);
      return event.defaultPrevented;
    });
    for (const locale of ['de', 'en']) {
      for (const width of [320, 390, 768, 960, 1440]) {
        await page.setViewportSize({width, height: 1000});
        await page.goto(`${origin}/fixture/${locale}`);
        await page.waitForFunction(() => document.querySelector('[data-email-preparation]').getAttribute('aria-busy') === 'false');
        const geometry = await page.evaluate(() => ({viewport: innerWidth, width: document.documentElement.scrollWidth, mainWidth: document.querySelector('.email-main').scrollWidth, mainClient: document.querySelector('.email-main').clientWidth}));
        assert.ok(geometry.width <= geometry.viewport + 1, `${locale}/${width}: page overflow ${JSON.stringify(geometry)}`);
        assert.ok(geometry.mainWidth <= geometry.mainClient + 1, `${locale}/${width}: main overflow ${JSON.stringify(geometry)}`);
        if (locale === 'de' && [390, 768, 1440].includes(width)) await page.screenshot({path: path.join(output, `empty-${locale}-${width}.png`)});
      }
    }
    assert.equal(posts.length, 0, 'Loading never starts preview or generation');
    mark('DE/EN layout at 320, 390, 768, 960 and 1440; no page/main horizontal overflow or automatic requests');

    await page.setViewportSize({width: 1440, height: 1000});
    await page.goto(`${origin}/fixture/en`);
    assert.equal(await q('generate').isVisible(), false);
    await preview();
    assert.equal(posts.filter(post => post.path.endsWith('/generate')).length, 0);
    assert.equal(await q('payload').textContent(), JSON.stringify(payload, null, 2));
    assert.equal(await page.evaluate(() => Boolean(window.EMAIL_INJECTION)), false);
    assert.equal(await q('facts').locator('script').count(), 0);
    await page.locator('.email-payload summary').click();
    const [requestDownload] = await Promise.all([page.waitForEvent('download'), q('download-request').click()]);
    assert.deepEqual(JSON.parse(await fs.readFile(await requestDownload.path(), 'utf8')), payload);
    await q('language').selectOption('de');
    assert.equal(await q('preview-content').isVisible(), false);
    await preview();
    assert.equal(posts.at(-1).data.language, 'de');
    mark('Preview shows exact escaped payload, JSON download matches, changing language invalidates preview');

    holdGenerate = new Promise(resolve => { releaseGenerate = resolve; });
    await q('generate').click();
    await page.waitForFunction(() => document.querySelector('[data-email-generate]').disabled);
    await q('generate').evaluate(button => { button.click(); button.click(); });
    assert.equal(posts.filter(post => post.path.endsWith('/generate')).length, 1);
    assert.equal(await q('language').isDisabled(), true);
    releaseGenerate(); holdGenerate = null;
    await waitStatus();
    assert.equal(await q('body').inputValue(), generateResponse.body.body);
    assert.equal(await q('generate').isDisabled(), true);
    assert.equal(await q('replace-confirm').isChecked(), false);
    await q('subject').fill('My edited subject');
    await q('body').fill('My edited message with exact PoC %22+%3Cscript%3E');
    await q('signature').fill('PRIVATE LOCAL SIGNATURE');
    assert.equal(await unloadBlocked(), true, 'Unexported edits guard navigation');
    mark('Held generation permits one request, locks language, creates editable draft and requires explicit replacement');

    await q('copy').click();
    assert.ok((await page.evaluate(() => navigator.clipboard.readText())).includes('PRIVATE LOCAL SIGNATURE'));
    assert.equal(await unloadBlocked(), true, 'Copying body alone must retain navigation guard for subject');
    await q('copy-subject').click();
    assert.equal(await page.evaluate(() => navigator.clipboard.readText()), 'My edited subject');
    assert.equal(await unloadBlocked(), false, 'Copying both subject and body clears navigation guard');
    await q('subject').fill('Edited after copy');
    assert.equal(await unloadBlocked(), true, 'Editing after copy restores navigation guard');
    await q('subject').fill('My edited subject');
    await page.evaluate(() => {
      window.emailOriginalWriteText = navigator.clipboard.writeText.bind(navigator.clipboard);
      navigator.clipboard.writeText = () => new Promise(resolve => { window.emailReleaseClipboard = resolve; });
    });
    await q('copy').click();
    await q('body').fill('Edited while clipboard was pending');
    await page.evaluate(() => { window.emailReleaseClipboard(); navigator.clipboard.writeText = window.emailOriginalWriteText; });
    assert.equal(await unloadBlocked(), true, 'Late clipboard completion cannot clear unsaved newer edits');
    await q('body').fill('My edited message with exact PoC %22+%3Cscript%3E');
    const [draftDownload] = await Promise.all([page.waitForEvent('download'), q('download').click()]);
    const downloaded = await fs.readFile(await draftDownload.path(), 'utf8');
    assert.ok(downloaded.includes('My edited subject'));
    assert.ok(downloaded.includes('My edited message with exact PoC %22+%3Cscript%3E'));
    assert.ok(downloaded.includes('PRIVATE LOCAL SIGNATURE'));
    assert.equal(await unloadBlocked(), false, 'Full draft download clears navigation guard');
    mark('Draft download/clipboard preserve edits and local signature; navigation guarded until subject and body exported');

    await q('replace-confirm').check();
    generateResponse = {status: 502, body: {ok: false, code: 'provider_failed', error: 'Synthetic upstream error'}};
    await generate();
    assert.equal(await q('subject').inputValue(), 'My edited subject');
    assert.ok((await q('body').inputValue()).startsWith('My edited message'));
    assert.equal(await q('signature').inputValue(), 'PRIVATE LOCAL SIGNATURE');
    assert.equal(await q('status').textContent(), 'Synthetic upstream error');
    assert.equal(await q('body').getAttribute('readonly'), null);
    generateResponse = {status: 503, body: {ok: false, code: 'not_configured', error: 'Synthetic connection no longer configured'}};
    await generate();
    assert.equal(await q('subject').inputValue(), 'My edited subject');
    assert.ok((await q('body').inputValue()).startsWith('My edited message'));
    generateResponse = {status: 409, body: {ok: false, code: 'stale_preview', error: 'Synthetic stale preview'}};
    await generate();
    assert.equal(await q('preview-content').isVisible(), false);
    assert.equal(await q('subject').inputValue(), 'My edited subject');
    assert.ok((await q('status').textContent()).includes('changed'));
    assert.equal(posts.some(post => JSON.stringify(post.data).includes('PRIVATE LOCAL SIGNATURE')), false);
    mark('Provider failure retains edits; stale conflict invalidates preview and keeps existing draft; signature never leaves tab');

    for (const width of [390, 768, 1440]) {
      await page.setViewportSize({width, height: 1000});
      await q('subject').scrollIntoViewIfNeeded();
      await page.screenshot({path: path.join(output, `draft-en-${width}.png`)});
      assert.ok(await page.evaluate(() => document.querySelector('.email-main').scrollWidth <= document.querySelector('.email-main').clientWidth + 1));
    }
    await page.goto(`${origin}/fixture/en?configured=0`);
    await preview();
    assert.equal(await q('generate').isDisabled(), true);
    assert.ok((await page.locator('.email-notice').textContent()).includes('not configured'));
    await page.goto(`${origin}/fixture/de?eligible=0`);
    assert.equal(await q('preview').isDisabled(), true);
    mark('Unconfigured connection still allows payload preview; ineligible case blocks preview; populated editor remains responsive');
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    mark('No browser runtime errors or external requests');
    await fs.writeFile(path.join(output, 'receipt.json'), JSON.stringify({checks, errors, external, posts: posts.map(post => ({path: post.path, keys: Object.keys(post.data)}))}, null, 2));
  } finally {
    if (releaseGenerate) releaseGenerate();
    try { await browser?.close(); }
    finally { if (server.listening) await new Promise(resolve => server.close(resolve)); }
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
