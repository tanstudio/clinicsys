import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {randomUUID} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';

const base = process.env.CLINIC_TEST_URL || 'http://127.0.0.1:18765';
assert.equal(new URL(base).hostname, '127.0.0.1');
const run = randomUUID();
const helper = mode => execFileSync('php',['tests/operations-fixtures.php',mode,run],{encoding:'utf8'});
const fixture = JSON.parse(helper('create'));
let browser;
try {
    browser = await chromium.launch({channel:'chrome'});
    const page = await browser.newPage({acceptDownloads:true});
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto(base+'/staff/login');
    await page.getByLabel('工作電郵').fill(fixture.email);
    await page.getByLabel('密碼').fill(fixture.password);
    await page.getByRole('button',{name:'登入工作台'}).click();
    await page.waitForURL(base+'/staff');
    for (const width of [390,1280]) {
        await page.setViewportSize({width,height:900});
        await page.goto(base+'/staff/pos');
        assert.match(await page.locator('[name=encounter_id]').innerText(),/本機測試患者/);
        const receiptForm=page.getByRole('form',{name:'收據篩選'});
        await receiptForm.locator('[name=method]').selectOption('cash');
        await receiptForm.locator('[name=from]').fill(fixture.day);
        await receiptForm.locator('[name=to]').fill(fixture.day);
        await page.getByRole('button',{name:'篩選收據'}).click();
        assert.equal(await page.locator('tbody tr').count(),25);
        assert(await page.getByText(/26 張收據 · 合計 HK\$ 3,250.00/).isVisible());
        await page.getByRole('link',{name:'下一頁',exact:true}).click();
        assert.equal(new URL(page.url()).searchParams.get('method'),'cash');
        assert.equal(new URL(page.url()).searchParams.get('from'),fixture.day);
        assert.equal(await page.locator('tbody tr').count(),1);
        assert(await page.getByText(/26 張收據 · 合計 HK\$ 3,250.00/).isVisible());
        await receiptForm.locator('[name=method]').selectOption('');
        await receiptForm.locator('[name=reference]').fill(fixture.reference);
        await page.getByRole('button',{name:'篩選收據'}).click();
        assert(await page.getByRole('link',{name:'#'+fixture.receipt,exact:true}).isVisible());
        assert(await page.getByText(/1 張收據 · 合計 HK\$ 245.00/).isVisible());
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        if(process.env.CLINIC_SCREENSHOT_DIR) await page.screenshot({path:process.env.CLINIC_SCREENSHOT_DIR+'/operations-pos-'+width+'.png',fullPage:true});
        await receiptForm.locator('[name=reference]').fill('no-such-reference');
        await page.getByRole('button',{name:'篩選收據'}).click();
        assert(await page.getByText('找不到符合條件的收據。').isVisible());

        await page.goto(base+'/staff/inventory');
        const stockForm=page.getByRole('form',{name:'庫存流水篩選'});
        await stockForm.locator('[name=herb_filter]').fill('本機測試藥材');
        await stockForm.locator('[name=movement_kind]').selectOption('receive');
        await stockForm.locator('[name=from]').fill(fixture.day);
        await stockForm.locator('[name=to]').fill(fixture.day);
        await page.getByRole('button',{name:'篩選流水'}).click();
        assert(await page.getByText(/符合條件 26 筆流水/).isVisible());
        await page.getByRole('link',{name:'下一頁',exact:true}).click();
        assert.equal(new URL(page.url()).searchParams.get('movement_kind'),'receive');
        assert.equal(new URL(page.url()).searchParams.get('herb_filter'),'本機測試藥材');
        assert.equal(await page.locator('.sheet-scroll').last().locator('tbody tr').count(),1);
        await stockForm.locator('[name=movement_kind]').selectOption('dispense');
        await page.getByRole('button',{name:'篩選流水'}).click();
        assert(await page.getByText(/符合條件 1 筆流水/).isVisible());
        assert(await page.getByRole('cell',{name:'25.000',exact:true}).isVisible());
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        if(process.env.CLINIC_SCREENSHOT_DIR) await page.screenshot({path:process.env.CLINIC_SCREENSHOT_DIR+'/operations-inventory-'+width+'.png',fullPage:true});
        await page.getByRole('link',{name:'清除篩選',exact:true}).click();
        assert.equal(new URL(page.url()).search,'');

        await page.goto(base+'/staff/reports?from='+fixture.day+'&to='+fixture.day);
        assert(await page.getByText('HK$ 3,495.00',{exact:true}).isVisible());
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        const [download]=await Promise.all([page.waitForEvent('download'),page.getByRole('button',{name:'匯出分店指標 CSV'}).click()]);
        assert.match(download.suggestedFilename(),/^clinic-report-\d{8}-\d{8}\.csv$/);
        const csv=await fs.readFile(await download.path(),'utf8');
        assert(csv.startsWith('\uFEFF'));
        assert(csv.includes('3495.00') && csv.includes('本機營運測試'));
        if(process.env.CLINIC_SCREENSHOT_DIR) await page.screenshot({path:process.env.CLINIC_SCREENSHOT_DIR+'/operations-reports-'+width+'.png',fullPage:true});
    }
    assert.deepEqual(errors,[]);
    console.log('PASS operations: 6/6 module/viewport combinations (390/1280), 26-row pagination, retained filters, all-page totals, patient labels, empty results, unchanged stock and CSV downloads.');
} finally {
    try { await browser?.close(); } finally { helper('cleanup'); }
}
