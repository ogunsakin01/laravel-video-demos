// Captures dark-mode footage of the demo app for the promo, one clip per scene,
// at 2x pixel density through Chrome's screencast (sharper than Playwright's
// own video). Alongside each clip it writes markers: where things were on
// screen and when, so the edit can zoom and highlight precisely.
//   node capture.cjs [scene...]
// Needs the demo app served (composer serve, or testbench serve) and Playwright:
//   NODE_PATH=/path/to/node_modules PROMO_URL=http://127.0.0.1:8123 node capture.cjs
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');
const H = require(path.join(__dirname, '../../resources/recorder/helpers.cjs'));

const BASE = process.env.PROMO_URL || 'http://127.0.0.1:8123';
const OUT = path.join(__dirname, 'footage');
const FFMPEG = process.env.FFMPEG || 'ffmpeg';
H.setPace(1);

async function scene(browser, name, opts, run) {
  const dir = path.join(OUT, name);
  fs.rmSync(dir, { recursive: true, force: true });
  fs.mkdirSync(path.join(dir, 'frames'), { recursive: true });

  const ctx = await browser.newContext({ colorScheme: 'dark', viewport: opts.viewport, deviceScaleFactor: opts.scale, hasTouch: !!opts.touch, isMobile: !!opts.touch });
  if (opts.touch) await ctx.addInitScript('window.__demoTouch = true;');
  // A white cursor reads better on the dark theme.
  await ctx.addInitScript(H.CURSOR_JS.replace('fill="#111" stroke="#fff"', 'fill="#fff" stroke="#111"').replace('rgba(17,17,17,.18)', 'rgba(255,255,255,.25)'));
  const page = await ctx.newPage();
  if (opts.before) await opts.before(page);

  const cdp = await ctx.newCDPSession(page);
  const frames = [];
  let start = null;
  cdp.on('Page.screencastFrame', async f => {
    const t = f.metadata.timestamp;
    if (start === null) start = t;
    const file = path.join(dir, 'frames', String(frames.length).padStart(5, '0') + '.jpg');
    fs.writeFileSync(file, Buffer.from(f.data, 'base64'));
    frames.push({ file, t: t - start });
    cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {});
  });
  const w = opts.viewport.width * opts.scale, h = opts.viewport.height * opts.scale;
  await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: w, maxHeight: h, everyNthFrame: 1 });
  const t0 = Date.now();
  // Nudge the page so the first frame arrives straight away.
  await page.evaluate(() => { document.body.style.outline = '0 solid transparent'; });

  const markers = [];
  const mark = async (id, locator) => {
    const box = await locator.boundingBox();
    markers.push({ id, t: (Date.now() - t0) / 1000, x: box.x, y: box.y, w: box.width, h: box.height });
  };
  const at = id => markers.push({ id, t: (Date.now() - t0) / 1000 });

  await run(page, { mark, at });
  await page.waitForTimeout(600);
  await cdp.send('Page.stopScreencast');
  const total = (Date.now() - t0) / 1000;
  await ctx.close();

  // Each frame holds until the next one arrived, so timing matches real time.
  const list = frames.map((f, i) => `file '${f.file}'\nduration ${((frames[i + 1]?.t ?? total) - f.t).toFixed(4)}`).join('\n') + `\nfile '${frames.at(-1).file}'\n`;
  fs.writeFileSync(path.join(dir, 'frames.txt'), list);
  execFileSync(FFMPEG, ['-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', path.join(dir, 'frames.txt'),
    '-vf', `scale=${w}:${h}:flags=lanczos,format=yuv420p`, '-r', '30', '-fps_mode', 'cfr', '-c:v', 'libx264', '-crf', '14', '-preset', 'medium', path.join(OUT, `${name}.mp4`)]);
  fs.writeFileSync(path.join(OUT, `${name}.json`), JSON.stringify({ width: opts.viewport.width, height: opts.viewport.height, seconds: total, markers }, null, 2));
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(`${name}: ${total.toFixed(1)}s, ${frames.length} frames, ${markers.length} markers`);
}

const desktop = { viewport: { width: 1280, height: 800 }, scale: 2 };
const go = async (page, p) => { await page.goto(BASE + p, { waitUntil: 'networkidle' }); await page.waitForTimeout(500); };

