// Opens each page a video will use and reports the ones that fail, so a broken
// page is found before the AI model is asked to plan or repair anything.
//   node check.cjs <job.json>
// The job gives the paths, the app's address, the sign-in link and the screen.
// Writes result.json: { problems: [{ path, problem, status, url, detail }], languages: { path: lang } },
// languages being each page's <html lang>, for the language the video is made in.
const fs = require('fs');
const path = require('path');
const browserKit = require('./browser.cjs');
const { TIMEOUTS, isSignIn, launch, newContext, signIn, settle, firstLine } = browserKit;

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const url = p => browserKit.url(job, p);

// Read the error a page shows, preferring Laravel's exception message.
async function describeError(page) {
  return page.evaluate(() => {
    const pick = sel => document.querySelector(sel)?.textContent?.trim();
    return pick('.exception-message') || pick('[data-exception-message]') || pick('h1') || document.title || '';
  }).catch(() => '');
}

(async () => {
  const problems = [];
  const languages = {};
  let browser;
  try {
    browser = await launch(job);
    const context = await newContext(browser, job);
    try {
      await signIn(context, job);
    } catch (e) {
      problems.push({ path: '(sign in)', problem: 'Signing the recorder in failed', status: null, url: null, detail: firstLine(e) });
    }
    const page = await context.newPage();

    for (const p of job.paths) {
      const jsErrors = [];
      const onError = e => jsErrors.push(String(e.message || e).split('\n')[0]);
      page.on('pageerror', onError);
      try {
        // A slow image or script from another site doesn't make the page broken.
        const res = await page.goto(url(p), { waitUntil: 'domcontentloaded', timeout: TIMEOUTS.page });
        await settle(page);
        const status = res ? res.status() : null;
        const finalUrl = page.url();
        const lang = await page.evaluate(() => document.documentElement.lang || '').catch(() => '');
        const before = problems.length;

        if (status && status >= 400) {
          // Laravel's error pages only repeat the status ("404 | Not Found"), so keep only a detail that says more.
          const detail = (await describeError(page)).slice(0, 400);
          const generic = /^(\d{3}\b)?[\s|·-]*(not found|server error|forbidden|unauthorized|page expired|too many requests|service unavailable)?$/i.test(detail.trim());
          problems.push({ path: p, problem: `The page returned HTTP ${status}`, status, url: finalUrl, detail: generic ? null : detail });
        } else if (isSignIn(finalUrl) && !isSignIn(url(p))) {
          problems.push({ path: p, problem: job.loginPath ? 'It redirected to the sign-in page: this user may not have access' : 'It needs signing in, but the video is recorded as a guest', status, url: finalUrl, detail: null });
        } else if (jsErrors.length) {
          problems.push({ path: p, problem: 'The page threw a JavaScript error', status, url: finalUrl, detail: jsErrors.slice(0, 3).join(' · ').slice(0, 400) });
        }
        // Only a page that works says which language the video is made in.
        if (lang && problems.length === before) languages[p] = lang;
      } catch (e) {
        problems.push({ path: p, problem: 'The page did not load', status: null, url: null, detail: firstLine(e).slice(0, 300) });
      }
      page.off('pageerror', onError);
    }
  } catch (e) {
    problems.push({ path: '(browser)', problem: 'The page check could not run', status: null, url: null, detail: firstLine(e) });
  } finally {
    await browser?.close();
    fs.writeFileSync(path.join(job.outDir, 'result.json'), JSON.stringify({ problems, languages }, null, 2));
  }
  process.exit(0);
})();
