const { chromium } = require('playwright');

async function run() {
  const query = process.argv.slice(2).join(' ').trim();
  if (!query) throw new Error('Search query is required');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
    locale: 'en-PH'
  });
  const page = await context.newPage();
  await page.setDefaultTimeout(12000);
  const results = [];

  try {
    const searchUrl = 'https://www.bing.com/search?q=' + encodeURIComponent(query + ' Davao City Philippines');
    await page.goto(searchUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
    const links = await page.locator('li.b_algo h2 a').evaluateAll(anchors => anchors.slice(0, 8).map(a => ({
      title: (a.textContent || '').trim(), url: a.href || ''
    })).filter(x => x.url.startsWith('http')));

    for (const link of links) {
      if (results.length >= 5) break;
      const newPage = await context.newPage();
      await newPage.setDefaultTimeout(10000);
      try {
        await newPage.goto(link.url, { waitUntil: 'domcontentloaded', timeout: 12000 });
        await newPage.waitForTimeout(500);
        const title = await newPage.title().catch(() => link.title);
        const text = await newPage.locator('body').innerText().catch(() => '');
        const cleaned = text.replace(/\s+/g, ' ').trim().slice(0, 6000);
        if (cleaned && /davao\s+city/i.test(cleaned + ' ' + title)) {
          results.push({ title: title || link.title, url: link.url, text: cleaned });
        }
      } catch (error) {
      } finally {
        await newPage.close().catch(() => {});
      }
    }
  } finally {
    await browser.close().catch(() => {});
  }

  process.stdout.write(JSON.stringify({ query, results }));
}

run().catch(error => {
  process.stderr.write(String(error && error.message ? error.message : error));
  process.exit(1);
});
