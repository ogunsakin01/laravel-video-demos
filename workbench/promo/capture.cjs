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
    const sy = await page.evaluate(() => scrollY);
    markers.push({ id, t: (Date.now() - t0) / 1000, x: box.x, y: box.y, w: box.width, h: box.height, sy });
  };
  const at = id => markers.push({ id, t: (Date.now() - t0) / 1000 });

  // How far the page is scrolled, all through the clip, so a highlight can
  // follow what it marks when the page moves after the marker was taken.
  const scroll = [];
  let filming = true;
  const watch = (async () => {
    while (filming) {
      const y = await page.evaluate(() => scrollY).catch(() => null);
      if (y !== null && scroll.at(-1)?.[1] !== y) scroll.push([(Date.now() - t0) / 1000, y]);
      await new Promise(r => setTimeout(r, 40));
    }
  })();

  await run(page, { mark, at });
  await page.waitForTimeout(600);
  filming = false;
  await watch;
  await cdp.send('Page.stopScreencast');
  const total = (Date.now() - t0) / 1000;
  await ctx.close();

  // Each frame holds until the next one arrived, so timing matches real time.
  const list = frames.map((f, i) => `file '${f.file}'\nduration ${((frames[i + 1]?.t ?? total) - f.t).toFixed(4)}`).join('\n') + `\nfile '${frames.at(-1).file}'\n`;
  fs.writeFileSync(path.join(dir, 'frames.txt'), list);
  execFileSync(FFMPEG, ['-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', path.join(dir, 'frames.txt'),
    '-vf', `scale=${w}:${h}:flags=lanczos,format=yuv420p`, '-r', '30', '-fps_mode', 'cfr', '-c:v', 'libx264', '-crf', '14', '-preset', 'medium', path.join(OUT, `${name}.mp4`)]);
  fs.writeFileSync(path.join(OUT, `${name}.json`), JSON.stringify({ width: opts.viewport.width, height: opts.viewport.height, seconds: total, markers, scroll }, null, 2));
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(`${name}: ${total.toFixed(1)}s, ${frames.length} frames, ${markers.length} markers`);
}

