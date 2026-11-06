import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const base = process.env.CLINIC_TEST_URL || 'http://127.0.0.1:18765';
assert.equal(new URL(base).hostname,'127.0.0.1');
const browser = await chromium.launch({channel:'chrome'});
const errors=[];
try {
  for (const width of [390,1280]) {
    const page=await browser.newPage({viewport:{width,height:900}});
    page.on('pageerror',e=>errors.push(e.message));
    const name='QA-'+randomUUID();
    let reference;
    try {
      await page.goto(base+'/?lang=en');
      await page.locator('.times button').first().click();
      await page.getByRole('button',{name:'Enter patient details'}).click();
      await page.getByLabel('Patient name').fill(name);
      await page.getByLabel('Phone region').selectOption('86');
      const phone=page.getByLabel('Mainland China mobile number');
      await phone.fill('12800138000');
      assert.equal(await phone.evaluate(el=>el.checkValidity()),false);
      await phone.fill('13800138000');
      assert.equal(await phone.evaluate(el=>el.checkValidity()),true);
      assert.equal(await phone.getAttribute('maxlength'),'11');
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      if(process.env.CLINIC_SCREENSHOT_DIR) await page.screenshot({path:process.env.CLINIC_SCREENSHOT_DIR+'/phone-'+width+'.png',fullPage:true});
      const response=page.waitForResponse(r=>r.url()===base+'/appointments' && r.request().method()==='POST');
      await page.getByRole('button',{name:'Confirm booking',exact:true}).click();
      const saved=await response;
      assert.equal(saved.status(),201);
      reference=(await saved.json()).reference;
      await page.getByRole('link',{name:'My appointments'}).click();
      await page.getByText(reference,{exact:true}).waitFor();
      await page.getByText('Cancel appointment',{exact:true}).click();
      await page.getByRole('button',{name:'Confirm cancellation'}).click();
      await page.getByText('Appointment cancelled.',{exact:true}).waitFor();
    } finally {
      if(reference) {
        const saved=JSON.parse(execFileSync('php',['tests/check-history.php',name,reference],{encoding:'utf8'}));
        assert.equal(saved.phone,'+8613800138000');
      }
      await page.close();
    }
  }
  assert.deepEqual(errors,[]);
  console.log('PASS +86 booking and cancellation at 2 of 2 viewports (390/1280); invalid prefix rejected; 11-digit input; encrypted CRM phone; no overflow or JS errors.');
} finally { await browser.close(); }
