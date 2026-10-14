// Finds which paths are real pages, for the studio's start-page list.
//   node pages.cjs <job.json>
// Signs in like the recorder, then requests each path (no browser tab, so
// it's quick) and keeps the ones that answer 200 with HTML, with a title
// read from the page's <h1> or <title>. Redirects aren't followed: a page
// that sends this user elsewhere isn't one they can start on.
// Writes result.json: { pages: [{ path, title }], skipped: [{ path, reason }] }.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const url = p => job.baseUrl.replace(/\/$/, '') + '/' + String(p).replace(/^\//, '');

const decode = s => s
  .replace(/<[^>]+>/g, ' ')
  .replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&quot;/g, '"').replace(/&#0?39;|&apos;/g, "'").replace(/&#(\d+);/g, (_, n) => String.fromCharCode(n))
  .replace(/\s+/g, ' ').trim();

// Take the title from the page's first <h1>, or its <title>.
function titleOf(html) {
  const h1 = html.match(/<h1\b[^>]*>([\s\S]*?)<\/h1>/i);
  const title = html.match(/<title\b[^>]*>([\s\S]*?)<\/title>/i);
  return (h1 && decode(h1[1])) || (title && decode(title[1])) || '';
}

(async () => {
  const pages = [];
  const skipped = [];
  const browser = await chromium.launch(job.channel ? { channel: job.channel } : {});
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: !!job.ignoreHttpsErrors });
    // Sample data: the cookie sends every request to the video's own database.
    if (job.cookies?.length) await context.addCookies(job.cookies);
    if (job.loginPath) {
      const page = await context.newPage();
      await page.goto(url(job.loginPath), { waitUntil: 'load' });
      await page.close();
    }

    const check = async p => {
      try {
        const res = await context.request.get(url(p), { maxRedirects: 0, timeout: 15000, headers: { Accept: 'text/html' } });
        const type = res.headers()['content-type'] || '';
        if (res.status() >= 300 && res.status() < 400) return skipped.push({ path: p, reason: 'redirects' });
        if (res.status() !== 200) return skipped.push({ path: p, reason: 'HTTP ' + res.status() });
        if (!type.includes('text/html')) return skipped.push({ path: p, reason: 'not a page (' + type.split(';')[0] + ')' });
        pages.push({ path: p, title: titleOf(await res.text()).slice(0, 120) });
      } catch (e) {
        skipped.push({ path: p, reason: 'did not load' });
      }
    };

    // Four at a time: quick, without flooding a single-process dev server.
    const queue = [...job.paths];
    await Promise.all(Array.from({ length: 4 }, async () => {
      while (queue.length) await check(queue.shift());
    }));
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(job.outDir, 'result.json'), JSON.stringify({ pages, skipped }, null, 2));
  }
  process.exit(0);
})();
