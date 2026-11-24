/* Synthetic Hunter UI checks: production partials/CSS, no app kernel or database.
 * Every POST is intercepted locally; no Hunter request is made.
 * Run: node tests/browser/hunter-contact.cjs
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
const output = path.resolve(process.env.HUNTER_UI_OUTPUT || path.join(root, 'var/hunter-contact-ui'));
assert.equal(path.dirname(output), path.join(root, 'var'));
assert.match(path.basename(output), /^hunter-contact-ui(?:-[a-zA-Z0-9_-]+)?$/);
const domain = 'long-security-review-subdomain.company-with-a-long-name.example.org';
const php = String.raw`
require getcwd().'/vendor/autoload.php';
$locale = getenv('HUNTER_FIXTURE_LOCALE') ?: 'en';
$i18n = new \App\Service\UiTranslator($locale);
$t = fn(string $key, array $params = []): string => $i18n->trans($key, $params);
$escape = fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$finding = (new \App\Entity\Finding())->setDomain((new \App\Entity\Domain())->setHostname(getenv('HUNTER_FIXTURE_DOMAIN')));
$findingPath = '/findings/'.$finding->getId();
$csrfField = fn(string $key): string => '<input type="hidden" name="_token" value="synthetic-hunter-csrf">';
$returnField = '<input type="hidden" name="return_to" value="/findings?view=workbasket">';
$contactProviderOptions = ['hunter' => json_decode(getenv('HUNTER_FIXTURE_OPTIONS'), true, 32, JSON_THROW_ON_ERROR)];
$contact = ['hunter' => ['type' => 'generic', 'provenance' => 'found', 'confidence' => 87,
    'verification' => 'accept_all', 'department' => str_repeat('SecurityAndEngineering', 5),
    'position' => '<script>window.HUNTER_INJECTION = true</script>',
    'sources' => [
      ['url' => 'https://sources.example.invalid/'.str_repeat('long-source-path-', 16).'?example=%3Cscript%3E&value=synthetic', 'last_seen_on' => '2026-11-20'],
      ['url' => 'javascript:window.HUNTER_INJECTION=true', 'last_seen_on' => null],
    ],
]];
?>
<!doctype html><html lang="<?= $escape($locale) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Synthetic Hunter contact UI</title>
<link rel="stylesheet" href="/css/studio.css"><link rel="stylesheet" href="/css/studio-detail.css"><link rel="stylesheet" href="/css/studio-follow-up.css">
</head><body data-studio><main>
<div class="studio-follow-up-intro"><p class="studio-follow-up-kicker"><?= $escape($t('Kontakt und Nachverfolgung')) ?></p><p>Isolated synthetic layout fixture</p></div>
<div class="studio-follow-up-grid">
  <section class="studio-work-card"><h2><?= $escape($t('Nachverfolgung')) ?></h2><p>Synthetic adjacent card. No live case data.</p></section>
  <section class="studio-work-card" id="kontakte"><h2><?= $escape($t('Kontaktvorschläge')) ?></h2>
    <?php require getcwd().'/templates/studio/hunter-contact-discovery.php'; ?>
    <ol class="studio-record-list"><li class="studio-record" data-hunter-fixture-result><h3><?= $escape($t('Kontaktvorschläge gefunden')) ?></h3>
      <p><?= $escape($t('E-Mail')) ?>: <code>security@example.org</code></p>
      <?php require getcwd().'/templates/studio/hunter-contact-metadata.php'; ?>
    </li></ol>
  </section>
</div></main></body></html>
`;
const ready = {configured: true, available: true, blocked: false, cached: false, reserved: 0, limit: 4};
const states = {
  ready,
  unconfigured: {...ready, configured: false},
  unavailable: {...ready, available: false},
  pending: {...ready, blocked: true, reserved: 1},
  cached: {...ready, cached: true, reserved: 4},
  exhausted: {...ready, reserved: 4},
};
const render = (locale, state) => execFileSync('php', ['-r', php], {cwd: root, encoding: 'utf8', env: {...process.env,
  HUNTER_FIXTURE_LOCALE: locale, HUNTER_FIXTURE_DOMAIN: domain, HUNTER_FIXTURE_OPTIONS: JSON.stringify(states[state])}});
const pages = Object.fromEntries(['de', 'en'].flatMap(locale => Object.keys(states).map(state => [`${locale}/${state}`, render(locale, state)])));

async function main() {
  await fs.mkdir(output, {recursive: true});
  const checks = [], layouts = [], posts = [], errors = [], external = [];
  const mark = message => { checks.push(message); console.log('PASS ' + message); };
  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    const key = url.pathname.replace(/^\/fixture\//, '');
    if (pages[key]) { response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'}); response.end(pages[key]); return; }
    if (request.method !== 'GET' || !/^\/css\/[a-zA-Z0-9_.-]+$/.test(url.pathname)) { response.writeHead(404); response.end(); return; }
    try { response.writeHead(200, {'Content-Type': 'text/css'}); response.end(await fs.readFile(path.join(root, 'public', url.pathname))); }
    catch (_) { response.writeHead(404); response.end(); }
  });
  let browser;
  try {
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    const origin = `http://127.0.0.1:${server.address().port}`;
    const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined);
    browser = await chromium.launch({headless: true, ...(executablePath ? {executablePath} : {})});
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    await context.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url());
      if (url.origin !== origin) { external.push(request.url()); await route.abort(); return; }
      if (request.method() === 'POST') {
        const values = Object.fromEntries(new URLSearchParams(request.postData()));
        posts.push({path: url.pathname, values});
        await route.fulfill({status: 200, contentType: 'text/html', body: '<p data-fixture-posted>Only the synthetic local endpoint was reached.</p>'});
        return;
      }
      await route.continue();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const form = () => page.locator('[data-hunter-discovery]');
    const expand = async () => {
      await page.locator('[data-hunter-discovery-panel] > summary').click();
      await page.locator('[data-hunter-fixture-result] details > summary').click();
    };
    const geometry = () => page.evaluate(() => {
      const selectors = ['main', '.studio-follow-up-grid', '#kontakte', '[data-hunter-discovery-panel]', '[data-hunter-fixture-result]'];
      return {viewport: innerWidth, document: document.documentElement.scrollWidth,
        elements: selectors.map(selector => { const element = document.querySelector(selector); const bounds = element.getBoundingClientRect(); return {selector, client: element.clientWidth, scroll: element.scrollWidth, left: bounds.left, right: bounds.right}; })};
    });
    for (const locale of ['de', 'en']) {
      for (const width of [320, 390, 768, 1440]) {
        await page.setViewportSize({width, height: 1000});
        await page.goto(`${origin}/fixture/${locale}/ready`);
        await expand();
        const bounds = await geometry();
        layouts.push({locale, width, ...bounds});
        assert.ok(bounds.document <= width + 1, `Page overflow ${locale}/${width}: ${JSON.stringify(bounds)}`);
        for (const element of bounds.elements) {
          assert.ok(element.scroll <= element.client + 1, `Element overflow ${locale}/${width}: ${JSON.stringify(element)}`);
          assert.ok(element.left >= -1 && element.right <= width + 1, `Clipped element ${locale}/${width}: ${JSON.stringify(element)}`);
        }
        assert.equal(await page.evaluate(() => Boolean(window.HUNTER_INJECTION)), false);
        assert.equal(await page.locator('[data-hunter-fixture-result] script').count(), 0);
        assert.equal(await page.locator('[data-hunter-fixture-result] a[href^="javascript:"]').count(), 0);
        assert.equal(await form().locator('input[name="expected_domain"]').inputValue(), domain);
        if ([390, 768, 1440].includes(width)) await page.screenshot({path: path.join(output, `ready-${locale}-${width}.png`), fullPage: true});
      }
    }
    assert.equal(posts.length, 0);
    assert.deepEqual(external, []);
    mark('Eight DE/EN layouts at 320–1440px: long domain, source URL and metadata stay inside the real two-column/contact-card layout');
    mark('Expanded source data is escaped; unsafe source links are not clickable; opening panels makes no provider request');

    for (const state of ['unconfigured', 'unavailable', 'pending', 'exhausted']) {
      await page.setViewportSize({width: 390, height: 1000});
      await page.goto(`${origin}/fixture/en/${state}`);
      await expand();
      assert.equal(await form().locator('button').isDisabled(), true, state);
      assert.equal(await form().locator('input[name="consent"]').isDisabled(), true, state);
      const bounds = await geometry();
      assert.ok(bounds.document <= bounds.viewport + 1, `${state}: page overflow`);
      for (const element of bounds.elements) assert.ok(element.scroll <= element.client + 1, `${state}: ${JSON.stringify(element)}`);
      if (['pending', 'exhausted'].includes(state)) await page.screenshot({path: path.join(output, `${state}-en-390.png`), fullPage: true});
    }
    assert.equal(posts.length, 0);
    mark('Unconfigured, migration-required, pending/unknown and exhausted states disable consent and lookup');

    await page.goto(`${origin}/fixture/en/cached`);
    await expand();
    assert.equal(await form().locator('button').isEnabled(), true);
    assert.match(await page.locator('[data-hunter-discovery-panel]').innerText(), /reused without a provider request/);
    await page.screenshot({path: path.join(output, 'cached-en-390.png'), fullPage: true});
    assert.equal(await form().locator('input[name="consent"]').isChecked(), false);
    await form().locator('button').click();
    assert.equal(posts.length, 0, 'Native required acknowledgement stops the POST');
    assert.equal(await form().locator('input[name="consent"]').evaluate(element => element.validity.valueMissing), true);
    await form().locator('input[name="consent"]').focus();
    await page.keyboard.press('Space');
    assert.equal(await form().locator('input[name="consent"]').isChecked(), true);
    await page.keyboard.press('Tab');
    assert.equal(await form().locator('button').evaluate(element => document.activeElement === element), true);
    await page.keyboard.press('Enter');
    await page.waitForSelector('[data-fixture-posted]');
    assert.equal(posts.length, 1);
    assert.match(posts[0].path, /^\/findings\/[a-f0-9-]+\/contact-discovery$/);
    assert.deepEqual(posts[0].values, {_token: 'synthetic-hunter-csrf', return_to: '/findings?view=workbasket', provider: 'hunter', expected_domain: domain, consent: '1'});
    mark('A valid cache remains available at the four-reservation limit; native consent and keyboard submission send only the exact displayed domain');

    await page.goto(`${origin}/fixture/de/ready`);
    await expand();
    assert.equal(await form().locator('input[name="consent"]').isChecked(), false, 'Acknowledgement never persists across page loads');
    assert.equal(await form().locator('button').isEnabled(), true);
    assert.match(await page.locator('[data-hunter-discovery-panel]').innerText(), /Hunter-Credits/);
    assert.match(await page.locator('[data-hunter-fixture-result]').innerText(), /Anbieter-Konfidenz/);
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    mark('Fresh lookup resets acknowledgement; both languages retain cost/source meaning and no runtime errors or external requests occur');
    await fs.writeFile(path.join(output, 'receipt.json'), JSON.stringify({checks, layouts, posts, errors, external}, null, 2));
  } finally {
    try { await browser?.close(); }
    finally { if (server.listening) await new Promise(resolve => server.close(resolve)); }
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
