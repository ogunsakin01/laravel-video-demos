// Finds where a finished video could go in the app, and shows it there.
//   node spots.cjs <job.json>
// Signs in like the recorder (on the video's sample data when it has some).
//
// mode "map": opens each of job.paths and lists the sections a video could sit
// in: landmarks, cards and panels with a heading, empty states. Writes
// result.json: { pages: [{ path, title, sections: [{ id, selector, kind, heading, text, box }] }], failed: [{ path, reason }] }.
//
// mode "shoot": for each of job.spots ({ path, selector, positions }), puts the
// video's player (job.html, the real collapsible player) into the section and
// checks it doesn't upset the page, minimised and open, on each of
// job.screens: no sideways scrolling, nothing beside it squeezed or pushed
// sideways, the player not clipped or covered. Positions are tried in order
// until one passes everywhere; if none does, the one with fewest problems is kept. Saves a
// screenshot of each state with the player outlined, as
// <spot>-<screen>-<closed|open>.jpg in job.outDir. Writes result.json:
// { spots: [{ found, position, ok, problems: [{ screen, state, problem }], shots: { screen: { closed, open } } }] }.
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
      tag,
      classes: (typeof el.className === 'string' ? el.className : '').trim().split(/\s+/).filter(Boolean).slice(0, 4),
      heading,
      text: words.slice(0, 140),
      box: { x: Math.round(r.x), y: Math.round(r.y + scrollY), w: Math.round(r.width), h: Math.round(r.height) },
    });
  }
  return { title: (document.querySelector('h1') ? text(document.querySelector('h1')) : document.title).slice(0, 120), sections: found };
}

// Runs in the page: note where everything that could move is, before the player goes in.
function measure([selector, position]) {
  const target = document.querySelector(selector);
  if (!target) return null;
  const parent = position === 'top' ? target : target.parentElement;
  const box = el => { const r = el.getBoundingClientRect(); return { x: Math.round(r.x), w: Math.round(r.width) }; };
  window.__vdBefore = {
    wide: document.documentElement.scrollWidth > innerWidth + 1,
    siblings: [...parent.children].map(el => ({ el, box: box(el) })),
    target: box(target),
  };
  return true;
}

// Runs in the page: put the player in its place.
function place([selector, position, html]) {
  const target = document.querySelector(selector);
  const holder = document.createElement('div');
  holder.innerHTML = html;
  const player = holder.querySelector('details') || holder.firstElementChild;
  // Its styles and script, once.
  holder.querySelectorAll('style').forEach(st => document.head.appendChild(st));
  if (position === 'before') target.before(player);
  else if (position === 'after') target.after(player);
  else target.prepend(player);
  player.setAttribute('data-video-demo-spot', '');
  return true;
}