const desktop = { viewport: { width: 1280, height: 800 }, scale: 2 };
const go = async (page, p) => { await page.goto(BASE + p, { waitUntil: 'networkidle' }); await page.waitForTimeout(500); };
// Open folded cards and sections (<details>) by selector, and scroll so the
// element with the id is $top pixels from the top.
const open = (page, selector) => page.evaluate(s => document.querySelectorAll(s).forEach(d => { d.open = true; }), selector);
const scrollTo = (page, id, top) => page.evaluate(([id, top]) => { const r = document.getElementById(id).getBoundingClientRect(); scrollBy(0, r.top - top); }, [id, top]);

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
    await scene(b, 'screens', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await scrollTo(p, 'vd-devices-label', 300); } }, async (page, { mark }) => {
      const grid = page.locator('.vd-field', { has: page.locator('#vd-devices-label') });
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
    await scene(b, 'cost', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await open(p, '.vd-more'); } }, async (page, { mark, at }) => {
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
    await scene(b, 'pages', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await scrollTo(p, 'start_path', 260); } }, async (page, { mark }) => {
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
      // The bar sticks to the top while the list is scrolled; gliding to its
      // button scrolls back up, so it is measured once it has settled.
      await H.glide(page, page.locator('#vd-bulk button[value=publish]'));
      await page.waitForTimeout(500);
      await mark('bar', page.locator('#vd-bulk'));
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
      await page.waitForTimeout(300);
      at('enter');
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
    await scene(b, 'speed', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await open(p, '.vd-more'); await scrollTo(p, 'vd-speed-label', 300); } }, async (page, { mark }) => {
      await page.waitForTimeout(500);
      await mark('speed', page.locator('#vd-speed-label').locator('xpath=../..'));
      for (const speed of ['1', '1.5', '1.25']) {
        await H.click(page, page.locator(`[data-speed="${speed}"]`));
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
    await scene(b, 'sampledata', { ...desktop, before: async p => { await go(p, '/video-demo/studio/create'); await scrollTo(p, 'record_as', 300); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const who = page.locator('#record_as');
      await H.glide(page, who);
      await mark('recordas', who.locator('xpath=..'));
      await who.selectOption({ label: 'Recruiter' });
      await page.waitForTimeout(700);
      const box = page.locator('label', { has: page.locator('input[type=checkbox][name=sample_data]') });
      await H.glide(page, box);
      await mark('sample', box);
      at('sample');
      await H.click(page, box);
      await page.waitForTimeout(500);
      await H.click(page, box);
      await page.waitForTimeout(1600);
    });
  },

  // Make it again: one form; another style, and the new-plan toggle switching on.
  async again(b) {
    await scene(b, 'again', { ...desktop, before: async p => { await go(p, '/video-demo/studio/1'); await open(p, '#vd-again-fold'); await scrollTo(p, 'r-style', 260); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const style = page.locator('#r-style');
      await H.glide(page, style);
      await mark('style', style.locator('xpath=..'));
      await style.selectOption({ label: 'Spotlight' });
      at('spotlight');
      await page.waitForTimeout(1200);
      const toggle = page.locator('label', { has: page.locator('#a-new-plan') });
      await H.glide(page, toggle);
      await mark('toggle', toggle);
      await page.waitForTimeout(500);
      await H.click(page, toggle);
      at('free');
      await page.waitForTimeout(900);
      const btn = page.locator('#a-submit');
      await H.glide(page, btn);
      await mark('button', btn);
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
      const inUse = page.getByText('Published in the public library');
      await mark('card', inUse.locator('xpath=ancestor::div[contains(@class,"vd-card")][1]'));
      await H.glide(page, inUse);
      await page.waitForTimeout(1600);
    });
  },
  // Find what a change broke: the Broken mark, then the failed step and the page.
  async verify(b) {
    await scene(b, 'verify', { ...desktop, before: async p => { await go(p, '/video-demo/studio'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const row = page.locator('tr', { hasText: 'Review applicants' });
      await H.glide(page, row.locator('.vd-pill', { hasText: 'Broken' }));
      await mark('row', row);
      await page.waitForTimeout(900);
      await H.click(page, row.getByRole('link', { name: 'Review applicants' }));
      await page.waitForLoadState('networkidle');
      await scrollTo(page, 'vd-check', 24);
      await page.waitForTimeout(400);
      const error = page.locator('#vd-check .vd-fold-body > div').first();
      await H.glide(page, error);
      at('check');
      await mark('check', page.locator('#vd-check'));
      await mark('error', error);
      await page.waitForTimeout(1800);
      const fix = page.getByRole('button', { name: 'Fix with a new plan' });
      await H.glide(page, fix);
      await mark('fix', fix.locator('xpath=ancestor::div[1]'));
      await page.waitForTimeout(1200);
    });
  },

  // Fix it by hand: the button was renamed, so change the name the step looks for.
  async planedit(b) {
    await scene(b, 'planedit', { ...desktop, before: async p => {
      await go(p, '/video-demo/studio/3');
      await open(p, '#vd-plan');
      await p.evaluate(() => { const r = document.getElementById('plan-9-target-name').closest('li').getBoundingClientRect(); scrollBy(0, r.top - 120); });
    } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const step = page.locator('#plan-9-target-name').locator('xpath=ancestor::li[1]');
      await mark('step', step);
      const more = step.locator('details > summary').first();
      await H.click(page, more);
      await page.waitForTimeout(500);
      const name = page.locator('#plan-9-target-name');
      await H.glide(page, name);
      await mark('name', name.locator('xpath=..'));
      at('edit');
      await H.click(page, name);
      await name.fill('');
      await name.pressSequentially('Publish job', { delay: 90 });
      await page.waitForTimeout(600);
      const save = page.getByRole('button', { name: /Save and record it again/ });
      await save.scrollIntoViewIfNeeded();
      await page.evaluate(() => scrollBy(0, 160));
      await page.waitForTimeout(300);
      at('save');
      await mark('save', save.locator('xpath=..'));
      await H.glide(page, save);
      await page.waitForTimeout(1500);
    });
  },

  // Help on each page: the app's own page lists the videos that show it.
  async help(b) {
    await scene(b, 'help', { ...desktop, before: async p => { await go(p, '/jobs/create'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const btn = page.locator('.vd-help-button');
      await mark('button', btn);
      await H.click(page, btn);
      await page.waitForTimeout(300);
      at('open');
      await mark('panel', page.locator('.vd-help'));
      await page.evaluate(() => { const v = document.querySelector('.vd-help video'); v.muted = true; v.play(); });
      await page.waitForTimeout(3600);
    });
  },

  // Other languages: the French version, and another one being added.
  async languages(b) {
    await scene(b, 'languages', { ...desktop, before: async p => {
      await go(p, '/video-demo/studio/1');
      await open(p, '#vd-languages');
      await scrollTo(p, 'vd-languages', 80);
    } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      await mark('card', page.locator('#vd-languages'));
      await H.glide(page, page.locator('#vd-languages .vd-chapters a').first());
      await page.waitForTimeout(1000);
      const lang = page.locator('#t-language');
      await H.glide(page, lang);
      await lang.selectOption({ label: 'Spanish' });
      at('spanish');
      await page.waitForTimeout(800);
      await H.glide(page, page.getByRole('button', { name: 'Make it in this language' }));
      await page.waitForTimeout(1200);
    });
  },

  // The French version in the library, as a viewer in France gets it.
  async french(b) {
    await scene(b, 'french', { ...desktop, before: async p => { await go(p, '/help/videos/post-a-job'); } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const fr = page.getByRole('link', { name: 'Français' });
      await mark('picker', fr.locator('xpath=ancestor::div[contains(@class,"vd-card")][1]'));
      await H.click(page, fr);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(300);
      at('french');
      await mark('title', page.locator('h1').first());
      await page.waitForTimeout(2200);
    });
  },

  // Copies for other places: a GIF, square and vertical.
  async downloads(b) {
    await scene(b, 'downloads', { ...desktop, before: async p => {
      await go(p, '/video-demo/studio/1');
      await open(p, '#vd-downloads');
      await scrollTo(p, 'vd-downloads', 60);
    } }, async (page, { mark }) => {
      await page.waitForTimeout(500);
      await mark('card', page.locator('#vd-downloads'));
      await H.glide(page, page.locator('#vd-downloads .vd-btn-primary').first());
      await page.waitForTimeout(1100);
      for (const label of ['Square', 'Vertical (9:16)']) {
        await H.glide(page, page.locator('#vd-downloads li', { hasText: label }).getByRole('button'));
        await page.waitForTimeout(800);
      }
      await page.waitForTimeout(600);
    });
  },

  // Your brand: a logo on every frame and a card at the end.
  async brand(b) {
    const logo = path.join(OUT, 'acme-logo.svg');
    fs.mkdirSync(OUT, { recursive: true });
    fs.writeFileSync(logo, '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="64" viewBox="0 0 240 64"><rect width="64" height="64" rx="14" fill="#e5693d"/><text x="32" y="45" font-family="Arial" font-weight="700" font-size="36" text-anchor="middle" fill="#0b0b0a">A</text><text x="80" y="44" font-family="Arial" font-weight="700" font-size="32" fill="#f5f4ef">Acme HR</text></svg>');
    await scene(b, 'brand', { ...desktop, before: async p => {
      await go(p, '/video-demo/studio/styles');
      await scrollTo(p, 'logo', 260);
    } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      const file = page.locator('#logo');
      await H.glide(page, file);
      await mark('fields', file.locator('xpath=ancestor::div[contains(@class,"vd-row")][1]'));
      await file.setInputFiles(logo);
      await page.waitForTimeout(500);
      const pos = page.locator('#logo_position');
      await H.glide(page, pos);
      await pos.selectOption({ label: 'Top right' });
      await page.waitForTimeout(600);
      const end = page.locator('#end_title');
      await H.glide(page, end);
      const row = end.locator('xpath=ancestor::div[contains(@class,"vd-row")][1]');
      await mark('end', row);
      await mark('end-hint', row.locator('xpath=following-sibling::span[1]'));
      at('end');
      await H.click(page, end);
      await end.pressSequentially('Try it at acme.test', { delay: 70 });
      const text = page.locator('#end_text');
      await H.click(page, text);
      await text.pressSequentially('Free for 14 days.', { delay: 70 });
      await page.waitForTimeout(1200);
    });
  },

  // How each video is watched, and what users looked for and didn't find.
  async watching(b) {
    await scene(b, 'watching', { ...desktop, before: async p => {
      await go(p, '/video-demo/studio/1');
      await open(p, '#vd-watching');
      await p.locator('#vd-watching [data-bars]:not([hidden])').waitFor();
      await scrollTo(p, 'vd-watching', 120);
    } }, async (page, { mark, at }) => {
      await page.waitForTimeout(500);
      await mark('card', page.locator('#vd-watching'));
      await H.glide(page, page.locator('#vd-watching [data-drop]'));
      await page.waitForTimeout(2000);
      await go(page, '/video-demo/studio');
      at('searches');
      await mark('searches', page.getByText('Searched for, nothing found').locator('xpath=..'));
      await H.glide(page, page.getByText('export invoices').first());
      await page.waitForTimeout(1800);
    });
  },
};

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const want = process.argv.slice(2);
  const failed = [];
  for (const [name, run] of Object.entries(scenes)) {
    if (want.length && !want.includes(name)) continue;
    try {
      await run(browser);
    } catch (e) {
      console.error(`${name}: ${e.message.split("\n").slice(0, 4).join(" ")}`);
      failed.push(name);
    }
  }
  await browser.close();
  if (failed.length) {
    console.error(`Failed: ${failed.join(' ')}`);
    process.exit(1);
  }
})().catch(e => { console.error(e); process.exit(1); });
