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
const SNAPSHOT_LIMIT = 12000;

const url = p => browserKit.url(job, p);

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

async function find(page, t) {
  const loc = locate(page, t);
  try {
    await waiting(() => loc.waitFor({ state: 'visible', timeout: FIND_TIMEOUT }));
  } catch {
    throw new Error(`Could not find a visible element with ${describe(t)}.`);
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

async function run(page, step, isLast) {
  const extra = (step.ms || 0) * SPEED;
  switch (step.action) {
    case 'goto':
      await wide(page);
      // Only the page itself must arrive; images and scripts get settle()'s grace period.
      await waiting(() => page.goto(url(step.url), { waitUntil: 'domcontentloaded', timeout: 60000 }));
      await settle(page);
      if (browserKit.isSignIn(page.url()) && !browserKit.isSignIn(url(step.url))) {
        throw new Error(`Opening ${step.url} went to the sign-in page instead. ${job.loginPath ? "The recorder's user may not have access." : 'The recorder is signed out; set video-demo.recorder.login_as.'}`);
      }
      // Time to take in the new page before anything moves.
      await pause(page, 1100 + extra);
      return;
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

// Describe what was on the page when a step failed, so the AI model can fix the plan.
async function snapshot(page) {
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

  return tree.length > SNAPSHOT_LIMIT ? tree.slice(0, SNAPSHOT_LIMIT) + '\n… cut' : tree;
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

    // Sign in first, outside the recording.
    let storageState;
    if (job.loginPath) {
      const ctx = await browserKit.newContext(browser, job, job, theme);
      await browserKit.signIn(ctx, job);
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
    page.on('response', r => {
      if (r.status() >= 500 && r.request().isNavigationRequest()) appErrors.push({ kind: 'server', status: r.status(), url: r.url(), at: Date.now() });
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
        // A step with a spoken line lasts until the line is finished.
        const left = (step.hold_ms || 0) - (Date.now() - stepStarted);
        if (left > 0) await pause(page, left);
      } catch (e) {
        result.failedStep = i + 1;
        result.error = String(e.message || e).split('\n')[0];
        result.errorFull = String(e.message || e).slice(0, 4000);
        result.url = page.url();
        result.screenshot = await page.screenshot({ path: path.join(job.outDir, 'failure.jpg'), type: 'jpeg', quality: 75 }).then(() => path.join(job.outDir, 'failure.jpg')).catch(() => null);
        result.title = await page.title().catch(() => '');
        result.snapshot = await snapshot(page);
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