// Runs in the page: what the player did to the page, in plain words.
function problems() {
  const player = document.querySelector('[data-video-demo-spot]');
  const before = window.__vdBefore;
  const found = [];
  const name = el => {
    const h = el.querySelector && el.querySelector('h1, h2, h3, h4, legend');
    const words = (h ? h.innerText : el.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    return words ? `“${words}”` : `the ${el.tagName.toLowerCase()} beside it`;
  };
  if (!before.wide && document.documentElement.scrollWidth > innerWidth + 1) found.push('The page scrolls sideways with it in.');
  for (const { el, box } of before.siblings) {
    if (!el.isConnected || el === player) continue;
    const r = el.getBoundingClientRect();
    if (Math.abs(r.width - box.w) > Math.max(4, box.w * 0.04)) found.push(`It changes the width of ${name(el)}.`);
    else if (Math.abs(r.x - box.x) > 4) found.push(`It pushes ${name(el)} sideways.`);
  }
  const r = player.getBoundingClientRect();
  if (r.width < Math.min(240, innerWidth - 40)) found.push(`It would be only ${Math.round(r.width)}px wide.`);
  for (let a = player.parentElement; a && a !== document.body; a = a.parentElement) {
    const st = getComputedStyle(a);
    if (st.overflowX !== 'visible' || st.overflowY !== 'visible') {
      const ar = a.getBoundingClientRect();
      if (r.right > ar.right + 1 || r.left < ar.left - 1 || (st.overflowY !== 'visible' && st.overflowY !== 'auto' && st.overflowY !== 'scroll' && r.bottom > ar.bottom + 1)) {
        found.push('Part of it is cut off by the section around it.');
        break;
      }
    }
  }
  player.scrollIntoView({ block: 'center' });
  const pr = player.getBoundingClientRect();
  const hit = document.elementFromPoint(pr.left + Math.min(pr.width / 2, 60), pr.top + Math.min(pr.height / 2, 20));
  if (hit && !player.contains(hit)) found.push('Something on the page covers it.');
  return [...new Set(found)];
}

// Runs in the page: outline the player and label it for the screenshot. Both
// sit outside the page's layout, so they move nothing.
function outline(accent) {
  const player = document.querySelector('[data-video-demo-spot]');
  player.scrollIntoView({ block: 'center' });
  player.style.outline = '3px solid ' + accent;
  player.style.outlineOffset = '5px';
  document.querySelector('[data-video-demo-label]')?.remove();
  const r = player.getBoundingClientRect();
  const label = document.createElement('div');
  label.setAttribute('data-video-demo-label', '');
  label.textContent = 'Video goes here';
  label.style.cssText = 'position:absolute;z-index:2147483647;left:' + Math.max(4, r.left + scrollX + 8) + 'px;top:' + Math.max(4, r.top + scrollY - 22) + 'px;'
    + 'background:' + accent + ';color:#fff;font:600 12px/1 ui-sans-serif,system-ui,sans-serif;padding:5px 8px;border-radius:6px;pointer-events:none';
  document.body.appendChild(label);
}

(async () => {
  const out = { pages: [], failed: [], spots: [] };
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
      const contexts = {};
      for (const screen of job.screens) {
        contexts[screen.key] = await browser.newContext({ viewport: screen.viewport, hasTouch: !!screen.touch, isMobile: !!screen.touch, ignoreHTTPSErrors: !!job.ignoreHttpsErrors });
        if (job.cookies?.length) await contexts[screen.key].addCookies(job.cookies);
        await signIn(contexts[screen.key]);
      }

      for (const [i, spot] of job.spots.entries()) {
        let chosen = null;
        for (const position of spot.positions) {
          const tried = { found: true, position, ok: true, problems: [], shots: {} };
          for (const screen of job.screens) {
            const { page } = await open(contexts[screen.key], spot.path);
            try {
              if (!(await page.evaluate(measure, [spot.selector, position]))) { tried.found = false; tried.ok = false; break; }
              await page.evaluate(place, [spot.selector, position, job.html]);
              await page.waitForTimeout(250);
              const shots = {};
              for (const state of ['closed', 'open']) {
                if (state === 'open') {
                  await page.evaluate(() => { const d = document.querySelector('[data-video-demo-spot]'); if (d) d.open = true; });
                  await page.waitForTimeout(250);
                }
                for (const problem of await page.evaluate(problems)) tried.problems.push({ screen: screen.key, state, problem });
                await page.evaluate(outline, job.accent || '#e5693d');
                await page.waitForTimeout(150);
                shots[state] = path.join(job.outDir, `${i + 1}-${screen.key}-${state}-${position}.jpg`);
                await page.screenshot({ path: shots[state], type: 'jpeg', quality: 80 });
                await page.evaluate(() => { const p = document.querySelector('[data-video-demo-spot]'); if (p) p.style.outline = ''; document.querySelector('[data-video-demo-label]')?.remove(); });
              }
              tried.shots[screen.key] = shots;
            } finally {
              await page.close();
            }
          }
          tried.ok = tried.found && tried.problems.length === 0;
          // Keep the first position that fits; failing that, the one with the fewest problems.
          if (tried.found && (!chosen || tried.problems.length < chosen.problems.length)) {
            if (chosen) for (const s of Object.values(chosen.shots)) for (const f of Object.values(s)) fs.rmSync(f, { force: true });
            chosen = tried;
          } else {
            for (const s of Object.values(tried.shots)) for (const f of Object.values(s)) fs.rmSync(f, { force: true });
          }
          if (chosen?.ok) break;
        }
        // Saved under a name without the position, now that it is settled.
        if (chosen) {
          for (const [screen, s] of Object.entries(chosen.shots)) for (const [state, f] of Object.entries(s)) {
            const final = path.join(job.outDir, `${i + 1}-${screen}-${state}.jpg`);
            fs.renameSync(f, final);
            chosen.shots[screen][state] = final;
          }
        }
        out.spots.push(chosen || { found: false, position: null, ok: false, problems: [], shots: {} });
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
