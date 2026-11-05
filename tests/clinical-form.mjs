import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';

const base = process.env.CLINIC_TEST_URL || 'http://127.0.0.1:18765';
assert.equal(new URL(base).hostname, '127.0.0.1');
const run = randomUUID();
const helper = mode => execFileSync('php', ['tests/clinical-form-fixtures.php', mode, run], {encoding: 'utf8'});
const fixture = JSON.parse(helper('create'));
let browser;
try {
    browser = await chromium.launch({channel: 'chrome'});
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base+'/staff/login');
    await page.getByLabel('工作電郵').fill(fixture.email);
    await page.getByLabel('密碼').fill(fixture.password);
    await page.getByRole('button', {name: '登入工作台'}).click();
    await page.waitForURL(base+'/staff');
    for (const [index, width] of [390, 1280].entries()) {
        await page.setViewportSize({width, height: 900});
        const path = '/staff/encounters/new/'+fixture.appointments[index];
        await page.goto(base+path);
        await page.getByLabel('疼痛', {exact: true}).check();
        await page.locator('[name=notes]').fill('本機測試症狀描述，確認驗證失敗後仍保留。');
        await page.locator('[name=prescription]').fill('測試文字，不作臨床用途。');
        await page.locator('[name=plan]').fill('本機測試計劃。');
        // Bypass native required validation to exercise a real server rejection.
        await page.locator('form[action="'+path+'"]').evaluate(form => { form.noValidate = true; });
        const [response] = await Promise.all([
            page.waitForNavigation(),
            page.getByRole('button', {name: '確認並儲存病歷'}).click(),
        ]);
        assert.equal(response.status(), 422);
        assert.match(response.headers()['cache-control'], /no-store/);
        assert(await page.getByRole('alert').isVisible());
        assert.equal(await page.evaluate(() => document.activeElement.id), 'record-errors');
        assert.match(await page.locator('[name=notes]').inputValue(), /仍保留/);
        assert(await page.getByLabel('疼痛', {exact: true}).isChecked());
        assert.equal(await page.locator('[name=prescription]').inputValue(), '測試文字，不作臨床用途。');
        assert.equal(await page.locator('[name=plan]').inputValue(), '本機測試計劃。');
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        if (process.env.CLINIC_SCREENSHOT_DIR) {
            await page.screenshot({path: process.env.CLINIC_SCREENSHOT_DIR+'/clinical-validation-'+width+'.png', fullPage: true});
        }
        await page.getByRole('link', {name: /辨證及診斷：/}).click();
        assert.equal(await page.evaluate(() => document.activeElement.id), 'record-diagnosis');
        await page.locator('[name=diagnosis]').fill('本機驗證用診斷，無臨床意義。');
        await page.getByRole('button', {name: '確認並儲存病歷'}).click();
        await page.waitForURL(/\/staff\/encounters\/\d+$/);
        assert(await page.getByText('本機驗證用診斷，無臨床意義。', {exact: true}).isVisible());
    }
    assert.deepEqual(errors, []);
    console.log('PASS clinical form: 2/2 viewports (390/1280), inline 422, retained fields, focus, no-store, no overflow and corrected resubmission.');
} finally {
    try { await browser?.close(); } finally { helper('cleanup'); }
}
