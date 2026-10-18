// Plays a video plan in a real browser and records it.
//   node record.cjs <job.json>
// The job file gives the plan file, the app's address, an optional sign-in
// link, the window size and where to write the recording. Writes result.json
// next to the recording: ok, how long it ran, and on failure the step that
// failed, the error and what was on the page (so the AI model can fix the plan).
// With job.dryRun, the plan is played as fast as it goes, with nothing recorded
// and no cursor, captions or cards, to check that every step still works.
const fs = require('fs');
const path = require('path');
const browserKit = require('./browser.cjs');
const { CURSOR_JS, OVERLAY_JS, HIGHLIGHT_JS, setPace, typingDelay, pause, glide, click, scrollBy, scrollTo } = require('./helpers.cjs');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const plan = JSON.parse(fs.readFileSync(job.planPath, 'utf8'));
// The video is played back this many times faster after recording, so what
// viewers must read (captions, cards, pauses) is held this much longer now.
const SPEED = job.speed || 1;
const style = Object.assign({ theme: 'light', accent: '#171717', captions: 'bottom', pace: 'normal', speed: SPEED }, job.style || {});
const DRY = !!job.dryRun;
setPace(DRY ? 'instant' : style.pace);
const FIND_TIMEOUT = 8000;

// Where each step happened, for styles that zoom in on it while encoding:
// { t: ms since recording started, x, y, w, h } for an element, or { t, wide: true }
// when the whole page should show again.
const focus = [];
// How many title cards and captions have been shown, to number them in styles that do.
let headlines = 0;
let started = 0;
// Whether the browser is signed in: from the start, or since a sign_in step.
let signedIn = !!(job.loginPath || job.signInPath);

// Time spent waiting on the app (pages loading, something appearing), in ms
// since recording started. Most of it is cut from the video, so a click goes
// straight to its result, as in a fast app.
const idle = [];
async function waiting(work) {
  const from = started ? Date.now() - started : 0;
  try {
    return await work();
  } finally {
    const to = started ? Date.now() - started : 0;
    if (started && to - from > 250) idle.push([from, to]);
  }
}

// Bring an element into view, note where it is, and outline it if the style asks.
async function focusOn(page, loc) {
  await scrollTo(page, loc).catch(() => {});
  const box = await loc.boundingBox().catch(() => null);
  if (!box) return;
  focus.push({ t: Date.now() - started, x: box.x, y: box.y, w: box.width, h: box.height });
  if (style.highlight) await loc.evaluate(el => window.__demoHighlight && window.__demoHighlight.show(el)).catch(() => {});
}

// Show the whole page again: the camera pulls back and the outline goes.
async function wide(page) {
  focus.push({ t: Date.now() - started, wide: true });
  if (style.highlight) await page.evaluate(() => window.__demoHighlight && window.__demoHighlight.hide()).catch(() => {});
}

const url = p => browserKit.url(job, p);

// End text with a full stop unless it already ends a sentence.
const sentence = text => (/[.!?]$/.test(text) ? text : text + '.');

// Build a locator for a plan target, inside an iframe when target.frame is set.
function locate(page, t) {
  if (!t) throw new Error('This step needs a target.');
  // Inside an iframe, such as the card fields of an embedded payment form.
  const scope = t.frame ? page.frameLocator(t.frame).first() : page;
  let loc;
  if (t.role) loc = scope.getByRole(t.role, t.name ? { name: t.name } : {});
  else if (t.label) loc = scope.getByLabel(t.label);
  else if (t.placeholder) loc = scope.getByPlaceholder(t.placeholder);
  else if (t.text) loc = scope.getByText(t.text);
  else if (t.selector) loc = scope.locator(t.selector);
  else throw new Error('The target has no role, label, placeholder, text or selector.');
  return loc.filter({ visible: true }).first();
}

function describe(t) {
  if (!t) return 'nothing';
  return Object.entries(t).filter(([, v]) => v).map(([k, v]) => `${k} "${v}"`).join(', ');
}

