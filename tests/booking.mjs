import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const base = process.env.CLINIC_TEST_URL || 'http://127.0.0.1:18765';
assert.equal(new URL(base).hostname, '127.0.0.1');
const browser = await chromium.launch({channel:'chrome'});
const fixture = 'QA-' + randomUUID();
const errors = [];
try {
  const patients = await Promise.all([390, 1280].map(async width => {
    const page = await browser.newPage({viewport:{width,height:860}});
    page.on('pageerror', e=>errors.push(e.message));
    await page.goto(base);
    const selects = page.locator('select');
    await page.locator('.times button').first().waitFor();
    await selects.nth(0).selectOption({index:1});
    await selects.nth(1).selectOption({index:1});
    const choices = await selects.evaluateAll(nodes=>nodes.map(n=>n.value));
    await page.getByRole('button',{name:'English'}).click();
    assert.deepEqual(await selects.evaluateAll(nodes=>nodes.map(n=>n.value)),choices);
    const available = await (await page.request.get(base+'/availability')).json();
    const valid = available.slots.filter(s=>[s.branch_id,s.service_id,s.practitioner_id].map(String).join()===choices.join());
    for (const time of await page.locator('.times button').allTextContents()) {
      const date = await page.locator('dd').nth(3).textContent();
      assert(valid.some(s=>s.starts_at.slice(0,16)===date+' '+time));
    }
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    await page.locator('.times button').first().click();
    await page.getByRole('button',{name:'Enter patient details'}).click();
    await page.getByLabel('Patient name').fill(fixture);
    await page.getByLabel('Hong Kong phone number').fill('91234567');
    return page;
  }));
  const responses = await Promise.all(patients.map(async page=>{
    const response = page.waitForResponse(r=>r.url().endsWith('/appointments'));
    await page.getByRole('button',{name:'Confirm booking',exact:true}).click();
    return await response;
  }));
  assert.deepEqual(responses.map(r=>r.status()).sort(),[201,409]);
  const winner = responses.findIndex(r=>r.status()===201);
  const reference = (await responses[winner].json()).reference;
  await patients[winner].getByText(reference,{exact:true}).waitFor();
  await patients[1-winner].getByRole('alert').getByText(/just been booked/).waitFor();
  const remaining = await (await patients[winner].request.get(base+'/availability')).json();
  const result = JSON.parse(execFileSync('php',['tests/check-history.php',fixture,reference],{encoding:'utf8'}));
  assert.equal(result.count,1);
  assert.equal(result.name,fixture);
  assert.equal(result.phone,'+85291234567');
  assert(!remaining.slots.some(s=>s.id===result.slot));
  assert.deepEqual(errors,[]);
  console.log('PASS mobile and desktop, language persistence, filtered availability, concurrent 201/409, refreshed slots and one matching CRM history entry');
} finally {
  await browser.close();
}
