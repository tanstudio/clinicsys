import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const base = process.env.CLINIC_TEST_URL || 'http://127.0.0.1:18765';
assert.equal(new URL(base).hostname, '127.0.0.1');
const run = randomUUID();
const helper = (mode, input) => execFileSync('php', ['tests/staff-role-fixtures.php', mode, run], {encoding:'utf8', input});
const fixture = JSON.parse(helper('create'));
const links = {
  doctor: ['patients','encounters','inventory','hr'],
  reception: ['schedule','patients','inventory','pos','inbox','hr'],
  admin: ['schedule','patients','encounters','inventory','pos','reports','inbox','hr'],
};
const forbidden = {doctor:['schedule','pos','reports','inbox'], reception:['encounters','reports'], admin:[]};
const problems = [];
let browser;
let rendered = 0;
try {
  browser = await chromium.launch({channel:'chrome'});
  for (const role of Object.keys(links)) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.on('pageerror', e => problems.push(role + ' JS: ' + e.message));
    const result = await page.goto(base+'/staff');
    assert.equal(result.url(), base+'/staff/login');
    const user = fixture.users[role];
    for (let attempt=0; attempt<2; attempt++) {
      await page.goto(base+'/staff/login');
      await page.getByLabel('工作電郵').fill(user.email);
      await page.getByLabel('密碼').fill(fixture.password);
      const [login] = await Promise.all([
        page.waitForResponse(r=>r.request().method()==='POST' && new URL(r.url()).pathname==='/staff/login'),
        page.getByRole('button', {name:'登入工作台'}).click(),
      ]);
      if (login.status()===429 && attempt===0) {
        const wait = Number(login.headers()['retry-after'] || 60);
        assert(wait>=0 && wait<=60,'Unexpected login retry delay');
        await new Promise(resolve=>setTimeout(resolve,(wait+1)*1000));
        continue;
      }
      assert.equal(login.status(),302,role+' login');
      await page.waitForURL(base+'/staff');
      break;
    }
    assert.equal(new URL(page.url()).pathname, '/staff');
    async function switchBranch(index) {
      await page.locator('select[name=branch_id]').selectOption(String(fixture.branches[index]));
      await page.getByRole('button', {name:'切換', exact:true}).click();
      assert.equal(await page.locator('select[name=branch_id]').inputValue(), String(fixture.branches[index]));
    }
    if (role === 'admin') await switchBranch(0);
    for (const width of [390,1280]) {
      await page.setViewportSize({width,height:860});
      await page.goto(base+'/staff');
      const found = await page.locator('.portal-index a').evaluateAll(items => items.map(a => a.pathname.split('/').pop()));
      assert.deepEqual(links[role].filter(p => !found.includes(p)), [], role+' missing links');
      assert.deepEqual(forbidden[role].filter(p => found.includes(p)), [], role+' forbidden links');
      for (const path of ['',...found]) {
        const url = '/staff'+(path ? '/'+path : '');
        await page.goto(base+'/staff');
        const response = path ? await Promise.all([
          page.waitForNavigation(), page.locator('.portal-index a[href="'+url+'"]').click(),
        ]).then(([r]) => r) : await page.reload();
        if (response.status() !== 200) {
          problems.push(role+' '+url+' '+width+'px: '+response.status());
          continue;
        }
        assert.equal(new URL(page.url()).pathname, url);
        assert(await page.locator('main h1').isVisible(), role+' '+url+' heading');
        if (!(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))) {
          problems.push(role+' '+url+' '+width+'px: viewport overflow');
        }
        await page.screenshot();
        rendered++;
      }
    }
    if (role === 'doctor') {
      await page.goto(base+'/staff/encounters');
      await page.locator('input[name=q]').fill(run);
      await page.getByRole('button',{name:'搜尋病歷'}).click();
      assert(await page.getByText('找不到符合條件的病歷。').isVisible());
      assert(!page.url().includes(run));
    } else if (role === 'reception') {
      await page.goto(base+'/staff/schedule');
      const date = '2026-01-10';
      await page.locator('input[name=date]').and(page.getByLabel('排班日期', {exact:true})).fill(date);
      await page.getByRole('button',{name:'查看排班'}).click();
      assert.equal(await page.locator('.agenda-week [aria-current=date]').getAttribute('href'), '?date='+date);
      assert(await page.getByRole('heading',{name:'當日時段'}).isVisible());
      await page.goto(base+'/staff/inbox');
      await page.getByRole('link',{name:'指派給我'}).click();
      assert.equal(new URL(page.url()).searchParams.get('queue'),'mine');
    } else {
      await page.goto(base+'/staff/reports');
      await page.locator('select[name=branch]').selectOption(String(fixture.branches[0]));
      await page.locator('select[name=metric]').selectOption('utilization');
      await page.getByRole('button',{name:'更新報表'}).click();
      assert.equal(await page.locator('main table').first().locator('tbody tr').count(),1);
      assert.equal(new URL(page.url()).searchParams.get('metric'),'utilization');
    }
    for (const path of forbidden[role]) {
      assert.equal((await page.goto(base+'/staff/'+path)).status(),403,role+' '+path);
    }
    await page.goto(base+'/staff/hr');
    if (role !== 'admin') {
      assert.equal(await page.locator('main table tbody a').count(),1);
      await page.getByRole('link',{name:user.name,exact:true}).click();
      assert(await page.getByRole('heading',{name:user.name,exact:true}).isVisible());
      const other = fixture.users[role === 'doctor' ? 'reception' : 'doctor'];
      assert.equal((await page.request.get(base+'/staff/hr/people/'+other.id)).status(),404);
      const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
      assert.equal((await page.request.post(base+'/staff/branch',{form:{_token:csrf, branch_id:fixture.branches[1]},maxRedirects:0})).status(),403);
      if (role === 'doctor') {
        const path = '/staff/hr/people/'+user.id;
        const res = await page.request.post(base+path+'/profile', {
          headers:{referer:base+path}, maxRedirects:0, form:{
            _token:csrf, identity:run.repeat(3), address:run, phone:run, emergency_name:run, emergency_phone:run,
          },
        });
        assert.equal(res.status(),302);
        const cookie = (await context.cookies()).find(c=>c.name===fixture.session_cookie);
        assert.equal(helper('check-session', cookie.value),'Sensitive values excluded');
        await page.goto(base+path);
        assert(await page.getByRole('alert').isVisible());
        assert.equal(await page.locator('input[name=identity]').inputValue(),'');
      }
    } else {
      for (const [branch, visible, hidden] of [[0,'doctor','reception'],[1,'reception','doctor']]) {
        if (branch) await switchBranch(branch);
        await page.goto(base+'/staff/hr');
        assert(await page.getByRole('link',{name:fixture.users[visible].name,exact:true}).isVisible());
        assert.equal(await page.getByRole('link',{name:fixture.users[hidden].name,exact:true}).count(),0);
        assert.equal((await page.request.get(base+'/staff/hr/people/'+fixture.users[hidden].id)).status(),404);
      }
    }
    await page.goto(base+'/staff');
    await page.getByRole('button',{name:'登出',exact:true}).click();
    assert.equal(new URL((await page.goto(base+'/staff/hr')).url()).pathname,'/staff/login');
    await context.close();
  }
  assert.deepEqual(problems,[]);
  console.log('PASS 3 staff roles, '+rendered+' role/viewport/screens at 390 and 1280 px, visible module links, role denials, self-service, HQ branch switch, validation flash and logout');
} finally {
  try { await browser?.close(); } finally { helper('cleanup'); }
}
