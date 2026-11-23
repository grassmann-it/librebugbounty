'use strict';
// Native geometry and evidence-navigation regression; fresh local fixture only.
const assert=require('node:assert/strict'),fs=require('node:fs/promises'),path=require('node:path');
const {chromium}=require('../../playwright-worker/node_modules/playwright');
const base=process.env.STUDIO_BROWSER_BASE,output=process.env.STUDIO_BROWSER_OUTPUT;
assert.ok(base&&output);const origin=new URL(base).origin;assert.ok(['web','localhost','127.0.0.1'].includes(new URL(base).hostname));
(async()=>{
 await fs.mkdir(output,{recursive:true});const browser=await chromium.launch({headless:true});
 const api=await browser.newContext(),get=async()=>{const r=await api.request.get(base+'/__cribrum_badges_fixture');assert.equal(r.status(),200);return r.json()};
 const before=await get();assert.ok(before.isolated);assert.equal(before.runtime.sourceRoot,'/var/www/html');assert.ok(before.runtime.storageRoot.startsWith('/tmp/librebugbounty-cribrum-badges-layout-'));
 const result={runtime:before.runtime,captures:[],checks:[],errors:[],blocked:[]};
 try{
 for(const locale of ['de','en']){
  const c=await browser.newContext({deviceScaleFactor:1,reducedMotion:'reduce',serviceWorkers:'block'});
  await c.addCookies([{name:'lbb_locale',value:locale,url:origin}]);
  await c.route('**/*',r=>{const q=r.request();if(new URL(q.url()).origin!==origin||q.method()!=='GET'){result.blocked.push([q.method(),q.url()]);return r.abort()}return r.continue()});
  const p=await c.newPage();p.on('pageerror',e=>result.errors.push(e.message));
  const capture=async(name,url,size,js=true,page=p)=>{
   await page.setViewportSize(size);const response=await page.goto(base+url);assert.ok(response.ok(),name+' HTTP');
   await page.evaluate(async()=>{await document.fonts.ready;await Promise.all([...document.images].filter(i=>i.getClientRects().length>0).map(i=>i.complete?Promise.resolve():new Promise(r=>{i.onload=i.onerror=r}))) });
   const geometry=await page.evaluate(()=>{
    const rect=e=>{if(!e)return null;const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,bottom:r.bottom,right:r.right}};
    const img=document.querySelector('[data-review-shot]:not([hidden]) [data-review-image],.studio-shot:not([hidden]) img');
    const stage=img?.closest('.review-image-link,.studio-shot-image-link');
    let imageDraw=null;if(img){const r=img.getBoundingClientRect(),scale=Math.min(r.width/img.naturalWidth,r.height/img.naturalHeight);imageDraw={width:img.naturalWidth*scale,height:img.naturalHeight*scale}}
    return {width:innerWidth,height:innerHeight,overflow:document.documentElement.scrollWidth>innerWidth+1,stage:rect(stage),image:rect(img),imageDraw,dock:rect(document.querySelector('[data-review-dock]')),nav:rect(document.querySelector('.studio-workspace-nav')),heading:rect(document.querySelector('.studio-case-heading')),summary:rect(document.querySelector('.studio-detail-workspace > [data-report-summary]')),broken:[...document.images].filter(i=>i.getAttribute('src')&&i.getClientRects().length>0&&(!i.complete||!i.naturalWidth)).length};
   });
   assert.equal(geometry.overflow,false,name+' horizontal overflow');assert.equal(geometry.broken,0,name+' broken image');assert.ok(geometry.imageDraw,name+' readable stored screenshot');
   if(name.startsWith('review')){
    assert.equal(await page.locator('.review-card > [data-report-summary],.review-card > .review-state-rail').count(),0,'Case details must not consume review image height');
    assert.ok(geometry.stage.bottom<=geometry.dock.y+1,'Review image remains above decisions');
    const minimum=size.width>=1400?(size.height>=1000?400:300):150;
    assert.ok(geometry.imageDraw.height>=minimum,`${name}/${size.width}: actual contained image ${geometry.imageDraw.height}px < ${minimum}px`);
    assert.equal(await page.locator('.review-source [data-report-summary]').count(),1,'Review findings remain accessible in inspector');
   }else{
    assert.ok(geometry.summary.x>=geometry.heading.x-1,'Summary stays within case content');
    assert.ok(geometry.summary.right<=geometry.heading.right+1,'Summary right edge stays within case content');
    assert.equal(await page.locator('.studio-detail-workspace > [data-report-history],.studio-detail-workspace > #worker-proof-review').count(),0,'History/review details must not precede image');
    assert.equal(await page.locator('.studio-case-proof > .cribrum-diagnostics').count(),1,'Diagnostic details remain available');
    if(size.width>=1400){assert.ok(geometry.stage.y<(name==='case-dense'?560:430),'Case image starts in upper working area');assert.ok(geometry.imageDraw.height>=350,'Case screenshot large enough to read');}
   }
   const file=`${locale}-${name}-${size.width}x${size.height}${js?'':'-nojs'}.png`;await page.screenshot({path:path.join(output,file),fullPage:false,animations:'disabled'});
   result.captures.push({locale,name,size,file,geometry});
  };
  const ids=before.fixtures;
  for(const size of [{width:1920,height:1080},{width:1893,height:950},{width:1440,height:900},{width:390,height:844}]){
   await capture('review-ready','/review?images=ready',size);assert.equal(await p.locator('[data-studio-review]').getAttribute('data-current-id'),ids.mixed.id);
   await capture('case-mixed','/findings/'+ids.mixed.id,size);
   await capture('case-stale','/findings/'+ids.stale.id,size).catch(async e=>{if((await p.locator('.studio-shot img').count())===0&&e.message.includes('readable stored screenshot')){result.checks.push(locale+' stale case without image retained honest empty state');return}throw e});
  }
  for(const kind of ['mixed','dense']){
   await capture('case-'+kind,'/findings/'+ids[kind].id,{width:1920,height:1080});
   const links=p.locator('.cribrum-badge-item > .cribrum-proof-link');let count=await links.count();assert.ok(count>0);
   for(let i=0;i<count;i++){const hash=new URL(await links.nth(i).getAttribute('href'),p.url()).hash;await links.nth(i).focus();await p.keyboard.press('Enter');await p.waitForFunction(hash=>{const panel=document.getElementById(hash.slice(1));return location.hash===hash&&panel?.open&&!panel.hidden},hash);}
   result.checks.push({locale,case:kind,evidenceLinks:count});
  }
  await capture('review-classified','/review?images=ready',{width:1920,height:1080});
  assert.equal(await p.locator('[data-studio-review]').getAttribute('data-current-id'),ids.mixed.id);
  await p.locator('[data-review-open-details]').first().click();
  const reviewProof=p.locator('[data-cribrum-code="sql_injection"] > .cribrum-proof-link');await reviewProof.focus();await p.keyboard.press('Enter');
  assert.ok(await p.locator(new URL(p.url()).hash).evaluate(e=>e.open&&!e.hidden));
  await p.locator('[data-review-focus-toggle]').click();assert.equal(await p.locator('body').getAttribute('data-review-focus-mode'),'true');
  await p.locator('[data-review-menu] > summary').click();
  await p.locator('[data-review-focus-toggle]').click();assert.equal(await p.locator('body').getAttribute('data-review-focus-mode'),'false');
  result.checks.push(locale+' classified Review evidence and focus-mode roundtrip');
  await c.close();
  const nj=await browser.newContext({viewport:{width:1920,height:1080},deviceScaleFactor:1,javaScriptEnabled:false});await nj.addCookies([{name:'lbb_locale',value:locale,url:origin}]);
  await nj.route('**/*',r=>new URL(r.request().url()).origin===origin&&r.request().method()==='GET'?r.continue():r.abort());
  const n=await nj.newPage();await capture('review-ready','/review?images=ready',{width:1920,height:1080},false,n);assert.equal(await n.locator('[data-studio-review]').getAttribute('data-current-id'),ids.mixed.id);await capture('case-mixed','/findings/'+ids.mixed.id,{width:1920,height:1080},false,n);await nj.close();
 }
 const after=await get();assert.deepEqual(after.snapshot,before.snapshot,'Passive views must not change business data');assert.deepEqual(after.calls,[],'No worker/status calls');assert.deepEqual(result.errors,[]);assert.deepEqual(result.blocked,[]);
 result.checks.push('All business tables identical; no transports, external requests, writes, or JS errors');
 await fs.writeFile(path.join(output,'result.json'),JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({captures:result.captures.length,checks:result.checks,errors:result.errors}));
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
