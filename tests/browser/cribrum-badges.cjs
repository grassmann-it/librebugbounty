/* Dedicated synthetic CB6 acceptance. Initialize/serve tests/Support/cribrum_badges_browser_router.php.
 * STUDIO_BROWSER_BASE=http://127.0.0.1:8096 STUDIO_BROWSER_OUTPUT=/absolute/delivery/screenshots node tests/browser/cribrum-badges.cjs
 * No target visits, capture/recheck actions, Gmail, or case writes. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
const {chromium}=require('../../playwright-worker/node_modules/playwright');
const base=process.env.STUDIO_BROWSER_BASE;
const output=process.env.STUDIO_BROWSER_OUTPUT;
assert.ok(base && ['127.0.0.1','localhost','web'].includes(new URL(base).hostname));
assert.ok(output && path.isAbsolute(output));
const origin=new URL(base).origin;
async function main(){
 await fs.mkdir(output,{recursive:true});
 const browser=await chromium.launch({headless:true});
 const checks=[],errors=[],external=[],writes=[],failures=[],workerUnavailable=[];
 const mark=x=>{checks.push(x);console.log('PASS '+x);};
 const control=await browser.newContext();
 const state=async()=>{const r=await control.request.get(base+'/__cribrum_badges_fixture');assert.equal(r.status(),200);const d=await r.json();assert.equal(d.isolated,true);return d;};
 const before=await state();
 try {
 for(const locale of ['de','en']) for(const width of [1440,390]) {
  const ctx=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'});
  await ctx.addCookies([{name:'lbb_locale',value:locale,url:origin}]);
  await ctx.route('**/*',route=>{const r=route.request();if(new URL(r.url()).origin!==origin){external.push(r.url());return route.abort();}if(r.method()!=='GET'){writes.push(r.url());return route.abort();}return route.continue();});
  const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));p.on('response',r=>{if(r.status()===503 && /\/interactive\/status$/.test(new URL(r.url()).pathname))workerUnavailable.push(r.url());else if(r.status()>=400)failures.push([r.url(),r.status()]);});
  const go=async url=>assert.equal((await p.goto(base+url)).status(),200,url);
  const shot=async name=>p.screenshot({path:path.join(output,`${locale}-${width}-${name}.png`),fullPage:false});
  const overflow=async()=>assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${locale}/${width}: horizontal overflow`);
  const mixed=before.fixtures.mixed.id, dense=before.fixtures.dense.id;
  const ids=()=>p.locator('[data-finding-id]').evaluateAll(ns=>ns.map(n=>n.dataset.findingId));
  const badge=()=>p.locator(`[data-finding-id="${mixed}"] [data-cribrum-code="sql_injection"] .cribrum-badge`);
  await go('/findings?pageSize=25'); assert.ok(!(await ids()).includes(mixed),'Oldest mixed begins beyond page one');
  const firstPage=await ids();
  await go('/findings?pageSize=25&page=2');const secondPage=await ids();assert.ok(secondPage.includes(mixed));assert.equal(new Set([...firstPage,...secondPage]).size,35,'Pagination has no missing or duplicate cases');
  await p.locator('#finding-filters [data-cribrum-filter-disclosure] > summary').click();
  await p.locator('#finding-filters [name=sort]').selectOption('additional_first');
  await Promise.all([p.waitForNavigation(),p.locator('#finding-filters button[type=submit]').first().click()]);
  assert.equal(new URL(p.url()).searchParams.get('page'),null,'New selection resets page');
  assert.ok((await ids()).includes(mixed),'Global sort promotes page-two mixed case: '+p.url()+' ids='+JSON.stringify(await ids()));
  assert.ok(await badge().isVisible()); assert.match(await badge().innerText(),locale==='de'?/SQL-Injection · Verdacht/:/SQL injection · suspected/);
  await badge().scrollIntoViewIfNeeded();await overflow();await shot('inventory');
  await badge().focus();await p.keyboard.press('Tab');
  assert.ok(await p.evaluate(()=>document.activeElement.classList.contains('cribrum-proof-link')),'Separate keyboard evidence action');
  await shot('focus');
  await Promise.all([p.waitForNavigation(),badge().click()]);
  assert.equal(new URL(p.url()).searchParams.get('cribrum[0]'),'sql_injection');
  assert.deepEqual(new Set(await ids()),new Set([mixed,dense]),'Badge filters exactly both current SQLi cases; SQL-error-only and stale excluded');
  const proof=p.locator(`[data-finding-id="${mixed}"] .cribrum-proof-link`).filter({hasText:locale==='de'?'SQL-Injection':'SQL injection'});
  await Promise.all([p.waitForNavigation(),proof.click()]);
  assert.match(new URL(p.url()).hash,/^#cribrum-/);
  const anchor=p.locator(new URL(p.url()).hash);if((await anchor.getAttribute('open'))===null)await anchor.locator('summary').first().click();
  assert.ok(await anchor.isVisible()); await overflow(); await shot('detail-proof');
  await go('/findings/'+mixed);await p.locator('.cribrum-summary').scrollIntoViewIfNeeded();await overflow();await shot('detail');
  await go('/export?cribrum%5B0%5D=sql_injection&sort=additional_first');
  assert.ok(await p.locator('[data-cribrum-code="sql_injection"] .cribrum-badge').first().isVisible());
  await p.locator('[data-cribrum-code="sql_injection"]').first().scrollIntoViewIfNeeded();await overflow();await shot('export');
  await go('/review?images=all');
  assert.equal(await p.locator('[data-studio-review]').getAttribute('data-current-id'),mixed,'Oldest eligible review card is the mixed case');
  const inspector=p.locator('[data-review-inspector]');
  if((await inspector.getAttribute('open'))===null) await inspector.locator('summary').first().click();
  assert.ok(await p.locator('[data-cribrum-code="sql_injection"] .cribrum-badge').first().isVisible(),'Review main case displays SQLi suspicion');
  await p.locator('[data-cribrum-code="sql_injection"]').first().scrollIntoViewIfNeeded();await overflow();await shot('review');
  await go('/findings?view=workbasket&sort=additional_count');
  assert.ok((await ids()).includes(mixed));
  const workbasketCodes=p.locator(`[data-finding-id="${mixed}"] .studio-finding-cribrum-toggle`);
  if(await workbasketCodes.count()&&(await workbasketCodes.getAttribute('open'))===null)await workbasketCodes.locator('summary').click();
  assert.ok(await badge().isVisible());await badge().scrollIntoViewIfNeeded();await overflow();await shot('workbasket');
  await go('/findings?q=sql-error');assert.equal(await p.locator('[data-cribrum-code="sql_injection"]').count(),0);assert.equal(await p.locator('[data-cribrum-code="sql_error_observed"]').count(),1);
  await go('/findings?q=missing');assert.equal(await p.locator('[data-cribrum-freshness=missing]').count(),1);await overflow();await shot('missing');
  mark(`${locale}/${width}: responsive inventory, global sort/page reset, current badge filter, keyboard proof, detail, review, workbasket, export and missing evaluation`);
  await ctx.close();
 }
 assert.deepEqual((await state()).snapshot,before.snapshot,'Read-only acceptance leaves case tables unchanged');
 assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.deepEqual(writes,[]);assert.deepEqual(failures,[]);
 mark('No external requests, writes, unexpected failed responses or JavaScript errors; synthetic case tables unchanged; disabled local interactive worker reports expected 503');
 await fs.writeFile(path.join(output,'result.json'),JSON.stringify({checks,errors,external,writes,failures,workerUnavailable,fixture:base,synthetic:true,screenshots:output},null,2)+'\n');
 } finally {await control.close();await browser.close();}
}
main().catch(e=>{console.error(e);process.exitCode=1;});