// For a role without a name but with a label or placeholder, the element of
// that role the label or placeholder belongs to, then one named by it; when the
// page has neither, the first of that role, as before.
function narrowed(page, t) {
  if (!t.role || t.name || !(t.label || t.placeholder)) return [];
  const scope = t.frame ? page.frameLocator(t.frame).first() : page;
  const byRole = scope.getByRole(t.role);
  return [
    byRole.and(t.label ? scope.getByLabel(t.label) : scope.getByPlaceholder(t.placeholder)),
    scope.getByRole(t.role, { name: t.label || t.placeholder }),
  ].map(loc => loc.filter({ visible: true }).first());
}

async function find(page, t) {
  const loc = locate(page, t);
  try {
    await waiting(() => loc.waitFor({ state: 'visible', timeout: FIND_TIMEOUT }));
  } catch {
    throw new Error(`Could not find a visible element with ${describe(t)}.`);
  }
  // Otherwise "the first text box" may be the wrong field of a form.
  for (const better of narrowed(page, t)) {
    if (await better.count().catch(() => 0)) return better;
  }
  return loc;
}

// Wait for the page to load and its network to go quiet, without failing if it never does:
// a slow image or script from another site must not stop the video. A recording
// waits a little longer for quiet than a check, so pages are shown finished.
const RECORDING_QUIET = 4000;
async function settle(page) {
  await waiting(() => browserKit.settle(page, RECORDING_QUIET));
}

// Say why a page that answered 4xx can't be in the video, with what it says.
async function blockedPage(page, hit) {
  const where = new URL(hit.url).pathname;
  const says = await page.evaluate(() => {
    const text = el => (el?.innerText || '').trim().replace(/\s+/g, ' ');
    return [text(document.querySelector('h1')), text(document.querySelector('main p, p'))].filter(Boolean).join(' ').slice(0, 240);
  }).catch(() => '');
  const why = hit.status === 403
    ? "This user isn't allowed to see it yet. On sample data, give them what the page checks for with seed_data (a sign_in step's user is @user_<type>) and say so in the video, or leave the page out."
    : hit.status === 404 ? 'There is no such page, or its record is missing: check the path, or add the record with seed_data.'
    : hit.status === 401 ? 'It needs signing in: add a sign_in step before it.'
    : 'Change the plan so it isn\'t shown.';
  return `${where} answered HTTP ${hit.status}${says ? ` ("${says}")` : ''}, so it can't be shown as if it worked. ${why}`;
}

