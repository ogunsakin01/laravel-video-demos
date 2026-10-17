// Opens one page as the video's user and describes what is on it, for the AI
// model while it plans: the exact labels of links, buttons and fields when the
// code doesn't settle them. Nothing is recorded.
//   node look.cjs <job.json>
// The job gives the path, the app's address, the sign-in and the screen.
// Writes result.json: { ok, url, title, status, snapshot, error }.
const fs = require('fs');
const path = require('path');
const browserKit = require('./browser.cjs');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));

(async () => {
  const result = { ok: false, url: null, title: null, status: null, snapshot: null, error: null };
  let browser;
  try {
    browser = await browserKit.launch(job);
    const context = await browserKit.newContext(browser, job);
    await browserKit.signIn(context, job);
    const { page, status } = await browserKit.open(context, job, job.path);
    result.status = status;
    result.url = page.url();
    result.title = await page.title().catch(() => '');
    result.snapshot = await browserKit.snapshot(page, job.limit || 6000);
    result.ok = true;
  } catch (e) {
    result.error = browserKit.firstLine(e);
  } finally {
    await browser?.close();
    fs.writeFileSync(path.join(job.outDir, 'result.json'), JSON.stringify(result));
  }
  process.exit(0);
})();
