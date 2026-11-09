import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const base=process.env.CLINIC_TEST_URL || 'http://127.0.0.1:18765';
assert.equal(new URL(base).hostname,'127.0.0.1');
const [email,password]=(await fs.readFile('storage/review-login.txt','utf8')).trim().split(/\r?\n/);
const browser=await chromium.launch({channel:'chrome'});
try {
    const page=await browser.newPage();
    const errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.goto(base+'/staff/login');
    await page.getByLabel('工作電郵').fill(email);
    await page.getByLabel('密碼',{exact:true}).fill(password);
    await page.getByRole('button',{name:'登入工作台'}).click();
    await page.waitForURL(base+'/staff');
    await page.getByRole('link',{name:'WhatsApp 設定 Meta 通道、接待分店及通知範本'}).click();
    for (const width of [390,1280]) {
        await page.setViewportSize({width,height:900});
        await page.goto(base+'/staff/whatsapp');
        assert(await page.getByRole('heading',{name:'WhatsApp 設定',exact:true}).isVisible());
        assert.equal(await page.locator('tbody tr').count(),6);
        await page.locator('details').first().locator('summary').click();
        const form=page.getByRole('form',{name:'預約確認範本',exact:true});
        assert(await form.getByLabel('Meta 範本名稱').isVisible());
        assert(await form.getByRole('button',{name:'儲存預約確認範本'}).isVisible());
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        if(process.env.CLINIC_SCREENSHOT_DIR) await page.screenshot({path:process.env.CLINIC_SCREENSHOT_DIR+'/whatsapp-settings-'+width+'.png',fullPage:true});
        await page.goto(base+'/staff/inbox');
        assert(await page.getByRole('link',{name:'WhatsApp 通道與通知範本'}).isVisible());
        assert(await page.getByText(/同義問法/).isVisible());
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    }
    assert.deepEqual(errors,[]);
    console.log('PASS WhatsApp UI: 4/4 page/viewport combinations (390/1280), HQ navigation, six configuration indicators, template forms, synonyms and no horizontal overflow. No settings changed.');
} finally {await browser.close();}
