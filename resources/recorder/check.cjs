// Opens each page a video will use and reports the ones that fail, so a broken
// page is found before the AI model is asked to plan or repair anything.
//   node check.cjs <job.json>
// The job gives the paths, the app's address, the sign-in link and the screen.
// Writes result.json: { problems: [{ path, problem, status, url, detail }] }.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const url = p => job.baseUrl.replace(/\/$/, '') + '/' + String(p).replace(/^\//, '');
const isSignIn = u => /\/(login|signin|sign-in)\b/.test(new URL(u).pathname);

// Read the error a page shows, preferring Laravel's exception message.
async function describeError(page) {
  return page.evaluate(() => {
    const pick = sel => document.querySelector(sel)?.textContent?.trim();
    return pick('.exception-message') || pick('[data-exception-message]') || pick('h1') || document.title || '';
  }).catch(() => '');
}

(async () => {
  const problems = [];
  const browser = await chromium.launch(job.channel ? { channel: job.channel } : {});
  try {
    const context = await browser.newContext({
      viewport: job.viewport, hasTouch: !!job.touch,
      isMobile: !!job.touch && job.viewport.width < 600, ignoreHTTPSErrors: !!job.ignoreHttpsErrors,
    });
    // Sample data: the cookie sends every request to the video's own database.
    if (job.cookies?.length) await context.addCookies(job.cookies);
    const page = await context.newPage();

    if (job.loginPath) {
      const res = await page.goto(url(job.loginPath), { waitUntil: 'domcontentloaded', timeout: 60000 });
      if (!res || res.status() >= 400) {
        problems.push({ path: '(sign in)', problem: `Signing the recorder in failed with HTTP ${res ? res.status() : 'no response'}`, status: res ? res.status() : null, url: null, detail: 'Is the studio enabled and the app running at ' + job.baseUrl + '?' });
      }
    }

    for (const p of job.paths) {
      const jsErrors = [];
      const onError = e => jsErrors.push(String(e.message || e).split('\n')[0]);
      page.on('pageerror', onError);
      try {
        // A slow image or script from another site doesn't make the page broken.
        const res = await page.goto(url(p), { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForLoadState('load', { timeout: 10000 }).catch(() => {});
        await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
        const status = res ? res.status() : null;
        const finalUrl = page.url();

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
      } catch (e) {
        problems.push({ path: p, problem: 'The page did not load', status: null, url: null, detail: String(e.message || e).split('\n')[0].slice(0, 300) });
      }
      page.off('pageerror', onError);
    }
  } catch (e) {
    problems.push({ path: '(browser)', problem: 'The page check could not run', status: null, url: null, detail: String(e.message || e).split('\n')[0] });
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(job.outDir, 'result.json'), JSON.stringify({ problems }, null, 2));
  }
  process.exit(0);
})();
