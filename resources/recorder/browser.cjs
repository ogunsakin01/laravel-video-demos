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

const join = (base, p) => String(base).replace(/\/$/, '') + '/' + String(p).replace(/^\//, '');

// The full address of a page: on a separate frontend when there is one,
// otherwise on the Laravel app.
const url = (job, p) => join(job.frontendUrl || job.baseUrl, p);

// The full address of a path on the Laravel app (the API of a separate frontend).
const appUrl = (job, p) => join(job.baseUrl, p);

const parse = u => { try { return new URL(u); } catch { return null; } };

// Whether a request goes to the Laravel app from a separate frontend: to its
// origin, under its path.
function toApp(job, u) {
  if (!job.frontendUrl) return false;
  const a = parse(u), app = parse(job.baseUrl);
  return !!a && !!app && a.origin === app.origin && a.pathname.startsWith(app.pathname.replace(/\/$/, '') + '/');
}

// Whether a request goes to a separate frontend's own address, which a dev
// server may pass on to the app (Vite's proxy for /api, say).
const toFrontend = (job, u) => !!job.frontendUrl && parse(u)?.origin === parse(job.frontendUrl)?.origin;

// Whether a request is one of a separate frontend's calls to the app: to the
// app, or a fetch through the frontend's own address. Not Laravel Precognition's
// live validation or Echo's channel sign-in, which may refuse as a matter of course.
function isAppCall(job, request) {
  const u = request.url();
  if (!toApp(job, u) && !(toFrontend(job, u) && ['fetch', 'xhr'].includes(request.resourceType()))) return false;
  return !request.headers()['precognition'] && !/\/broadcasting\/auth\b/.test(parse(u)?.pathname || '');
}

// Whether an address is a sign-in page: by its path, or by a frontend's hash
// route (#/login), not an anchor on the page (#login).
function isSignIn(u) {
  const a = new URL(u);
  const hash = /^#!?\//.test(a.hash) ? a.hash.replace(/^#!?/, '') : '';
  return /\/(login|signin|sign-in)\b/.test(a.pathname) || /\/(login|signin|sign-in)(?=$|[/?#])/.test(hash);
}

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
    // A service worker's requests can't be given the sign-in's and sample data's headers.
    ...(job.frontendUrl ? { serviceWorkers: 'block' } : {}),
    ...extra,
  };
}

// Open a browser context for a screen. On sample data, its cookie sends every
// request to the video's own database.
async function newContext(browser, job, screen = job, extra = {}) {
  const context = await browser.newContext(contextOptions(job, screen, extra));
  if (job.cookies?.length) await context.addCookies(job.cookies);
  if (job.frontendUrl) {
    // Requests to the Laravel app get the sample database's header and the
    // sign-in's headers, and so do those to the frontend's own address, which
    // its dev server may pass on to the app. Added here, the browser doesn't
    // ask the app's CORS rules about them.
    await context.route(r => toApp(job, r) || toFrontend(job, r), route => route.continue({ headers: { ...route.request().headers(), ...appHeaders(job) } }));
    await applySignIn(context, job);
  }
  return context;
}

// The headers added to a separate frontend's requests to the Laravel app.
function appHeaders(job) {
  return {
    ...(job.sandboxHeader ? { [job.sandboxHeader.name.toLowerCase()]: job.sandboxHeader.value } : {}),
    ...Object.fromEntries(Object.entries(job.signIn?.headers || {}).map(([k, v]) => [k.toLowerCase(), v])),
  };
}

// Put a separate frontend's sign-in into a context: cookies for the Laravel
// app, and storage entries on the frontend, set before its scripts run and
// only where the frontend hasn't stored its own (after a token refresh, say).
async function applySignIn(context, job) {
  const s = job.signIn;
  if (!s) return;
  // Over HTTPS the cookies are sent from a frontend on another site too.
  const secure = parse(job.baseUrl)?.protocol === 'https:';
  if (s.cookies?.length) await context.addCookies(s.cookies.map(c => ({ name: c.name, value: c.value, url: appUrl(job, '/'), ...(secure ? { sameSite: 'None', secure: true } : {}) })));
  if (Object.keys(s.localStorage || {}).length || Object.keys(s.sessionStorage || {}).length) {
    // Once per tab, so a video that signs out stays signed out.
    await context.addInitScript(({ origin, local, session }) => {
      if (location.origin !== origin || sessionStorage.getItem('video-demo-signed-in')) return;
      for (const [k, v] of Object.entries(local)) if (localStorage.getItem(k) === null) localStorage.setItem(k, v);
      for (const [k, v] of Object.entries(session)) if (sessionStorage.getItem(k) === null) sessionStorage.setItem(k, v);
      sessionStorage.setItem('video-demo-signed-in', '1');
    }, { origin: new URL(job.frontendUrl).origin, local: s.localStorage || {}, session: s.sessionStorage || {} });
  }
}

// Sign the context in as the video's user: through the studio's sign-in link,
// or for a separate frontend's tokens by fetching them from the app once and
// putting them in this context and every later one. Throws when that fails,
// saying why; does nothing for a guest. Tokens never leave this process.
async function signIn(context, job) {
  if (job.signInPath) {
    if (!job.signIn) {
      const res = await context.request.get(appUrl(job, job.signInPath), {
        headers: job.sandboxHeader ? { [job.sandboxHeader.name]: job.sandboxHeader.value } : {},
        timeout: TIMEOUTS.signIn,
        failOnStatusCode: false,
      });
      const body = await res.json().catch(() => null);
      if (!res.ok() || !body) {
        throw new Error(body?.error || `Signing in failed with HTTP ${res.status()}. Is the studio enabled and the app running at ${job.baseUrl}?`);
      }
      job.signIn = body;
      // Later contexts get it from newContext.
      await applySignIn(context, job);
    }
    return;
  }
  if (!job.loginPath) return;
  const page = await context.newPage();
  try {
    const res = await page.goto(appUrl(job, job.loginPath), { waitUntil: 'domcontentloaded', timeout: TIMEOUTS.signIn });
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

// Describe a failed request to the Laravel app, with the message Laravel
// sends in JSON ("message"), such as "POST /api/jobs returned HTTP 500: …".
async function describeAppError(response) {
  // A body that never ends (a stream) doesn't hold the recording up.
  const body = await Promise.race([response.json().catch(() => null), new Promise(done => setTimeout(() => done(null), 2000))]);
  const message = body && typeof body === 'object' ? String(body.message || body.error || '').split('\n')[0] : '';
  const where = new URL(response.url()).pathname;
  return `${response.request().method()} ${where} returned HTTP ${response.status()}` + (message ? `: ${message}` : '');
}

// Describe what is on a page for the AI model: its accessibility tree (headings,
// links, buttons, fields with their labels, text) and what is inside its iframes,
// cut to `limit` characters. Used when a step fails, and when the model looks at
// a page while planning.
async function snapshot(page, limit = 12000) {
  let tree = '';
  try {
    tree = await page.locator('body').ariaSnapshot({ timeout: 3000 });
  } catch {}

  // Add the page's iframes and what's inside them, so the AI model can target
  // fields in embedded forms (payment card fields, sign-in widgets) with target.frame.
  try {
    const frames = await page.locator('iframe').evaluateAll(els => els.map(f => ({
      title: f.getAttribute('title'), name: f.getAttribute('name'), id: f.id,
      src: (f.getAttribute('src') || '').slice(0, 120), visible: f.getBoundingClientRect().height > 0,
    })));
    const shown = frames.map((f, i) => ({ ...f, i })).filter(f => f.visible).slice(0, 5);
    for (const f of shown) {
      const selector = f.title ? `iframe[title="${f.title}"]` : f.name ? `iframe[name="${f.name}"]` : f.id ? `iframe#${f.id}` : `iframe >> nth=${f.i}`;
      let inside = '';
      try {
        inside = await page.frameLocator(`iframe >> nth=${f.i}`).locator('body').ariaSnapshot({ timeout: 3000 });
      } catch {}
      tree += `\n\n- iframe (target.frame: ${selector})${f.src ? ` src=${f.src}` : ''}:\n` + inside.split('\n').map(l => '  ' + l).join('\n');
    }
  } catch {}

  return tree.length > limit ? tree.slice(0, limit) + '\n… cut' : tree;
}

// The first line of an error, for a result.
const firstLine = e => String(e?.message || e).split('\n')[0];

module.exports = { TIMEOUTS, url, appUrl, toApp, toFrontend, isAppCall, describeAppError, snapshot, isSignIn, launch, contextOptions, newContext, signIn, settle, open, firstLine };