async function run(page, step, isLast) {
  const extra = (step.ms || 0) * SPEED;
  switch (step.action) {
    case 'goto':
      await wide(page);
      // Only the page itself must arrive; images and scripts get settle()'s grace period.
      await waiting(() => page.goto(url(step.url), { waitUntil: 'domcontentloaded', timeout: 60000 }));
      await settle(page);
      if (browserKit.isSignIn(page.url()) && !browserKit.isSignIn(url(step.url))) {
        throw new Error(`Opening ${step.url} went to the sign-in page instead. ${signedIn ? "The recorder's user may not have access." : 'The video is signed out here: add a sign_in step before this page.'}`);
      }
      if (browserKit.isVerifyEmail(page.url()) && !browserKit.isVerifyEmail(url(step.url))) {
        throw new Error(`Opening ${step.url} went to the email verification page instead: this user hasn't verified their email, which a recording can't do. ${signedIn ? 'Sign in with a sign_in step as a user whose email is verified.' : 'Add a sign_in step before this page.'}`);
      }
      // Time to take in the new page before anything moves.
      await pause(page, 1100 + extra);
      return;
    case 'sign_in': {
      // Signs in out of view, then opens the page as that user, as goto does.
      const entry = (job.signInAs || {})[(step.value || '').trim()];
      if (!entry) throw new Error('There is no sign-in for this step\'s user. Record the video again so the app makes one.');
      await waiting(() => browserKit.signInAs(page.context(), job, entry));
      signedIn = true;
      await run(page, { ...step, action: 'goto' }, isLast);
      return;
    }
    case 'click': {
      const loc = await find(page, step.target);
      await focusOn(page, loc);
      await click(page, loc);
      await settle(page);
      // Time to see what the click did.
      await pause(page, 900 + extra);
      return;
    }
    case 'hover': {
      const loc = await find(page, step.target);
      await focusOn(page, loc);
      await glide(page, loc);
      await loc.hover();
      await pause(page, 600 + extra);
      return;
    }
    case 'check': {
      const loc = await find(page, step.target);
      await focusOn(page, loc);
      await glide(page, loc);
      await loc.check();
      await pause(page, 400 + extra);
      return;
    }
    case 'type': {
      const loc = await find(page, step.target);
      await focusOn(page, loc);
      await click(page, loc);
      if (await loc.inputValue().catch(() => '')) await loc.fill('');
      await loc.pressSequentially(String(step.value), { delay: typingDelay() });
      await pause(page, 400 + extra);
      return;
    }
    case 'select': {
      const loc = await find(page, step.target);
      await focusOn(page, loc);
      await glide(page, loc);
      const isNative = await loc.evaluate(el => el.tagName === 'SELECT');
      if (isNative) {
        await loc.selectOption({ label: String(step.value) });
      } else {
        await click(page, loc);
        await click(page, await find(page, { role: 'option', name: String(step.value) }));
      }
      await pause(page, 500 + extra);
      return;
    }
    case 'press':
      await page.keyboard.press(String(step.value));
      await settle(page);
      await pause(page, 300 + extra);
      return;
    case 'scroll':
      await wide(page);
      if (step.target) await scrollTo(page, await find(page, step.target));
      else await scrollBy(page, page.viewportSize().height * 0.7);
      await pause(page, 400 + extra);
      return;
    case 'wait':
      if (step.target) {
        const loc = locate(page, step.target);
        await waiting(() => loc.waitFor({ state: 'visible', timeout: 15000 })).catch(() => {
          throw new Error(`Waited 15 seconds for ${describe(step.target)} to appear.`);
        });
      } else {
        await settle(page);
      }
      await pause(page, extra);
      return;
    case 'caption':
      // A check only needs the steps that use the app.
      if (DRY) return;
      await wide(page);
      await page.evaluate(([t, s, n]) => window.__demoOverlay.caption(t, s, n), [step.title, step.text || '', ++headlines]);
      await pause(page, (step.ms || 3000) * SPEED);
      // The last step stays on screen, so the video doesn't end mid-fade.
      if (isLast) return;
      await page.evaluate(() => window.__demoOverlay.hide());
      await pause(page, 350 * SPEED);
      return;
    case 'card':
      if (DRY) return;
      await wide(page);
      await page.evaluate(([t, s, n]) => window.__demoOverlay.card(t, s, n), [step.title, step.text || '', ++headlines]);
      await pause(page, (step.ms || 3500) * SPEED);
      if (isLast) return;
      await page.evaluate(() => window.__demoOverlay.hide());
      await pause(page, 450 * SPEED);
      return;
    case 'pause':
      if (DRY) return;
      await pause(page, (step.ms || 1500) * SPEED);
      return;
    default:
      throw new Error(`Unknown action ${step.action}.`);
  }
}