const scenes = {
  async describe(b) {
    await scene(b, 'describe', { ...desktop, before: p => go(p, '/video-demo/studio/create') }, async (page, { mark, at }) => {
      await page.waitForTimeout(700);
      const prompt = page.locator('#prompt');
      await mark('prompt', prompt);
      await H.click(page, prompt);
      await prompt.pressSequentially('approve holiday request', { delay: 70 });
      await page.waitForTimeout(400);
      const btn = page.locator('#vd-complete');
      await mark('complete', btn);
      at('click');
      await H.click(page, btn);
      await page.locator('#vd-complete:not([aria-busy])').waitFor();
      at('done');
      await mark('prompt-filled', page.locator('#prompt'));
      await page.waitForTimeout(1600);
    });
  },

  async screens(b) {
    await scene(b, 'screens', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await p.evaluate(() => scrollTo(0, 260)); } }, async (page, { mark }) => {
      const grid = page.getByText('The AI writes one plan for all of them').locator('xpath=preceding-sibling::*[1]');
      await page.waitForTimeout(500);
      await mark('devices', grid);
      for (const label of ['Tablet', 'Mobile', 'TV']) {
        await H.click(page, page.locator('label', { hasText: label }).first());
        await page.waitForTimeout(350);
      }
      await page.waitForTimeout(900);
    });
  },

  async cost(b) {
    await scene(b, 'cost', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      await mark('estimate', page.locator('#vd-estimate').locator('xpath=ancestor::aside[1]'));
      await page.waitForTimeout(900);
      const style = page.locator('#style_id');
      await H.glide(page, style);
      await style.selectOption({ label: 'Promo ad' });
      await page.waitForTimeout(600);
      const limit = page.locator('#max_cost');
      await H.click(page, limit);
      await mark('limit', limit.locator('xpath=..'));
      await limit.fill('');
      await limit.pressSequentially('0.05', { delay: 110 });
      await page.waitForTimeout(500);
      at('warn');
      await page.waitForTimeout(1500);
      await limit.fill('');
      await limit.pressSequentially('0.50', { delay: 110 });
      await page.waitForTimeout(1300);
    });
  },

  async pages(b) {
    await scene(b, 'pages', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await p.locator('#start_path').scrollIntoViewIfNeeded(); await p.evaluate(() => scrollBy(0, 200)); } }, async (page, { mark }) => {
      const input = page.locator('#start_path');
      await H.click(page, input);
      await page.locator('#vd-pages [role=option]').first().waitFor({ timeout: 60000 });
      await page.waitForTimeout(500);
      await mark('list', page.locator('.vd-combo'));
      await input.pressSequentially('jobs', { delay: 110 });
      await page.waitForTimeout(900);
      await page.keyboard.press('ArrowDown');
      await page.waitForTimeout(500);
      await page.keyboard.press('Enter');
      await page.waitForTimeout(1200);
    });
  },

  async checks(b) {
    await scene(b, 'checks', { ...desktop, before: async p => { await go(p, '/video-demo/studio'); } }, async (page, { mark }) => {
      await page.waitForTimeout(400);
      await H.click(page, page.getByRole('link', { name: "Send this month's payslips" }));
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(700);
      await mark('issues', page.locator('#vd-issues'));
      await H.glide(page, page.locator('#vd-issues .vd-detail'));
      await page.waitForTimeout(2200);
    });
  },

  async review(b) {
    await scene(b, 'review', { ...desktop, before: async p => { await go(p, '/video-demo/studio/1'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(400);
      const player = page.locator('#vd-preview');
      await mark('player', player);
      await H.click(page, player);
      await page.evaluate(() => document.getElementById('vd-preview').play());
      await page.waitForTimeout(2200);
      await H.click(page, page.getByRole('button', { name: /Mobile/ }));
      await page.evaluate(() => document.getElementById('vd-preview').play());
      at('mobile');
      await page.waitForTimeout(2600);
      await mark('publish', page.getByRole('button', { name: /Publish/ }).first());
      await H.glide(page, page.getByRole('button', { name: /Publish/ }).first());
      await page.waitForTimeout(800);
    });
  },

  async embed(b) {
    await scene(b, 'embed', { ...desktop, before: async p => { await go(p, '/video-demo/studio/1'); await p.getByText('Blade, in this app').scrollIntoViewIfNeeded(); await p.evaluate(() => scrollBy(0, 150)); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const card = page.getByText('Blade, in this app').locator('xpath=ancestor::div[contains(@class,"vd-card")][1]');
      await mark('embed', card);
      const copy = page.locator('[data-copy]').first();
      await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
      await H.click(page, copy);
      at('copied');
      await page.waitForTimeout(1600);
      await H.click(page, page.locator('[data-copy]').nth(1));
      await page.waitForTimeout(1400);
    });
  },

  async bulk(b) {
    await scene(b, 'bulk', { ...desktop, before: async p => { await go(p, '/video-demo/studio'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      await mark('table', page.locator('.vd-table'));
      for (const name of ['Change notification emails', 'Review applicants', 'Post a job']) {
        await H.click(page, page.locator('tr', { hasText: name }).locator('input[type=checkbox]'));
        await page.waitForTimeout(250);
      }
      await page.waitForTimeout(400);
      await mark('bar', page.locator('#vd-bulk'));
      await H.glide(page, page.locator('#vd-bulk button[value=publish]'));
      at('publish');
      await page.waitForTimeout(1400);
    });
  },

  async library(b) {
    await scene(b, 'library', { ...desktop, before: async p => { await go(p, '/help/videos'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const q = page.locator('#vd-q');
      await mark('search', q);
      await H.click(page, q);
      await q.pressSequentially('applicants', { delay: 90 });
      await page.keyboard.press('Enter');
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(800);
      at('results');
      await H.click(page, page.getByRole('link', { name: 'Review applicants' }).first());
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(500);
      await page.evaluate(() => document.querySelector('video')?.play());
      await mark('player', page.locator('video').first());
      await page.waitForTimeout(3000);
    });
  },

  async phone(b) {
    await scene(b, 'phone', { viewport: { width: 390, height: 844 }, scale: 3, touch: true, before: async p => { await go(p, '/help/videos/post-a-job'); } }, async (page) => {
      await page.waitForTimeout(400);
      await page.evaluate(() => { const v = document.querySelector('video'); v.muted = true; v.play(); });
      await page.waitForTimeout(4200);
      await H.scrollBy(page, 420);
      await page.waitForTimeout(1500);
    });
  },

  async speed(b) {
    await scene(b, 'speed', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await p.locator('#vd-speed-label').scrollIntoViewIfNeeded(); await p.evaluate(() => scrollBy(0, 120)); } }, async (page, { mark }) => {
      await page.waitForTimeout(500);
      await mark('speed', page.locator('#vd-speed-label').locator('xpath=..'));
      for (const label of ['1× real time', '1.5×', '1.25×']) {
        await H.click(page, page.getByRole('button', { name: label }));
        await page.waitForTimeout(700);
      }
      await page.waitForTimeout(900);
    });
  },

  async ideas(b) {
    await scene(b, 'ideas', { ...desktop, before: async p => { await go(p, '/video-demo/studio/ideas'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const btn = page.getByRole('button', { name: /Suggest/ }).last();
      await mark('button', btn);
      await H.click(page, btn);
      await page.waitForLoadState('networkidle');
      await page.getByText('Post your first job').waitFor();
      await page.waitForTimeout(400);
      at('ideas');
      await mark('grid', page.locator('.vd-grid'));
      await H.glide(page, page.getByRole('link', { name: 'Make this video' }).first());
      await page.waitForTimeout(1500);
    });
  },

  // Record as a type of user, on sample data.
  async sampledata(b) {
    await scene(b, 'sampledata', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await p.locator('#record_as').scrollIntoViewIfNeeded(); await p.evaluate(() => scrollBy(0, 160)); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const who = page.locator('#record_as');
      await mark('recordas', who.locator('xpath=..'));
      await H.glide(page, who);
      await who.selectOption({ label: 'Recruiter' });
      await page.waitForTimeout(700);
      const box = page.locator('input[type=checkbox][name=sample_data]');
      await mark('sample', box.locator('xpath=ancestor::div[1]'));
      at('sample');
      await H.click(page, box);
      await page.waitForTimeout(500);
      await H.click(page, box);
      await page.waitForTimeout(1600);
    });
  },

  // Make it again: one form; another style, and the new-plan toggle switching on.
  async again(b) {
    await scene(b, 'again', { ...desktop, before: async p => { await go(p, '/video-demo/studio/1'); await p.locator('#r-style').scrollIntoViewIfNeeded(); await p.evaluate(() => scrollBy(0, 300)); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const style = page.locator('#r-style');
      await mark('style', style.locator('xpath=..'));
      await H.glide(page, style);
      await style.selectOption({ label: 'Spotlight' });
      at('spotlight');
      await page.waitForTimeout(1200);
      const toggle = page.locator('#a-new-plan');
      await mark('toggle', toggle.locator('xpath=ancestor::div[1]'));
      await H.glide(page, toggle);
      await page.waitForTimeout(500);
      await H.click(page, toggle);
      at('free');
      await page.waitForTimeout(900);
      const btn = page.locator('#a-submit');
      await mark('button', btn);
      await H.glide(page, btn);
      await page.waitForTimeout(1300);
    });
  },

  // Delete: tick two videos, and see where each is used first.
  async remove(b) {
    await scene(b, 'remove', { ...desktop, before: async p => { await go(p, '/video-demo/studio'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      for (const name of ['Post a job', 'Change notification emails']) {
        await H.click(page, page.locator('tr', { hasText: name }).locator('input[type=checkbox]'));
        await page.waitForTimeout(250);
      }
      await H.click(page, page.locator('#vd-bulk-delete'));
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(500);
      at('confirm');
      await mark('warning', page.locator('.vd-flash-warn'));
      await mark('card', page.locator('.vd-card').filter({ hasText: 'Shown on' }).first());
      await H.glide(page, page.locator('.vd-card').filter({ hasText: 'Shown on' }).locator('.vd-mono').first());
      await page.waitForTimeout(1600);
    });
  },
};

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const want = process.argv.slice(2);
  for (const [name, run] of Object.entries(scenes)) {
    if (want.length && !want.includes(name)) continue;
    await run(browser);
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
