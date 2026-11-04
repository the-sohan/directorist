/* Run: node tests/account-dropdown-viewport.cjs
 * Exercises the shipped account-block assets in Chromium, without theme styles.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('@playwright/test');
const root = path.resolve(__dirname, '..');

(async () => {
    const chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.CHROME_PATH || (fs.existsSync(chrome) ? chrome : undefined),
    });
    let cases = 0;
    try {
        for (const direction of ['ltr', 'rtl']) {
            const css = fs.readFileSync(path.join(root, 'blocks/build/account-button',
                direction === 'rtl' ? 'style-index-rtl.css' : 'style-index.css'), 'utf8');
            for (const width of [128, 320, 390, 768, 1440]) {
                for (const edge of ['left', 'right', 'middle']) {
                    const page = await browser.newPage({ viewport: { width, height: 600 } });
                    const position = edge === 'left' ? 'left:4px' : edge === 'right'
                        ? 'right:4px' : 'left:calc(50% - 16px)';
                    const label = `AccountPreferences${'TranslatedLabel'.repeat(8)}`;
                    await page.setContent(`<!doctype html><html dir="${direction}"><head><style>
                        body { margin:0; } .fixture { position:relative; height:500px; }
                        .fixture>.directorist-account-block-logged-mode { position:absolute;top:40px;${position};width:32px; }
                        .directorist-account-block__trigger { width:32px;height:32px; }
                    </style><style>${css}</style></head><body><div class="fixture">
                        <div class="directorist-account-block-logged-mode">
                            <button class="directorist-account-block__trigger" aria-controls="account-menu" aria-expanded="false">A</button>
                            <div id="account-menu" class="directorist-account-block-logged-mode__navigation" aria-hidden="true">
                                <ul><li><a href="#profile">Profile</a></li><li><a href="#settings">${label}</a></li><li><a href="#logout">Log out</a></li></ul>
                            </div>
                        </div></div></body></html>`);
                    await page.addScriptTag({ path: path.join(root, 'blocks/build/account-button/script.js') });
                    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
                    const context = `${direction} ${width}px ${edge}`;
                    const scrollWidth = () => page.evaluate(() => document.documentElement.scrollWidth);
                    assert.ok(await scrollWidth() <= width, `${context}: closed menu must not overflow`);
                    const trigger = page.locator('.directorist-account-block__trigger');
                    const menu = page.locator('#account-menu');
                    await trigger.click();
                    await page.waitForFunction(() => document.querySelector('#account-menu').getAttribute('aria-hidden') === 'false');
                    const fits = async (expectedWidth) => {
                        await page.waitForFunction(value => parseFloat(document.querySelector('#account-menu').style.getPropertyValue('--directorist-account-menu-max-width')) === value - 16, expectedWidth);
                        await page.waitForFunction(() => {
                            const r = document.querySelector('#account-menu').getBoundingClientRect();
                            return r.left >= 7.5 && r.right <= document.documentElement.clientWidth - 7.5;
                        });
                        assert.ok(await scrollWidth() <= expectedWidth, `${context}: open menu must fit`);
                    };
                    await fits(width);
                    await page.setViewportSize({ width: 180, height: 600 });
                    await fits(180);
                    await page.setViewportSize({ width: 900, height: 600 });
                    await fits(900);
                    await page.keyboard.press('Escape');
                    assert.equal(await menu.getAttribute('aria-hidden'), 'true');
                    assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
                    assert.equal(await trigger.evaluate(el => document.activeElement === el), true);
                    assert.ok(await scrollWidth() <= 900, `${context}: Escape must restore closed state`);
                    await trigger.click();
                    await fits(900);
                    await page.waitForFunction(() => document.querySelector('.directorist-account-block-logged-mode__overlay').getBoundingClientRect().height >= innerHeight - 1);
                    await page.mouse.click(450, 580);
                    assert.equal(await menu.getAttribute('aria-hidden'), 'true', 'Outside click closes navigation');
                    await page.close();
                    cases++;
                    console.log(`PASS ${context}: closed/open, both edges, resize, long labels, Escape and outside click`);
                }
            }
        }
    } finally {
        await browser.close();
    }
    console.log(`PASS ${cases} account dropdown viewport cases`);
})().catch(error => { console.error(error); process.exitCode = 1; });
