/*
 * Synthetic UI contract test: renders only the prepare-email template, serves
 * static assets on loopback, and mocks every preview, generate and Gmail
 * response. It never boots the app, opens a case database, or calls a provider.
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
$initialState['gmail'] = json_decode(getenv('EMAIL_FIXTURE_GMAIL'), true, 512, JSON_THROW_ON_ERROR);
require getcwd().'/templates/studio/prepare-email.php';
`;
const initialGmail = {configured: true, account: 'writer@example.test', canCreate: true, receipt: null};
const receipt = (attemptId, extra = {}) => ({state: 'verified', attemptId, draftId: 'synthetic-draft-id', messageId: 'synthetic-message-id', gmailUrl: 'https://mail.google.com/mail/u/writer@example.test/#drafts', account: 'writer@example.test', stale: false, canCreate: false, ...extra});
const existingAttempt = '40fa4036-764e-4995-889e-77440dd76847';
const render = (locale, configured = true, eligible = true, gmail = initialGmail) => execFileSync('php', ['-r', php], {cwd: root, encoding: 'utf8', env: {...process.env, EMAIL_FIXTURE_LOCALE: locale, EMAIL_FIXTURE_CONFIGURED: configured ? '1' : '0', EMAIL_FIXTURE_ELIGIBLE: eligible ? '1' : '0', EMAIL_FIXTURE_GMAIL: JSON.stringify(gmail)}});
const html = Object.fromEntries(['de', 'en'].map(locale => [locale, render(locale)]));
const checks = [];
const mark = text => { checks.push(text); console.log('PASS ' + text); };
const errors = [];
const external = [];
const posts = [];
const gmailReads = [];
let generateResponse = {status: 200, body: {ok: true, subject: 'Security report for example.test', body: 'Confirmed XSS.\n\nhttps://example.test/?q=%22+%3Cscript%3E\n\nPlease review this finding.', recipient: 'security@example.test', channel: 'email', model: 'gpt-6.2-sol', language: 'en'}};
let holdGenerate = null;
let releaseGenerate = null;
let holdGmail = null;
let releaseGmail = null;
let gmailCreateMode = 'verified';
let gmailStatus = null;
let reconcileStatus = null;
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
      const gmail = {...initialGmail};
      if (url.searchParams.has('receipt')) {
        gmail.receipt = receipt(existingAttempt, {stale: url.searchParams.get('receipt') === 'stale', ...(url.searchParams.get('receipt') === 'unsafe' ? {gmailUrl: 'javascript:window.EMAIL_INJECTION=true'} : {})});
        gmail.canCreate = false;
        if (url.searchParams.get('receipt') === 'rejected-safe') {
          gmail.account = 'new-writer@example.test';
          gmail.canCreate = true;
          gmail.receipt = receipt(existingAttempt, {state: 'rejected', draftId: null, messageId: null, gmailUrl: null, canCreate: true});
        }
      }
      response.end(url.search ? render(locale, url.searchParams.get('configured') !== '0', url.searchParams.get('eligible') !== '0', gmail) : html[locale]);
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
      if (request.method() === 'GET' && url.pathname.endsWith('/gmail-draft')) {
        gmailReads.push(url.pathname);
        await route.fulfill({status: 200, json: {ok: true, ...gmailStatus}});
        return;
      }
      if (request.method() !== 'POST') { await route.continue(); return; }
      const data = request.postDataJSON();
      posts.push({path: url.pathname, data});
      assert.equal(data._token, 'synthetic-csrf-token');
      if (url.pathname.endsWith('/preview')) {
        await route.fulfill({status: 200, json: {...previewResponse, language: data.language, manualDraft: {subject: `Local ${data.language} subject`, body: `Local ${data.language} factual text\n\nhttps://example.test/?q=%22+%3Cscript%3E`, recipient: previewResponse.contact.destination, channel: previewResponse.contact.channel, language: data.language, fingerprint: previewResponse.fingerprint}}});
        return;
      }
      if (url.pathname.endsWith('/gmail-draft/reconcile')) {
        await route.fulfill({status: 200, json: {ok: true, ...reconcileStatus}});
        return;
      }
      if (url.pathname.endsWith('/gmail-draft')) {
        if (holdGmail) await holdGmail;
        if (gmailCreateMode === 'abort') { await route.abort('timedout'); return; }
        if (gmailCreateMode === 'account-changed') { await route.fulfill({status: 409, json: {ok: false, code: 'account_changed', error: 'Synthetic Gmail account changed', configured: true, account: 'new-writer@example.test', receipt: null, canCreate: true}}); return; }
        if (gmailCreateMode === 'foreign') { await route.fulfill({status: 409, json: {ok: false, code: 'existing_attempt', error: 'Existing attempt from another tab', receipt: receipt(existingAttempt), canCreate: false}}); return; }
        if (gmailCreateMode.startsWith('rejected')) { const canCreate = ['rejected-safe', 'rejected-account', 'rejected-success'].includes(gmailCreateMode); const successStatus = gmailCreateMode === 'rejected-success'; await route.fulfill({status: successStatus ? 200 : 502, json: {ok: successStatus, code: 'rejected', error: 'Synthetic rejected create', ...(gmailCreateMode === 'rejected-account' ? {configured: true, account: 'new-writer@example.test'} : {}), receipt: receipt(data.attemptId, {state: 'rejected', draftId: null, messageId: null, gmailUrl: null, canCreate}), canCreate}}); return; }
        await route.fulfill({status: 200, json: {ok: true, receipt: receipt(data.attemptId, {account: data.expectedAccount, gmailUrl: `https://mail.google.com/mail/u/${data.expectedAccount}/#drafts`}), canCreate: false}});
        return;
      }
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

    const gmailWrites = () => posts.filter(post => post.path.endsWith('/gmail-draft'));
    const reconciles = () => posts.filter(post => post.path.endsWith('/gmail-draft/reconcile'));
    const startManual = async (options = '') => {
      await page.goto(`${origin}/fixture/en${options ? '?' + options : ''}`);
      await preview();
      await q('manual').click();
      assert.equal(await q('editor').isVisible(), true);
    };
    const gmailCreate = async () => { await q('gmail-reviewed').check(); await q('gmail-create').click(); await waitStatus(); };

    const noAiBefore = posts.filter(post => post.path.endsWith('/generate')).length;
    const noGmailBefore = gmailWrites().length;
    await startManual('configured=0');
    assert.equal(await q('draft-source').textContent(), 'Local');
    assert.equal(await q('generate').isDisabled(), true);
    assert.equal(await q('subject').inputValue(), 'Local en subject');
    await q('copy').click();
    const [manualDownload] = await Promise.all([page.waitForEvent('download'), q('download').click()]);
    assert.ok((await fs.readFile(await manualDownload.path(), 'utf8')).includes('Local en factual text'));
    assert.equal(posts.filter(post => post.path.endsWith('/generate')).length, noAiBefore);
    assert.equal(gmailWrites().length, noGmailBefore);
    assert.equal(gmailReads.length, 0);
    assert.equal(await q('gmail-create').isDisabled(), true);
    await q('gmail-reviewed').check();
    assert.equal(await q('gmail-create').isDisabled(), false);
    await q('signature').fill('Local signature for Gmail');
    assert.equal(await q('gmail-reviewed').isChecked(), false);
    assert.equal(await q('gmail-create').isDisabled(), true);
    mark('Manual draft works without OpenAI; preview/copy/download make no Gmail call; editing resets explicit review');

    await page.goto(`${origin}/fixture/de`);
    previewResponse.fingerprint = 'original-de-fingerprint';
    await preview(); await q('manual').click();
    await q('subject').fill('Edited Gmail subject');
    await q('body').fill('Edited Gmail body\n\nhttps://example.test/?q=%22+%3Cscript%3E');
    await q('signature').fill('LOCAL SIGNATURE');
    await q('language').selectOption('en');
    previewResponse.fingerprint = 'new-en-fingerprint';
    await preview(); await q('replace-confirm').check();
    generateResponse = {status: 502, body: {ok: false, code: 'provider_failed', error: 'Failed replacement generation'}};
    await generate();
    assert.equal(await q('body').inputValue(), 'Edited Gmail body\n\nhttps://example.test/?q=%22+%3Cscript%3E');
    holdGmail = new Promise(resolve => { releaseGmail = resolve; });
    await q('gmail-reviewed').check();
    await q('gmail-create').click();
    await page.waitForFunction(() => document.querySelector('[data-email-gmail-create]').disabled);
    await q('gmail-create').evaluate(button => { button.click(); button.click(); });
    assert.equal(gmailWrites().length, noGmailBefore + 1);
    const submitted = gmailWrites().at(-1).data;
    assert.equal(submitted.language, 'de');
    assert.equal(submitted.fingerprint, 'original-de-fingerprint');
    assert.equal(submitted.subject, 'Edited Gmail subject');
    assert.equal(submitted.body, 'Edited Gmail body\n\nhttps://example.test/?q=%22+%3Cscript%3E\n\nLOCAL SIGNATURE');
    assert.equal(submitted.reviewed, true);
    assert.equal(submitted.expectedAccount, 'writer@example.test', 'Create is bound to the displayed configured account');
    assert.match(submitted.attemptId, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
    for (const field of ['subject', 'body', 'signature']) assert.equal(await q(field).getAttribute('readonly'), '');
    assert.equal(await q('manual').isDisabled(), true);
    assert.equal(await q('generate').isDisabled(), true);
    releaseGmail(); holdGmail = null;
    await waitStatus();
    assert.equal(await q('gmail-receipt').getAttribute('data-state'), 'verified');
    assert.equal(await q('gmail-id').textContent(), 'synthetic-draft-id');
    assert.equal(await q('gmail-account').textContent(), 'writer@example.test');
    assert.equal(await q('gmail-open').getAttribute('href'), receipt(submitted.attemptId).gmailUrl);
    assert.equal(await unloadBlocked(), false, 'Verified exact submitted version clears local unsaved guard');
    mark('Gmail preserves original draft language/fingerprint after failed replacement; one explicit create stores edited body and signature');

    const beforeReceiptLoad = {writes: gmailWrites().length, reads: gmailReads.length, reconciles: reconciles().length};
    await page.goto(`${origin}/fixture/en?receipt=verified`);
    assert.equal(await q('gmail-receipt').getAttribute('data-state'), 'verified');
    assert.equal(await q('gmail-open').isVisible(), true);
    await preview();
    assert.equal(await q('manual').isDisabled(), true);
    assert.equal(await q('generate').isDisabled(), true);
    assert.deepEqual({writes: gmailWrites().length, reads: gmailReads.length, reconciles: reconciles().length}, beforeReceiptLoad);
    await page.goto(`${origin}/fixture/en?receipt=unsafe`);
    assert.equal(await q('gmail-open').isVisible(), false);
    assert.equal(await q('gmail-open').getAttribute('href'), null);
    assert.equal(await page.evaluate(() => Boolean(window.EMAIL_INJECTION)), false);
    await page.goto(`${origin}/fixture/en?receipt=stale`);
    assert.equal(await q('gmail-stale').isVisible(), true);
    assert.ok((await q('gmail-stale').textContent()).includes('may already exist'));
    reconcileStatus = {receipt: receipt(existingAttempt, {stale: true}), canCreate: false};
    await q('gmail-check').click(); await waitStatus();
    assert.ok((await q('status').textContent()).includes('changed'), 'Stale verification is not presented as current success');
    for (const width of [390, 768, 1440]) {
      await page.setViewportSize({width, height: 1000});
      await q('gmail').scrollIntoViewIfNeeded();
      assert.ok(await page.evaluate(() => document.querySelector('.email-main').scrollWidth <= document.querySelector('.email-main').clientWidth + 1));
      await page.screenshot({path: path.join(output, `gmail-receipt-en-${width}.png`)});
    }
    mark('Existing verified/stale receipts survive reload without provider calls; unsafe Gmail links hidden; receipt responsive at 390–1440');

    await page.setViewportSize({width: 1440, height: 1000});
    await startManual();
    gmailCreateMode = 'abort';
    const beforeUnknown = gmailWrites().length;
    await gmailCreate();
    const uncertainAttempt = gmailWrites().at(-1).data.attemptId;
    assert.equal(gmailWrites().length, beforeUnknown + 1);
    assert.equal(await q('gmail-receipt').getAttribute('data-state'), 'uncertain');
    assert.equal(await q('gmail-create').isDisabled(), true);
    assert.equal(await q('body').getAttribute('readonly'), '');
    assert.equal(await unloadBlocked(), true);
    gmailStatus = {...initialGmail, canCreate: false, receipt: receipt(uncertainAttempt, {state: 'uncertain', draftId: null, messageId: null, gmailUrl: null})};
    const priorStatusReads = gmailReads.length;
    const priorReconciles = reconciles().length;
    await q('gmail-check').click(); await waitStatus();
    assert.equal(gmailReads.length, priorStatusReads + 1);
    assert.equal(reconciles().length, priorReconciles);
    reconcileStatus = {receipt: receipt(uncertainAttempt), canCreate: false};
    await q('gmail-check').click(); await waitStatus();
    assert.equal(reconciles().length, priorReconciles + 1);
    assert.equal(reconciles().at(-1).data.attemptId, uncertainAttempt);
    assert.equal(gmailWrites().length, beforeUnknown + 1);
    assert.equal(await q('gmail-receipt').getAttribute('data-state'), 'verified');
    assert.equal(await unloadBlocked(), false);
    mark('Network-unknown create locks original attempt; explicit local-status then reconcile resolves it without another create');

    await startManual();
    await q('subject').fill('My separate local version');
    gmailCreateMode = 'foreign';
    await gmailCreate();
    const foreignLocalAttempt = gmailWrites().at(-1).data.attemptId;
    assert.notEqual(foreignLocalAttempt, existingAttempt);
    assert.equal(await q('subject').inputValue(), 'My separate local version');
    assert.equal(await unloadBlocked(), true, 'Foreign receipt cannot mark local text stored');
    assert.ok((await q('gmail-help').textContent()).includes('did not save the local text'));
    reconcileStatus = {receipt: receipt(existingAttempt), canCreate: false};
    await q('gmail-check').click(); await waitStatus();
    assert.equal(reconciles().at(-1).data.attemptId, existingAttempt);
    assert.equal(await unloadBlocked(), true, 'Reconciling a foreign receipt still cannot mark local text stored');
    mark('Cross-tab foreign receipt keeps local edits dirty and identifies that the visible text was not saved');

    await startManual();
    gmailCreateMode = 'rejected-safe';
    await gmailCreate();
    const firstRejectedAttempt = gmailWrites().at(-1).data.attemptId;
    assert.equal(await q('gmail-receipt').getAttribute('data-state'), 'rejected');
    assert.equal(await q('body').getAttribute('readonly'), null);
    assert.equal(await q('gmail-reviewed').isChecked(), false);
    assert.equal(await q('gmail-create').isDisabled(), true);
    const writesBeforeRetry = gmailWrites().length;
    await q('gmail-create').evaluate(button => button.click());
    assert.equal(gmailWrites().length, writesBeforeRetry);
    gmailCreateMode = 'verified';
    await gmailCreate();
    assert.equal(gmailWrites().length, writesBeforeRetry + 1);
    assert.notEqual(gmailWrites().at(-1).data.attemptId, firstRejectedAttempt);
    await startManual();
    gmailCreateMode = 'rejected-blocked';
    await gmailCreate();
    assert.equal(await q('gmail-receipt').getAttribute('data-state'), 'rejected');
    assert.equal(await q('gmail-create').isDisabled(), true);
    assert.equal(await q('gmail-reviewed').isDisabled(), true);
    assert.equal(await q('body').getAttribute('readonly'), '');
    mark('Only authoritative rejected/canCreate=true unlocks fresh review and a new explicit UUID; other rejections stay locked');

    await startManual();
    gmailCreateMode = 'rejected-success';
    await gmailCreate();
    assert.equal(await q('gmail-receipt').getAttribute('data-state'), 'rejected');
    assert.ok((await q('status').textContent()).includes('No Gmail draft was created'));
    assert.equal(await q('gmail-create').isDisabled(), true);
    mark('HTTP 200 with authoritative safe rejection is shown as known not-created, with fresh review required');

    await startManual();
    gmailCreateMode = 'account-changed';
    await gmailCreate();
    assert.equal(await q('preview-content').isVisible(), false);
    assert.equal(await q('gmail-account').textContent(), 'new-writer@example.test');
    assert.equal(await q('gmail-create').isDisabled(), true);
    assert.equal(await q('gmail-reviewed').isChecked(), false);
    await preview();
    await q('gmail-reviewed').check();
    assert.equal(await q('gmail-create').isDisabled(), true, 'Refreshing preview alone cannot rebind an older draft');
    await q('replace-confirm').check(); await q('manual').click();
    gmailCreateMode = 'verified';
    await gmailCreate();
    assert.equal(gmailWrites().at(-1).data.expectedAccount, 'new-writer@example.test');
    mark('Account-change rejection invalidates draft context; explicit new draft and review bind next request to the new displayed account');

    await startManual();
    gmailCreateMode = 'rejected-account';
    await gmailCreate();
    assert.equal(await q('gmail-account').textContent(), 'new-writer@example.test', 'Retry must display the current target account, not historical rejected receipt account');
    assert.equal(await q('gmail-receipt-account-wrap').isVisible(), true);
    assert.equal(await q('gmail-receipt-account').textContent(), 'writer@example.test');
    gmailCreateMode = 'verified';
    await gmailCreate();
    assert.equal(gmailWrites().at(-1).data.expectedAccount, 'new-writer@example.test');
    mark('Safe retry after account update displays and submits the same current Gmail account');

    await page.goto(`${origin}/fixture/en?receipt=rejected-safe`);
    assert.equal(await q('gmail-account').textContent(), 'new-writer@example.test');
    reconcileStatus = receipt(existingAttempt, {state: 'rejected', draftId: null, messageId: null, gmailUrl: null, canCreate: true});
    await q('gmail-check').click(); await waitStatus();
    assert.equal(await q('gmail-account').textContent(), 'new-writer@example.test', 'Flat historical receipt cannot change configured account');
    assert.equal(await q('gmail-receipt-account').textContent(), 'writer@example.test');
    await preview(); await q('manual').click();
    await gmailCreate();
    assert.equal(gmailWrites().at(-1).data.expectedAccount, 'new-writer@example.test');
    mark('Reload and flat reconciliation keep historical receipt account separate from current retry account');

    previewResponse.contact = {channel: 'web', destination: 'https://example.test/report'};
    await startManual();
    assert.equal(await q('gmail-create').isDisabled(), true);
    assert.equal(await q('gmail-reviewed').isDisabled(), true);
    assert.ok((await q('gmail-help').textContent()).includes('reporting portal'));
    mark('Portal contact routes offer local text and keep Gmail creation disabled');
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    mark('No browser runtime errors or external requests');
    await fs.writeFile(path.join(output, 'receipt.json'), JSON.stringify({checks, errors, external, gmailReads, posts: posts.map(post => ({path: post.path, keys: Object.keys(post.data)}))}, null, 2));
  } finally {
    if (releaseGenerate) releaseGenerate();
    if (releaseGmail) releaseGmail();
    try { await browser?.close(); }
    finally { if (server.listening) await new Promise(resolve => server.close(resolve)); }
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
