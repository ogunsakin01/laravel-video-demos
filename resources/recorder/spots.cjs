// Finds where a finished video could go in the app, and shows it there.
//   node spots.cjs <job.json>
// Signs in like the recorder (on the video's sample data when it has some).
//
// mode "map": opens each of job.paths and lists the sections a video could sit
// in: landmarks, cards and panels with a heading, empty states. Writes
// result.json: { pages: [{ path, title, sections: [{ id, selector, kind, heading, text, box }] }], failed: [{ path, reason }] }.
//
// mode "shoot": for each of job.spots ({ path, selector, position }), opens the
// page on each of job.screens, puts a player card for the video there (its
// poster, title and length) with an outline around it, and saves a screenshot.
// Writes result.json: { shots: [{ spot, screen, file }], missing: [{ spot, screen, reason }] }.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const url = p => job.baseUrl.replace(/\/$/, '') + '/' + String(p).replace(/^\//, '');

async function open(context, p) {
  const page = await context.newPage();
  const res = await page.goto(url(p), { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForLoadState('load', { timeout: 10000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
  return { page, status: res ? res.status() : null };
}

async function signIn(context) {
  if (!job.loginPath) return;
  const page = await context.newPage();
  await page.goto(url(job.loginPath), { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.close();
}

// Runs in the page: the sections worth putting a video in, each with a selector
// that finds it again.
function sections() {
  const selectorOf = el => {
    if (el.id && /^[A-Za-z][\w-]*$/.test(el.id) && document.querySelectorAll('#' + el.id).length === 1) return '#' + el.id;
    const parts = [];
    for (let node = el; node && node !== document.body; node = node.parentElement) {
      if (node.id && /^[A-Za-z][\w-]*$/.test(node.id) && document.querySelectorAll('#' + node.id).length === 1) { parts.unshift('#' + node.id); break; }
      const same = [...node.parentElement.children].filter(c => c.tagName === node.tagName);
      parts.unshift(node.tagName.toLowerCase() + (same.length > 1 ? ':nth-of-type(' + (same.indexOf(node) + 1) + ')' : ''));
    }
    return (parts[0]?.startsWith('#') ? '' : 'body > ') + parts.join(' > ');
  };
  const visible = el => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width >= 200 && r.height >= 48 && s.visibility !== 'hidden' && s.display !== 'none';
  };
  const text = el => (el.innerText || '').replace(/\s+/g, ' ').trim();
  const headingOf = el => {
    const h = el.querySelector('h1, h2, h3, h4, legend, [role=heading]');
    return h ? text(h).slice(0, 80) : (el.getAttribute('aria-label') || '').slice(0, 80);
  };

  const found = [];
  const seen = new Set();
  const candidates = document.querySelectorAll('main, [role=main], section, article, aside, form, nav, header, footer, fieldset, div');
  for (const el of candidates) {
    if (found.length >= 40 || !visible(el)) continue;
    const tag = el.tagName.toLowerCase();
    const heading = headingOf(el);
    const words = text(el);
    const empty = /\b(no \w+ yet|nothing (here|yet)|get started|you haven.t|is empty|no results)\b/i.test(words) && words.length < 400;
    const landmark = ['main', 'section', 'article', 'aside', 'form', 'nav', 'header', 'footer', 'fieldset'].includes(tag) || el.getAttribute('role') === 'main';
    // A plain div counts when it looks like a card of its own (a border, a
    // background or a shadow) with a heading at its top, or is an empty state.
    let panel = false;
    if (tag === 'div') {
      const st = getComputedStyle(el);
      const boxed = parseFloat(st.borderTopWidth) > 0 || st.boxShadow !== 'none' || !/rgba\(0, 0, 0, 0\)|transparent/.test(st.backgroundColor);
      const first = el.firstElementChild;
      const h = el.querySelector('h1, h2, h3, h4');
      panel = empty || (boxed && !!h && !!first && (first === h || first.contains(h)));
    }
    if (!landmark && !panel) continue;
    // One entry per heading: the outermost element that carries it.
    if (heading && seen.has(heading) && !empty) continue;
    if (heading) seen.add(heading);
    const r = el.getBoundingClientRect();
    found.push({
      id: found.length + 1,
      selector: selectorOf(el),
      kind: empty ? 'empty state' : (el.getAttribute('role') === 'main' ? 'main' : tag === 'div' ? 'panel' : tag),
      heading,
      text: words.slice(0, 140),
      box: { x: Math.round(r.x), y: Math.round(r.y + scrollY), w: Math.round(r.width), h: Math.round(r.height) },
    });
  }
  return { title: (document.querySelector('h1') ? text(document.querySelector('h1')) : document.title).slice(0, 120), sections: found };
}

// Runs in the page: put the player card in its place, outlined, and bring it into view.
function place([selector, position, card]) {
  const target = document.querySelector(selector);
  if (!target) return false;
  const wrap = document.createElement('div');
  wrap.setAttribute('data-video-demo-spot', '');
  wrap.style.cssText = 'all:initial;display:block;box-sizing:border-box;width:100%;max-width:' + (position === 'top' ? '560px' : '640px') + ';margin:16px 0;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
    + 'outline:3px solid ' + card.accent + ';outline-offset:6px;border-radius:12px;position:relative;margin-top:26px';
  wrap.innerHTML = '<span style="position:absolute;z-index:1;top:-17px;left:14px;background:' + card.accent + ';color:#fff;font:600 12px/1 ui-sans-serif,system-ui,sans-serif;padding:6px 9px;border-radius:6px;letter-spacing:.01em">Video goes here</span>'
    + '<div style="display:flex;gap:14px;align-items:center;background:#ffffff;color:#171717;border:1px solid #e5e2dc;border-radius:12px;padding:12px;box-shadow:0 8px 24px -12px rgba(0,0,0,.25)">'
    + '<div style="position:relative;flex:none;width:44%;max-width:240px;aspect-ratio:16/9;border-radius:8px;overflow:hidden;background:#141412">'
    + (card.poster ? '<img src="' + card.poster + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block">' : '')
    + '<span style="position:absolute;inset:0;margin:auto;width:40px;height:40px;border-radius:50%;background:rgba(17,17,17,.82);display:flex;align-items:center;justify-content:center">'
    + '<span style="width:0;height:0;border-left:13px solid #fff;border-top:8px solid transparent;border-bottom:8px solid transparent;margin-left:3px"></span></span></div>'
    + '<div style="min-width:0"><div style="font-size:12px;color:#6b6f7b;margin-bottom:4px">How-to video · ' + card.length + '</div>'
    + '<div style="font-size:15px;font-weight:600;line-height:1.35;color:#171717">' + card.title + '</div></div></div>';
  if (position === 'before') target.before(wrap);
  else if (position === 'after') target.after(wrap);
  else target.prepend(wrap);
  wrap.scrollIntoView({ block: 'center' });
  return true;
}

(async () => {
  const out = { pages: [], failed: [], shots: [], missing: [] };
  const browser = await chromium.launch(job.channel ? { channel: job.channel } : {});
  try {
    if (job.mode === 'map') {
      const context = await browser.newContext({ viewport: job.viewport, ignoreHTTPSErrors: !!job.ignoreHttpsErrors });
      if (job.cookies?.length) await context.addCookies(job.cookies);
      await signIn(context);
      for (const p of job.paths) {
        try {
          const { page, status } = await open(context, p);
          if (status && status >= 400) out.failed.push({ path: p, reason: 'HTTP ' + status });
          else out.pages.push({ path: p, ...(await page.evaluate(sections)) });
          await page.close();
        } catch (e) {
          out.failed.push({ path: p, reason: String(e.message || e).split('\n')[0].slice(0, 200) });
        }
      }
    } else {
      // The poster as a data address, so the page shows it whatever its own security rules.
      const poster = job.poster && fs.existsSync(job.poster) ? 'data:image/jpeg;base64,' + fs.readFileSync(job.poster).toString('base64') : null;
      const esc = s => String(s).replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch]);
      const card = { poster, title: esc(job.title), length: esc(job.length), accent: job.accent || '#e5693d' };
      for (const screen of job.screens) {
        const context = await browser.newContext({ viewport: screen.viewport, hasTouch: !!screen.touch, isMobile: !!screen.touch, ignoreHTTPSErrors: !!job.ignoreHttpsErrors });
        if (job.cookies?.length) await context.addCookies(job.cookies);
        await signIn(context);
        for (const [i, spot] of job.spots.entries()) {
          try {
            const { page } = await open(context, spot.path);
            if (await page.evaluate(place, [spot.selector, spot.position, card])) {
              await page.waitForTimeout(400);
              const file = path.join(job.outDir, (i + 1) + '-' + screen.key + '.jpg');
              await page.screenshot({ path: file, type: 'jpeg', quality: 80 });
              out.shots.push({ spot: i, screen: screen.key, file });
            } else {
              out.missing.push({ spot: i, screen: screen.key, reason: 'The section was not on the page' });
            }
            await page.close();
          } catch (e) {
            out.missing.push({ spot: i, screen: screen.key, reason: String(e.message || e).split('\n')[0].slice(0, 200) });
          }
        }
        await context.close();
      }
    }
  } catch (e) {
    out.error = String(e.message || e).split('\n')[0];
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(job.resultDir || job.outDir, 'result.json'), JSON.stringify(out, null, 2));
  }
  process.exit(0);
})();
