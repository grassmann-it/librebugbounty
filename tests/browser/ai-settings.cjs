/*
 * Synthetic native-form UI test. Renders the AI settings PHP template with
 * fixture values and serves static assets on loopback. POSTs are recorded and
 * answered by this fixture; database persistence and backend validation are
 * covered by PHP tests. No app boot, case database, provider or Gmail access.
 * Run: node tests/browser/ai-settings.cjs
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
const output = path.resolve(process.env.AI_SETTINGS_UI_OUTPUT || path.join(root, 'var/ai-settings-ui-20261124'));
assert.equal(path.dirname(output), path.join(root, 'var'));
assert.match(path.basename(output), /^ai-settings-ui-[a-zA-Z0-9_-]+$/);
const customId = '1c27938e-6fa0-4a81-9d7a-887a379bd2ce';
const injection = '</textarea><script>window.AI_PROFILE_INJECTION=true</script>';
const builtin = {id: 'builtin', name: 'Responsible disclosure', revision: 1, builtin: true, archived: false, isDefault: true, exampleCount: 0, instructions: '', examples: [], payloadHash: 'builtin-profile-hash'};
const custom = {id: customId, name: 'Short & personal', revision: 3, builtin: false, archived: false, isDefault: false, exampleCount: 1, instructions: 'Keep the introduction short and polite.', examples: [{name: 'Short introduction', text: `Hello team,\nA synthetic example only. ${injection}`}], payloadHash: 'custom-profile-hash'};
const archived = {...custom, id: '8bd274da-80d2-4b11-92fa-e2b8c29d2442', name: 'Archived example profile', archived: true, revision: 2};
const profiles = [builtin, custom, archived];
const history = [
  {revision: 3, createdAt: '2026-11-24T10:30:00+00:00', name: custom.name, instructions: custom.instructions, examples: custom.examples},
  {revision: 2, createdAt: '2026-11-23T08:15:00+00:00', name: `Earlier profile ${injection}`, instructions: `Earlier instructions ${injection}`, examples: [{name: 'Earlier example', text: `Earlier style text ${injection}`}]},
];
const php = String.raw`
require getcwd().'/vendor/autoload.php';
$fixture = json_decode(getenv('AI_SETTINGS_FIXTURE'), true, 512, JSON_THROW_ON_ERROR);
extract($fixture, EXTR_SKIP);
$i18n = new \App\Service\UiTranslator($locale);
$t = fn(string $key, array $params = []): string => $i18n->trans($key, $params);
require getcwd().'/templates/studio/ai-settings.php';
`;
const render = (locale, options = {}) => {
  const selected = options.new ? null : profiles.find(profile => profile.id === options.profile) || builtin;
  const selectedProfile = options.archivedConflict ? {...selected, archived: true} : selected;
  const formValues = options.values || {profileId: selectedProfile?.id || '', revision: selectedProfile?.revision || 0, name: selectedProfile?.name || '', instructions: selectedProfile?.instructions || '', example_names: selectedProfile?.examples.map(example => example.name) || [], example_texts: selectedProfile?.examples.map(example => example.text) || []};
  const fixture = {locale, profiles: options.archivedConflict ? profiles.map(profile => profile.id === selectedProfile.id ? selectedProfile : profile) : profiles, selectedProfile, formValues, errors: options.errors || {}, retainedEdit: Boolean(options.values && options.errors), profileHistory: selectedProfile?.id === customId ? history : [], message: options.message || null, csrfToken: 'synthetic-ai-settings-csrf', connection: {configured: options.configured !== false, model: 'gpt-6.2-sol', reasoning: 'medium'}, hunterConnection: {configured: false}, defaultProfileId: 'builtin', defaultRevision: 2, available: options.available !== false};
  return execFileSync('php', ['-r', php], {cwd: root, encoding: 'utf8', env: {...process.env, AI_SETTINGS_FIXTURE: JSON.stringify(fixture)}});
};
const checks = [], errors = [], external = [], posts = [];
const mark = text => { checks.push(text); console.log('PASS ' + text); };
let nextPostResult = 'success';
let fixtureLocale = 'en';

async function main() {
  await fs.mkdir(output, {recursive: true});
  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname === '/settings/ai' && request.method === 'POST') {
      let raw = '';
      for await (const chunk of request) raw += chunk;
      const form = new URLSearchParams(raw);
      const fields = Object.fromEntries(form.entries());
      fields.example_names = form.getAll('example_names[]');
      fields.example_texts = form.getAll('example_texts[]');
      posts.push(fields);
      if (nextPostResult === 'conflict' || nextPostResult === 'archived-conflict') {
        response.writeHead(409, {'Content-Type': 'text/html; charset=utf-8'});
        response.end(render(fixtureLocale, {profile: customId, archivedConflict: nextPostResult === 'archived-conflict', values: fields, errors: {revision: 'Synthetic conflict: another tab changed this profile. Your text is retained.'}}));
      } else {
        response.writeHead(303, {Location: `/settings/ai?profile=${encodeURIComponent(fields.profileId || customId)}&saved=1`});
        response.end();
      }
      return;
    }
    if (url.pathname === '/settings/ai' && request.method === 'GET') {
      fixtureLocale = url.searchParams.get('locale') === 'de' ? 'de' : (url.searchParams.get('locale') === 'en' ? 'en' : fixtureLocale);
      response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
      response.end(render(fixtureLocale, {profile: url.searchParams.get('profile'), new: url.searchParams.has('new'), configured: url.searchParams.get('configured') !== '0', available: url.searchParams.get('available') !== '0', message: url.searchParams.has('saved') ? 'Synthetic profile saved.' : null}));
      return;
    }
    if (request.method === 'GET' && /^\/(css|js)\/[a-zA-Z0-9_.-]+$/.test(url.pathname)) {
      try {
        response.writeHead(200, {'Content-Type': url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css'});
        response.end(await fs.readFile(path.join(root, 'public', url.pathname)));
      } catch (_) { response.writeHead(404); response.end(); }
      return;
    }
    response.writeHead(404); response.end();
  });
  let browser;
  try {
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    const origin = `http://127.0.0.1:${server.address().port}`;
    const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined);
    browser = await chromium.launch({headless: true, ...(executablePath ? {executablePath} : {})});
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    const guard = async route => {
      if (new URL(route.request().url()).origin !== origin) { external.push(route.request().url()); await route.abort(); }
      else await route.continue();
    };
    await context.route('**/*', guard);
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    const editor = () => page.locator('form').filter({has: page.locator('input[name="name"]')});
    const unloadBlocked = () => page.evaluate(() => {
      const event = new Event('beforeunload', {cancelable: true});
      window.dispatchEvent(event);
      return event.defaultPrevented;
    });
    for (const locale of ['de', 'en']) {
      for (const width of [320, 390, 768, 960, 1440]) {
        await page.setViewportSize({width, height: 1000});
        await page.goto(`${origin}/settings/ai?locale=${locale}&profile=${customId}`);
        const geometry = await page.evaluate(() => ({viewport: innerWidth, width: document.documentElement.scrollWidth, mainWidth: document.querySelector('main').scrollWidth, mainClient: document.querySelector('main').clientWidth}));
        assert.ok(geometry.width <= geometry.viewport + 1, `${locale}/${width}: page overflow ${JSON.stringify(geometry)}`);
        assert.ok(geometry.mainWidth <= geometry.mainClient + 1, `${locale}/${width}: main overflow ${JSON.stringify(geometry)}`);
        assert.equal(await page.locator('input[name="name"]').inputValue(), custom.name);
        assert.equal(await page.locator('textarea[name="example_texts[]"]').first().inputValue(), custom.examples[0].text);
        assert.equal(await page.evaluate(() => Boolean(window.AI_PROFILE_INJECTION)), false);
        if ([390, 768, 1440].includes(width)) await page.screenshot({path: path.join(output, `profile-${locale}-${width}.png`), fullPage: true});
      }
    }
    assert.equal(posts.length, 0);
    mark('DE/EN layouts at 320–1440 px preserve escaped example mail text without overflow or automatic requests');

    await page.setViewportSize({width: 1440, height: 1000});
    await page.goto(`${origin}/settings/ai?locale=en&profile=${customId}`);
    assert.equal(await unloadBlocked(), false, 'Unedited saved profile needs no leave warning');
    const historyPanel = page.locator('[data-ai-profile-history]');
    await historyPanel.locator(':scope > summary').click();
    const earlier = historyPanel.locator('[data-ai-history-revision="2"]');
    await earlier.locator(':scope > summary').click();
    assert.ok((await earlier.textContent()).includes(history[1].name));
    assert.ok((await earlier.textContent()).includes(history[1].instructions));
    assert.ok((await earlier.textContent()).includes(history[1].examples[0].text));
    assert.equal(await earlier.locator('time').getAttribute('datetime'), history[1].createdAt);
    assert.equal(await historyPanel.locator('form, button, input, textarea').count(), 0, 'Historical versions offer no write actions');
    const currentHistory = historyPanel.locator('[data-ai-history-revision="3"]');
    await currentHistory.locator(':scope > summary').click();
    assert.ok((await currentHistory.textContent()).includes('Current version'));
    assert.equal(await page.evaluate(() => Boolean(window.AI_PROFILE_INJECTION)), false);
    const hunter = page.locator('section[aria-labelledby="ai-hunter-title"]');
    assert.ok((await hunter.textContent()).includes('API key missing'));
    assert.equal(await hunter.locator('form, button, input').count(), 0, 'Connection status cannot start a contact lookup');
    mark('Read-only version history escapes names, instructions and examples with saved dates; Hunter status starts no search');
    const name = page.locator('input[name="name"]');
    await name.fill('Unsaved profile name');
    assert.equal(await unloadBlocked(), true, 'Local profile edits guard navigation');
    await name.fill(custom.name);
    assert.equal(await unloadBlocked(), false, 'Reverting to saved profile values clears the guard');
    await name.focus();
    await page.keyboard.press('Tab');
    assert.equal(await page.locator('textarea[name="instructions"]').evaluate(element => document.activeElement === element), true, 'Keyboard reaches profile instructions after name');
    mark('Saved profile edits and exact reverts control the unsaved warning; keyboard reaches editing fields');

    await page.goto(`${origin}/settings/ai?locale=en&new=1`);
    const createForm = editor();
    assert.equal(await createForm.count(), 1);
    await createForm.locator('input[name="name"]').fill('New synthetic profile');
    await createForm.locator('textarea[name="instructions"]').fill('Write a concise, friendly introduction.');
    await createForm.locator('.ai-example summary').first().click();
    await createForm.locator('input[name="example_names[]"]').first().fill('Synthetic introduction');
    await createForm.locator('textarea[name="example_texts[]"]').first().fill('Hello team,\nThis is an example of tone, not a current finding.');
    await Promise.all([page.waitForURL(url => url.searchParams.has('saved')), createForm.locator('button[type="submit"]').first().click()]);
    assert.equal(posts.at(-1).action, 'create');
    assert.equal(posts.at(-1)._token, 'synthetic-ai-settings-csrf');
    assert.equal(posts.at(-1).name, 'New synthetic profile');
    assert.equal(posts.at(-1).example_names[0], 'Synthetic introduction');
    assert.equal(posts.at(-1).example_texts[0], 'Hello team,\r\nThis is an example of tone, not a current finding.', 'Native form encoding uses CRLF without losing text');
    assert.equal(posts.at(-1).example_texts.length, 3);
    mark('Native create form submits named examples, instructions and CSRF only to local AI settings');

    await page.goto(`${origin}/settings/ai?locale=en&profile=${customId}`);
    nextPostResult = 'conflict';
    await page.locator('input[name="name"]').fill('Retained unsaved name');
    await page.locator('textarea[name="instructions"]').fill('Retained unsaved instructions.');
    const conflictResponse = page.waitForResponse(response => response.url().endsWith('/settings/ai') && response.request().method() === 'POST');
    await editor().locator('button[type="submit"]').first().click();
    assert.equal((await conflictResponse).status(), 409);
    await page.waitForLoadState();
    assert.equal(posts.at(-1).action, 'update');
    assert.equal(posts.at(-1).profileId, customId);
    assert.equal(posts.at(-1).revision, '3');
    assert.equal(await page.locator('input[name="name"]').inputValue(), 'Retained unsaved name');
    assert.equal(await page.locator('textarea[name="instructions"]').inputValue(), 'Retained unsaved instructions.');
    assert.ok((await page.locator('body').textContent()).includes('Synthetic conflict'));
    await page.screenshot({path: path.join(output, 'profile-conflict-en-1440.png'), fullPage: true});
    nextPostResult = 'success';
    mark('A native conflict response preserves posted profile text and displays the server error with the original revision');

    await page.goto(`${origin}/settings/ai?locale=en&profile=${customId}`);
    nextPostResult = 'archived-conflict';
    const retainedName = 'My unsaved profile name';
    const retainedInstructions = `My unsaved instructions ${injection}`;
    const retainedExample = `My unsaved example\n${injection}`;
    await page.locator('input[name="name"]').fill(retainedName);
    await page.locator('textarea[name="instructions"]').fill(retainedInstructions);
    await page.locator('textarea[name="example_texts[]"]').first().fill(retainedExample);
    const archivedConflictResponse = page.waitForResponse(response => response.url().endsWith('/settings/ai') && response.request().method() === 'POST');
    await editor().locator('button[type="submit"]').first().click();
    assert.equal((await archivedConflictResponse).status(), 409);
    await page.waitForLoadState();
    assert.equal(await page.locator('input[name="name"]').inputValue(), retainedName);
    assert.equal(await page.locator('textarea[name="instructions"]').inputValue(), retainedInstructions);
    assert.equal(await page.locator('textarea[name="example_texts[]"]').first().inputValue(), retainedExample);
    assert.equal(await editor().locator('button[type="submit"]').first().isDisabled(), true);
    assert.equal(await unloadBlocked(), true, 'A concurrently archived profile must not discard retained text without warning');
    assert.equal(await page.evaluate(() => Boolean(window.AI_PROFILE_INJECTION)), false);
    assert.ok((await page.locator('body').textContent()).includes('can no longer be saved into the archived profile'));
    await page.screenshot({path: path.join(output, 'profile-archived-conflict-en-1440.png'), fullPage: true});
    nextPostResult = 'success';
    mark('Concurrent archival keeps unsaved instructions and example text escaped, copyable and guarded while disabling Save');

    await page.goto(`${origin}/settings/ai?locale=en&profile=${customId}`);
    const defaultForm = page.locator('form').filter({has: page.locator('input[name="action"][value="default"]')});
    await Promise.all([page.waitForURL(url => url.searchParams.has('saved')), defaultForm.locator('button[type="submit"]').click()]);
    assert.equal(posts.at(-1).action, 'default');
    assert.equal(posts.at(-1)._token, 'synthetic-ai-settings-csrf');
    assert.equal(posts.at(-1).profileId, customId);
    assert.equal(posts.at(-1).revision, '3');
    assert.equal(posts.at(-1).defaultRevision, '2');
    const archiveForm = page.locator('form').filter({has: page.locator('input[name="action"][value="archive"]')});
    const archiveResponse = page.waitForResponse(response => response.url().endsWith('/settings/ai') && response.request().method() === 'POST');
    await archiveForm.locator('button[type="submit"]').click(); await archiveResponse; await page.waitForLoadState();
    assert.equal(posts.at(-1).action, 'archive');
    assert.equal(posts.at(-1).profileId, customId);
    assert.equal(posts.at(-1).revision, '3');
    await page.goto(`${origin}/settings/ai?locale=en&profile=builtin`);
    assert.equal(await page.locator('[data-ai-profile-form]').count(), 0);
    assert.equal(await page.locator('input[name="action"][value="archive"]').count(), 0);
    await page.goto(`${origin}/settings/ai?locale=en&profile=${archived.id}`);
    assert.equal(await page.locator('[data-ai-profile-form]').count(), 0);
    assert.equal(await page.locator('input[name="action"][value="default"]').count(), 0);
    assert.ok((await page.locator('body').textContent()).includes('cannot be selected for new drafts'));
    mark('Default/archive forms carry profile and revision tokens; built-in and archived profiles remain read-only');

    // Native forms remain usable with JavaScript disabled; this is a template
    // transport check, not a substitute for PHP persistence/concurrency tests.
    const nativeContext = await browser.newContext({javaScriptEnabled: false, viewport: {width: 768, height: 1000}});
    await nativeContext.route('**/*', guard);
    const nativePage = await nativeContext.newPage();
    await nativePage.goto(`${origin}/settings/ai?locale=en&new=1`);
    const nativeForm = nativePage.locator('form').filter({has: nativePage.locator('input[name="name"]')});
    await nativeForm.locator('input[name="name"]').fill('No-JS synthetic profile');
    await nativeForm.locator('textarea[name="instructions"]').fill('Use plain language.');
    await Promise.all([nativePage.waitForURL(url => url.searchParams.has('saved')), nativeForm.locator('button[type="submit"]').first().click()]);
    assert.equal(posts.at(-1).name, 'No-JS synthetic profile');
    assert.equal(posts.at(-1).action, 'create');
    await nativeContext.close();
    mark('Profile creation works through a native POST with JavaScript disabled');

    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    mark('No browser runtime errors, external requests or API/Gmail operations');
    await fs.writeFile(path.join(output, 'receipt.json'), JSON.stringify({checks, errors, external, posts: posts.map(post => ({action: post.action, profileId: post.profileId || '', keys: Object.keys(post)}))}, null, 2));
  } finally {
    try { await browser?.close(); }
    finally { if (server.listening) await new Promise(resolve => server.close(resolve)); }
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
