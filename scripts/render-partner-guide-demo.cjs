// Render local, anonymised Blade snapshots; never navigate to a live portal.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const source = path.join(root, '.tmp', 'partner-manual-v2');
const executablePath = process.env.GUIDE_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';

(async () => {
  const browser = await chromium.launch({ executablePath, headless: true });
  try {
    for (const locale of ['hu', 'en']) {
      const folder = path.join(source, locale);
      const published = path.join(root, 'public/help/partner-guide', locale);
      fs.mkdirSync(published, { recursive: true });
      const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1.3 });
      await page.route('**/*', route => /^(file:|data:|about:)/.test(route.request().url()) ? route.continue() : route.abort());
      const targets = [
        ['04-rendeles-valasztas', '04-selector-panel', 'selection'],
        ['04-rendeles-valasztas', '05-rendeleslista', 'summary'],
        ['06-rendelolap', '06-rendelolap', null],
        ['06-rendelolap', '06-szezon-fejlec', null],
        ['08-osszesitett-adatok', '08-osszesitett-adatok', null],
        ['16-visszanyitas', '16-visszanyitas', null],
        ['17-tomeges-bekuldes', '17-tomeges-bekuldes', null],
      ];
      for (const [name, output, tab] of targets) {
        const state = JSON.parse(fs.readFileSync(path.join(folder, name + '.json'), 'utf8'));
        await page.goto(pathToFileURL(path.join(folder, name + '.html')).href);
        await page.evaluate(async ({ state, tab }) => {
          await document.fonts.ready;
          for (const element of document.querySelectorAll('input,select,textarea')) {
            const binding = [...element.attributes].find(a => a.name.startsWith('wire:model'))?.value;
            if (!binding) continue;
            const value = binding.split('.').reduce((v, k) => v?.[k], state);
            if (value === null || value === undefined) continue;
            if (element.type === 'checkbox') element.checked = Array.isArray(value) ? value.map(String).includes(element.value) : Boolean(value);
            else if (element.type !== 'file') element.value = String(value);
          }
          for (const element of document.querySelectorAll('[wire\\:loading],[wire\\:loading\\.flex]')) element.style.display = 'none';
          if (tab) {
            for (const panel of document.querySelectorAll('[role="tabpanel"]')) panel.style.display = panel.id === 'order-' + tab + '-panel' ? '' : 'none';
            for (const button of document.querySelectorAll('[role="tab"]')) {
              const selected = button.id === 'order-' + tab + '-tab';
              button.setAttribute('aria-selected', String(selected));
              button.style.backgroundColor = selected ? '#e0f2fe' : '#fff';
              button.style.borderColor = selected ? '#38bdf8' : 'transparent';
              button.style.color = selected ? '#082f49' : '#475569';
            }
          }
        }, { state, tab });
        await page.waitForTimeout(200);
        const body = await page.locator('body').innerText();
        if (/@endphp|@if\s*\(|\{\{\s*__\(/.test(body)) throw new Error('Uncompiled Blade in ' + name);
        const target = output === '17-tomeges-bekuldes'
          ? page.locator('[data-bulk-order-submission] section')
          : output === '06-szezon-fejlec' || output === '16-visszanyitas'
            ? page.locator('[data-order-header-tools]').locator('..')
            : page.locator('[wire\\:id]').first();
        await target.screenshot({ path: path.join(folder, output + '.png') });
        await target.screenshot({ type: 'jpeg', quality: 87, path: path.join(published, output + '.jpg') });
        console.log(locale + ': ' + output);
      }
      await page.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