(async () => {
  // starts: when each step began, in ms since recording started, so voice-over lines can be placed.
  const result = { ok: false, video: null, durationMs: 0, trimMs: 0, chapters: [], starts: [], focus, failedStep: null, error: null, url: null, title: null, snapshot: null, idle, speaking: [] };
  // For the full error view: the browser's own messages and failed requests, and the page when it failed.
  const browserLog = [];
  const log = (kind, text, extra = {}) => {
    browserLog.push({ kind, text: String(text).slice(0, 1000), t: started ? Date.now() - started : 0, ...extra });
    if (browserLog.length > 60) browserLog.shift();
  };
  // Started inside the try, so a browser that won't start is reported in the result.
  let browser, page;
  try {
    browser = await browserKit.launch(job);
    // Playwright records at the page's own size, so each version is recorded at its screen size.
    // The app is recorded in light or dark mode, for apps that follow the system setting.
    const theme = style.app_theme === 'dark' || style.app_theme === 'light' ? { colorScheme: style.app_theme } : {};

    // Sign in first, and accept a cookie banner, outside the recording: the
    // banner's choice is kept, so it shows on no page of the video.
    let storageState;
    const banner = (job.cookieBanner || 'accept') !== 'leave';
    if (job.loginPath || job.signInPath || banner) {
      const ctx = await browserKit.newContext(browser, job, job, theme);
      await browserKit.signIn(ctx, job);
      if (banner) {
        const first = plan.steps.find(s => s.action === 'goto' && s.url);
        const tab = await ctx.newPage();
        try {
          await tab.goto(url(first ? first.url : '/'), { waitUntil: 'domcontentloaded', timeout: 60000 });
          await browserKit.settle(tab);
          await browserKit.acceptCookies(tab, job);
        } catch {
          // A page that doesn't open here fails in the recording, with its reason.
        } finally {
          await tab.close().catch(() => {});
        }
      }
      storageState = await ctx.storageState();
      await ctx.close();
    }

    const context = await browserKit.newContext(browser, job, job, { ...theme, storageState, ...(DRY ? {} : { recordVideo: { dir: job.outDir, size: job.viewport } }) });
    await context.addInitScript(`window.__demoStyle = ${JSON.stringify(style)};`);
    // Touch screens show where a finger taps instead of a cursor.
    if (job.touch) await context.addInitScript('window.__demoTouch = true;');
    if (!DRY) {
      await context.addInitScript(CURSOR_JS);
      await context.addInitScript(OVERLAY_JS);
      if (style.highlight) await context.addInitScript(HIGHLIGHT_JS);
    }
    page = await context.newPage();
    // The video's first frame is the page's: setting up the browser before it isn't in it.
    const recordingStarted = Date.now();
    // Installed Chrome starts with a shorter window, and its capture only takes
    // the full size after a real navigation. So wait, then navigate to a plain
    // page for anything shown before the first page opens, and cut everything
    // before that from the video.
    if (!DRY) await page.waitForTimeout(600);
    const blank = `<html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:${style.theme === 'dark' ? '#171717' : '#faf8f3'}"></body></html>`;
    await page.goto('data:text/html,' + encodeURIComponent(blank));
    if (!DRY) await page.waitForTimeout(400);
    started = Date.now();
    // A new page is shown whole: the camera pulls back the moment it opens.
    page.on('framenavigated', frame => { if (frame === page.mainFrame()) focus.push({ t: Date.now() - started, wide: true }); });
    result.trimMs = started - recordingStarted;

    // Errors from the app itself: a step that fails after one of these is a
    // bug in the app, not something a better plan can fix.
    const appErrors = [];
    // A separate frontend's requests the app refused (a 419 CSRF mismatch, a
    // 403, a 422 for a field left empty): changes it sent that didn't happen,
    // which the video would show failing. Reading data (GET) is left alone, as
    // an optional endpoint may answer 404.
    const appRefusals = [];
    const sentAt = new WeakMap();
    page.on('request', r => sentAt.set(r, Date.now()));
    // Fail on the app's refusals, blaming the step that sent the request. The
    // responses are given a moment to arrive, longer after the last step.
    async function checkRefusals(wait) {
      await waiting(() => page.waitForTimeout(wait));
      const refused = appRefusals.filter(a => !a.reported);
      if (!refused.length) return;
      await Promise.all(refused.map(a => a.done));
      refused.forEach(a => { a.reported = true; });
      const sent = Math.min(...refused.map(a => a.sent)) - started;
      const error = new Error(`The app refused ${refused.length === 1 ? 'a request' : refused.length + ' requests'}: ${sentence(refused.slice(-3).map(a => a.text).join(' · '))}`);
      error.step = Math.max(0, result.starts.filter(s => s <= sent).length - 1);
      throw error;
    }
    page.on('response', async r => {
      // A separate frontend's pages load their data from the app, so its errors count too.
      const fromApp = browserKit.toApp(job, r.url()) || browserKit.isAppCall(job, r.request());
      if (fromApp && r.status() >= 400 && r.status() < 500 && r.request().method() !== 'GET' && browserKit.isAppCall(job, r.request()) && !(r.status() === 401 && !signedIn)) {
        const refusal = { sent: sentAt.get(r.request()) ?? Date.now(), status: r.status(), text: '' };
        refusal.done = browserKit.describeAppError(r).then(text => { refusal.text = text; });
        appRefusals.push(refusal);
      }
      if (r.status() >= 500 && (r.request().isNavigationRequest() || fromApp)) {
        const error = { kind: 'server', status: r.status(), url: r.url(), at: Date.now() };
        appErrors.push(error);
        if (fromApp) error.message = await browserKit.describeAppError(r);
      }
    });
    // Pages the browser opened that answered 4xx: one this user may not see yet
    // (a 403 profile gate) or one that isn't there. A video would read over them as if they worked.
    const blocked = [];
    page.on('response', r => {
      if (r.request().isNavigationRequest() && r.frame() === page.mainFrame() && r.status() >= 400 && r.status() < 500) blocked.push({ status: r.status(), url: r.url(), at: Date.now() });
    });
    page.on('pageerror', e => appErrors.push({ kind: 'javascript', message: String(e.message || e).split('\n')[0], url: page.url(), at: Date.now() }));
    page.on('pageerror', e => log('exception', e.stack || e.message || e, { url: page.url() }));
    page.on('console', m => { if (['error', 'warning'].includes(m.type())) log(m.type(), m.text(), { url: m.location()?.url || null }); });
    page.on('response', r => { if (r.status() >= 400) log('response', `${r.request().method()} ${r.url()}`, { status: r.status() }); });
    page.on('requestfailed', r => log('failed', `${r.method()} ${r.url()}`, { reason: r.failure()?.errorText || null }));

    for (let i = 0; i < plan.steps.length; i++) {
      const step = plan.steps[i];
      if (step.chapter) result.chapters.push({ title: step.chapter, at: (Date.now() - started) / 1000 });
      const stepStarted = Date.now();
      result.starts.push(stepStarted - started);
      // While a line is spoken, nothing is cut, or the voice would run into the next one.
      if (step.hold_ms) result.speaking.push([stepStarted - started, stepStarted - started + step.hold_ms]);
      try {
        await run(page, step, i === plan.steps.length - 1);
        const hit = blocked.filter(b => b.at >= stepStarted).pop();
        if (hit) throw new Error(await blockedPage(page, hit));
        // A step with a spoken line lasts until the line is finished.
        const left = (step.hold_ms || 0) - (Date.now() - stepStarted);
        if (left > 0) await pause(page, left);
        // On a separate frontend, the app refusing a change a step sent means the
        // video would show it failing. Captions, cards and pauses send nothing,
        // but the last step waits for any late answers.
        const last = i === plan.steps.length - 1;
        if (job.frontendUrl && (last || !['caption', 'card', 'pause'].includes(step.action))) await checkRefusals(last ? 1500 : 300);
      } catch (e) {
        result.failedStep = (e.step ?? i) + 1;
        result.error = String(e.message || e).split('\n')[0];
        // Since the step before this one, which may have sent what this one waits for.
        const since = started + (result.starts[Math.max(0, i - 1)] ?? 0);
        const pending = appRefusals.filter(a => a.sent >= since && !a.reported);
        await Promise.all(pending.map(a => a.done));
        const refused = pending.map(a => a.text).filter(Boolean);
        if (refused.length) result.error += ` Meanwhile, the app refused ${refused.length === 1 ? 'a request' : refused.length + ' requests'}: ${sentence(refused.slice(-3).join(' · '))}`;
        result.errorFull = String(e.message || e).slice(0, 4000);
        result.url = page.url();
        result.screenshot = await page.screenshot({ path: path.join(job.outDir, 'failure.jpg'), type: 'jpeg', quality: 75 }).then(() => path.join(job.outDir, 'failure.jpg')).catch(() => null);
        result.title = await page.title().catch(() => '');
        result.snapshot = await browserKit.snapshot(page, 12000);
        // A server error or JavaScript error during this step means the app is broken here.
        const appError = appErrors.filter(a => a.at >= stepStarted - 2000).pop();
        if (appError) {
          const detail = await page.evaluate(() => (document.querySelector('.exception-message')?.textContent || document.querySelector('h1')?.textContent || document.title || '').trim()).catch(() => '');
          result.appError = { ...appError, detail: (appError.message || detail || '').slice(0, 400) };
        }
        break;
      }
    }

    result.durationMs = Date.now() - started;
    result.ok = result.failedStep === null;
    const video = page.video();
    await context.close();
    if (video) result.video = await video.path();
  } catch (e) {
    result.error = String(e.message || e).split('\n')[0];
  } finally {
    await browser?.close();
    result.browserLog = browserLog;
    fs.writeFileSync(path.join(job.outDir, 'result.json'), JSON.stringify(result, null, 2));
  }
  process.exit(0);
})();
