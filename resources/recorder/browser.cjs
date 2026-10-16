// What every script that opens the app shares: its address, the browser, the
// screen, the sample data cookie and signing in, so they all open the app the
// same way. Used by record.cjs, check.cjs, pages.cjs and spots.cjs.
const { chromium } = require('playwright');

// How long to wait, in milliseconds.
const TIMEOUTS = {
  // Signing in can start the app's first, slowest request.
  signIn: 60000,
  page: 30000,
  load: 10000,
  // How long the network must be quiet for a page to count as settled.
  quiet: 3000,
};

// The full address of an app path.
const url = (job, p) => job.baseUrl.replace(/\/$/, '') + '/' + String(p).replace(/^\//, '');

// Whether an address is a sign-in page.
const isSignIn = u => /\/(login|signin|sign-in)\b/.test(new URL(u).pathname);

// Start the browser: the one Playwright installed, or an installed Chrome.
const launch = job => chromium.launch(job.channel ? { channel: job.channel } : {});

// The browser settings for a screen ({ viewport, touch }, the job's own by default).
function contextOptions(job, screen = job, extra = {}) {
  return {
    viewport: screen.viewport,
    hasTouch: !!screen.touch,
    // Phones get mobile layout handling (the page's meta viewport); tablets don't.
    isMobile: !!screen.touch && !!screen.viewport && screen.viewport.width < 600,
    ignoreHTTPSErrors: !!job.ignoreHttpsErrors,
    // A video in another language opens the app in that language.
    ...(job.locale ? { locale: job.locale } : {}),
    ...extra,
  };
}

// Open a browser context for a screen. On sample data, its cookie sends every
// request to the video's own database.
async function newContext(browser, job, screen = job, extra = {}) {
  const context = await browser.newContext(contextOptions(job, screen, extra));
  if (job.cookies?.length) await context.addCookies(job.cookies);
  return context;
}

// Sign the context in as the video's user through the studio's sign-in link.
// Throws when that fails, saying why; does nothing for a guest.
async function signIn(context, job) {
  if (!job.loginPath) return;
  const page = await context.newPage();
  try {
    const res = await page.goto(url(job, job.loginPath), { waitUntil: 'domcontentloaded', timeout: TIMEOUTS.signIn });
    if (!res || res.status() >= 400) {
      throw new Error(`Signing in failed with HTTP ${res ? res.status() : 'no response'}. Is the studio enabled and the app running at ${job.baseUrl}?`);
    }
  } finally {
    await page.close();
  }
}

// Wait for the page to load and its network to go quiet, without failing if it
// never does: a slow image or script from another site doesn't make it broken.
async function settle(page, quiet = TIMEOUTS.quiet) {
  await page.waitForLoadState('load', { timeout: TIMEOUTS.load }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: quiet }).catch(() => {});
}

// Open an app path in a new tab and let it settle. Returns the tab and the HTTP status.
async function open(context, job, p) {
  const page = await context.newPage();
  const res = await page.goto(url(job, p), { waitUntil: 'domcontentloaded', timeout: TIMEOUTS.page });
  await settle(page);
  return { page, status: res ? res.status() : null };
}

// The first line of an error, for a result.
const firstLine = e => String(e?.message || e).split('\n')[0];

module.exports = { TIMEOUTS, url, isSignIn, launch, contextOptions, newContext, signIn, settle, open, firstLine };
