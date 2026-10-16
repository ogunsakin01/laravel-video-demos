// Helpers for record.cjs: a visible cursor that glides to each target before
// acting, eased scrolling, and the caption and title card overlays that run
// inside the recorded page.
const CURSOR_JS = `
(() => {
  if (window.__demoCursor) return;
  const c = document.createElement('div');
  c.style.cssText = 'position:fixed;z-index:2147483647;left:0;top:0;width:22px;height:22px;margin:-4px 0 0 -4px;pointer-events:none;transform:translate(640px,420px)';
  c.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24"><path d="M4 2l16 9-7 2-3 7z" fill="#111" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>';
  const ring = document.createElement('div');
  ring.style.cssText = 'position:fixed;z-index:2147483646;width:34px;height:34px;margin:-17px 0 0 -17px;border-radius:50%;background:rgba(17,17,17,.18);pointer-events:none;opacity:0;transform:scale(.4);transition:opacity .35s, transform .35s';
  // Turn off smooth scrolling, so positions are final the moment they are measured.
  const st = document.createElement('style');
  st.textContent = 'html,body{scroll-behavior:auto!important}';
  // Phones and tablets have no pointer: show only the tap ring.
  if (window.__demoTouch) c.style.display = 'none';
  const add = () => { document.head.appendChild(st); document.body.appendChild(c); document.body.appendChild(ring); };
  document.body ? add() : document.addEventListener('DOMContentLoaded', add);
  // Re-attach after the document is replaced in the same window (page.setContent).
  const attach = () => { if (!c.isConnected && document.body) add(); };
  let at = { x: 640, y: 420 };
  window.__demoCursor = {
    // Moves the way a hand does: a slight arc, quick to start and slowing as it
    // arrives, over a time that grows with the distance. Returns that time in ms.
    move(x, y) {
      attach();
      const dx = x - at.x, dy = y - at.y, dist = Math.hypot(dx, dy);
      const ms = dist < 4 ? 0 : Math.round(Math.min(1100, Math.max(420, 300 + dist * 0.55)));
      // The arc bends to one side by up to 8% of the distance, the same way each time for the same move.
      const bend = (((Math.round(at.x + y) % 7) - 3) / 3) * 0.08;
      const mid = { x: at.x + dx / 2 - dy * bend, y: at.y + dy / 2 + dx * bend };
      const t = p => 'translate(' + p.x + 'px,' + p.y + 'px)';
      if (ms) c.animate([{ transform: t(at) }, { transform: t(mid), offset: 0.55 }, { transform: t({ x, y }) }], { duration: ms, easing: 'cubic-bezier(.3,.1,.2,1)' });
      c.style.transform = t({ x, y });
      at = { x, y };
      return ms;
    },
    click(x, y) {
      attach();
      // The pointer dips as it presses, and a ring shows where.
      c.animate([{ transform: c.style.transform }, { transform: c.style.transform + ' scale(.82)' }, { transform: c.style.transform }], { duration: 220, easing: 'ease-out' });
      ring.style.left = x + 'px'; ring.style.top = y + 'px';
      ring.style.opacity = '1'; ring.style.transform = 'scale(1)';
      setTimeout(() => { ring.style.opacity = '0'; ring.style.transform = 'scale(.4)'; }, 350);
    },
  };
})();`;

// Title cards and captions in the video's style (window.__demoStyle), in solid
// colours only. With style.art "abstract", cards get line art that draws itself
// on and drifts, and the words rise into place. With "headline", cards and
// captions are big, left-aligned headlines in two lines (bold, then muted) under
// a numbered label, like a product promo. Written as a function so it reads as
// code; it is turned into a string and runs inside the page.
function overlay() {
  if (window.__demoOverlay) return;
  const style = Object.assign({ theme: 'light', accent: '#171717', captions: 'bottom', art: 'none' }, window.__demoStyle || {});
  const dark = style.theme === 'dark';
  const plainAccent = style.accent.toLowerCase() === '#171717';
  const c = dark
    ? { bg: '#141412', ink: '#fafaf8', muted: 'rgba(250,250,248,.72)', line: 'rgba(255,255,255,.12)', bar: plainAccent ? '#fafaf8' : style.accent, art: 'rgba(250,250,248,.2)', dot: plainAccent ? '#fafaf8' : style.accent }
    : { bg: '#faf8f3', ink: '#171717', muted: '#5f636e', line: '#e5e2dc', bar: style.accent, art: '#c9c4b8', dot: plainAccent ? '#171717' : style.accent };
  const abstract = style.art === 'abstract';
  const headline = style.art === 'headline';
  const rises = abstract || headline;
  // Animations run this much slower while recording, to look right once the
  // video is played back faster.
  const S = style.speed || 1;
  const ms = n => Math.round(n * S) + 'ms';
  const font = "'Geist', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
  const mono = "'Geist Mono', ui-monospace, SFMono-Regular, Menlo, monospace";
  const esc = s => String(s).replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch]);

  const root = document.createElement('div');
  // Same layer as the cursor but added later, so cards cover it.
  root.style.cssText = 'position:fixed;inset:0;z-index:2147483647;pointer-events:none;font-family:' + font + ';-webkit-font-smoothing:antialiased';
  const css = document.createElement('style');
  css.textContent = [
    '@keyframes vdDraw { to { stroke-dashoffset: 0 } }',
    '@keyframes vdPop { from { opacity: 0; transform: scale(.4) } to { opacity: 1; transform: none } }',
    '@keyframes vdDrift { from { transform: translate(-14px, -8px) } to { transform: translate(14px, 8px) } }',
    '@keyframes vdRise { from { opacity: 0; transform: translateY(.45em) } to { opacity: 1; transform: none } }',
    '@keyframes vdFade { from { opacity: 0 } to { opacity: 1 } }',
    '@keyframes vdGrow { from { transform: scaleX(0) } to { transform: none } }',
    '@keyframes vdGrowY { from { transform: scaleY(0) } to { transform: none } }',
    '.vd-art { position: absolute; inset: 0; width: 100%; height: 100%; }',
    '.vd-art g.vd-drift { animation: vdDrift ' + ms(9000) + ' ease-in-out infinite alternate; }',
    '.vd-art .vd-l { fill: none; stroke: ' + c.art + '; stroke-width: 1.6; vector-effect: non-scaling-stroke; stroke-linecap: round; stroke-dasharray: 3000; stroke-dashoffset: 3000; animation: vdDraw ' + ms(2400) + ' ease-out forwards; animation-delay: var(--d); }',
    '.vd-art .vd-l.vd-a { stroke: ' + c.dot + '; stroke-width: 2; opacity: .85; }',
    '.vd-art .vd-n { fill: ' + c.dot + '; opacity: 0; transform-box: fill-box; transform-origin: center; animation: vdPop ' + ms(500) + ' ease-out forwards; animation-delay: var(--d); }',
    '.vd-word { display: inline-block; opacity: 0; animation: vdRise ' + ms(600) + ' cubic-bezier(.2,.7,.2,1) forwards; animation-delay: var(--d); }',
  ].join('\n');
  root.appendChild(css);

  // The style's logo in a corner of every frame, over cards and captions too.
  let logo = null;
  if (style.logo_src && style.logo_position && style.logo_position !== 'none') {
    const [v, h] = style.logo_position.split('-');
    logo = document.createElement('img');
    logo.src = style.logo_src;
    logo.alt = '';
    logo.style.cssText = 'position:absolute;' + v + ':22px;' + h + ':24px;height:clamp(22px,2.4vw,40px);width:auto;max-width:22vw;object-fit:contain;opacity:.95';
    root.appendChild(logo);
  }

  const add = () => {
    document.body.appendChild(root);
    // Geist for cards and captions; the system font shows if it can't load.
    if (!document.querySelector('link[data-demo-font]')) {
      const l = document.createElement('link');
      l.rel = 'stylesheet'; l.dataset.demoFont = '';
      l.href = 'https://fonts.bunny.net/css?family=geist:400,500,600,700|geist-mono:500&display=swap';
      document.head.appendChild(l);
    }
  };
  document.body ? add() : document.addEventListener('DOMContentLoaded', add);

  // Draw line art seeded by the title (contours, a network, waves or orbits),
  // kept to the edges so the words in the middle stay clear.
  function art(seedText) {
    let seed = 0;
    for (const ch of String(seedText)) seed = (seed * 31 + ch.charCodeAt(0)) >>> 0;
    const rnd = () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 4294967296);
    const kind = ['contours', 'network', 'waves', 'orbits'][seed % 4];
    const W = 1600, H = 1000;
    let out = '', n = 0;
    // One line is drawn in the accent colour.
    const line = (d, accent) => { out += '<path class="vd-l' + (accent ? ' vd-a' : '') + '" d="' + d + '" style="--d:' + ms(n++ * 70) + '"/>'; };
    const node = (x, y, r) => { out += '<circle class="vd-n" cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="' + r + '" style="--d:' + ms(300 + n++ * 60) + '"/>'; };
    if (kind === 'contours') {
      // Topographic rings around a point near a corner.
      const cx = rnd() < .5 ? W * (.08 + rnd() * .12) : W * (.8 + rnd() * .12), cy = H * (.15 + rnd() * .7);
      for (let ring = 1; ring <= 11; ring++) {
        const r = ring * 46, wob = 6 + ring * 2.2, ph = rnd() * 6.28; let d = '';
        for (let a = 0; a <= 64; a++) {
          const t = a / 64 * Math.PI * 2, rr = r + wob * Math.sin(t * 3 + ph) + wob * .6 * Math.sin(t * 5 + ring);
          d += (a ? ' L ' : 'M ') + (cx + rr * Math.cos(t)).toFixed(1) + ' ' + (cy + rr * .78 * Math.sin(t)).toFixed(1);
        }
        line(d + ' Z', ring === 3);
      }
    } else if (kind === 'network') {
      // People-like nodes linked along the top and the bottom.
      const rows = [H * .14, H * .86];
      rows.forEach(y0 => {
        const pts = [];
        for (let i = 0; i < 9; i++) pts.push([W * (.04 + i * .118 + rnd() * .03), y0 + (rnd() - .5) * 110]);
        for (let i = 1; i < pts.length; i++) line('M ' + pts[i - 1][0].toFixed(1) + ' ' + pts[i - 1][1].toFixed(1) + ' L ' + pts[i][0].toFixed(1) + ' ' + pts[i][1].toFixed(1), y0 === rows[0] && i === 4);
        for (let i = 2; i < pts.length; i += 3) line('M ' + pts[i - 2][0].toFixed(1) + ' ' + pts[i - 2][1].toFixed(1) + ' L ' + pts[i][0].toFixed(1) + ' ' + pts[i][1].toFixed(1));
        pts.forEach((p, i) => node(p[0], p[1], i % 3 ? 3.5 : 6));
      });
    } else if (kind === 'waves') {
      // Bands of waves across the top and bottom.
      [H * .16, H * .84].forEach((y0, band) => {
        for (let i = 0; i < 5; i++) {
          const y = y0 + (i - 2) * 26, amp = 34 - Math.abs(i - 2) * 9, f = 140 + i * 22 + band * 30, ph = rnd() * 6; let d = '';
          for (let x = -40; x <= W + 40; x += 30) d += (x > -40 ? ' L ' : 'M ') + x + ' ' + (y + amp * Math.sin(x / f + ph)).toFixed(1);
          line(d, band === 0 && i === 2);
        }
      });
    } else {
      // Orbits with a planet each, off to one side.
      const cx = rnd() < .5 ? W * .1 : W * .9, cy = H * (.3 + rnd() * .4);
      for (let i = 1; i <= 7; i++) {
        const rx = i * 82, ry = i * 52, d = 'M ' + (cx - rx) + ' ' + cy + ' a ' + rx + ' ' + ry + ' 0 1 0 ' + rx * 2 + ' 0 a ' + rx + ' ' + ry + ' 0 1 0 ' + -rx * 2 + ' 0';
        line(d, i === 3);
        const t = rnd() * Math.PI * 2;
        node(cx + rx * Math.cos(t), cy + ry * Math.sin(t), i % 3 ? 4 : 7);
      }
    }
    return '<svg class="vd-art" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><g class="vd-drift">' + out + '</g></svg>';
  }

  // The words of a title, each rising into place a moment after the last.
  const rising = (text, start) => esc(text).split(' ').map((w, i) => '<span class="vd-word" style="--d:' + ms(start + i * 70) + '">' + w + '</span>').join(' ');

  // The numbered label over a headline ("03 —"): the recorder counts the cards and captions.
  const label = n => '<div style="font-family:' + mono + ';font-size:clamp(13px,1.15vw,22px);font-weight:500;letter-spacing:.14em;color:' + c.dot + ';opacity:0;animation:vdFade ' + ms(400) + ' ease-out ' + ms(100) + ' forwards">' + String(n || 1).padStart(2, '0') + ' \u2014</div>';

  // A headline in two lines: the title in bold, then the sentence muted, each word rising in.
  const headlineText = (title, sub, big, small, start) => {
    const subStart = start + title.split(' ').length * 70 + 120;
    return '<div style="font-size:' + big + ';font-weight:700;letter-spacing:-.035em;line-height:1.05">' + rising(title, start) + '</div>'
      + (sub ? '<div style="margin-top:.18em;font-size:' + small + ';font-weight:700;letter-spacing:-.03em;line-height:1.1;color:' + c.muted + '">' + rising(sub, subStart) + '</div>' : '');
  };

  let current = null;
  const show = el => {
    // Re-attach after the document is replaced in the same window.
    if (!root.isConnected) document.body.appendChild(root);
    if (current) current.remove();
    current = el;
    root.appendChild(el);
    // Kept last, so it stays on top.
    if (logo) root.appendChild(logo);
    requestAnimationFrame(() => requestAnimationFrame(() => { el.style.opacity = '1'; el.style.transform = 'none'; }));
  };

  window.__demoOverlay = {
    caption(title, sub, n) {
      const el = document.createElement('div');
      if (headline) {
        // On a solid panel at the edge, so the app stays in view beside it.
        const edge = style.captions === 'top' ? 'top:28px' : 'bottom:32px';
        el.style.cssText = 'position:absolute;left:32px;' + edge + ';max-width:min(62vw,1200px);display:flex;flex-direction:column;gap:10px;padding:18px 26px 20px;border-radius:12px;'
          + 'background:' + c.bg + ';color:' + c.ink + ';border:1px solid ' + c.line + ';box-shadow:0 16px 40px -12px rgba(0,0,0,.35);opacity:0;transition:opacity ' + ms(300);
        el.innerHTML = label(n) + '<div>' + headlineText(title, sub, 'clamp(20px,2.3vw,44px)', 'clamp(16px,1.7vw,32px)', 120) + '</div>';
        show(el);

        return;
      }
      const edge = style.captions === 'top' ? 'top:28px' : 'bottom:36px';
      const shift = style.captions === 'top' ? '-10px' : '10px';
      el.style.cssText = 'position:absolute;left:50%;' + edge + ';width:max-content;max-width:min(54vw,1000px);min-width:min(320px,88vw);translate:-50% 0;display:flex;gap:14px;align-items:flex-start;padding:16px 20px 16px 18px;border-radius:12px;'
        + 'background:' + c.bg + ';color:' + c.ink + ';border:1px solid ' + c.line + ';box-shadow:0 16px 40px -12px rgba(0,0,0,.28);opacity:0;transform:translateY(' + shift + ');transition:opacity ' + ms(350) + ', transform ' + ms(350) + '';
      const bar = abstract
        ? '<span style="flex:none;width:3px;align-self:stretch;border-radius:2px;background:' + c.bar + ';transform-origin:top;animation:vdGrowY ' + ms(500) + ' ease-out ' + ms(150) + ' both"></span>'
        : '<span style="flex:none;width:3px;align-self:stretch;border-radius:2px;background:' + c.bar + '"></span>';
      const head = abstract ? rising(title, 180) : esc(title);
      const tail = sub ? '<div style="margin-top:3px;font-size:clamp(14px,1.18vw,22px);line-height:1.5;color:' + c.muted + (abstract ? ';opacity:0;animation:vdFade ' + ms(500) + ' ease-out ' + ms(550) + ' forwards' : '') + '">' + esc(sub) + '</div>' : '';
      el.innerHTML = bar + '<div><div style="font-size:clamp(16px,1.5vw,28px);font-weight:600;letter-spacing:-.015em;line-height:1.3">' + head + '</div>' + tail + '</div>';
      show(el);
    },
    card(title, sub, n) {
      const el = document.createElement('div');
      if (headline) {
        el.style.cssText = 'position:absolute;inset:0;display:flex;flex-direction:column;justify-content:center;gap:22px;padding:0 9vw;overflow:hidden;'
          + 'background:' + c.bg + ';color:' + c.ink + ';opacity:0;transition:opacity ' + ms(400);
        el.innerHTML = label(n) + '<div style="max-width:min(80vw,1500px)">' + headlineText(title, sub, 'clamp(34px,5.4vw,108px)', 'clamp(26px,4vw,80px)', 250) + '</div>'
          // A short accent line at the foot, growing in.
          + '<div style="position:absolute;left:9vw;bottom:7vh;width:min(120px,12vw);height:3px;border-radius:2px;background:' + c.bar + ';transform-origin:left;animation:vdGrow ' + ms(900) + ' cubic-bezier(.2,.7,.2,1) ' + ms(300) + ' both"></div>';
        show(el);

        return;
      }
      el.style.cssText = 'position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:56px;overflow:hidden;'
        + 'background:' + c.bg + ';color:' + c.ink + ';opacity:0;transition:opacity ' + ms(450);
      const words = abstract ? rising(title, 350) : esc(title);
      const subStart = 350 + title.split(' ').length * 70 + 250;
      el.innerHTML = (abstract ? art(title) : '')
        + '<div style="position:relative;width:40px;height:3px;border-radius:2px;background:' + c.bar + ';margin-bottom:28px' + (abstract ? ';animation:vdGrow ' + ms(600) + ' cubic-bezier(.2,.7,.2,1) ' + ms(200) + ' both' : '') + '"></div>'
        + '<div style="position:relative;font-size:clamp(30px,' + (abstract ? '4.2vw,84px' : '3.6vw,72px') + ');font-weight:' + (abstract ? 700 : 600) + ';letter-spacing:-.035em;max-width:min(74vw,1400px);line-height:1.06">' + words + '</div>'
        + (sub ? '<div style="position:relative;margin-top:18px;font-size:clamp(16px,1.6vw,30px);line-height:1.5;color:' + c.muted + ';max-width:min(60vw,1100px)' + (abstract ? ';opacity:0;animation:vdFade ' + ms(600) + ' ease-out ' + ms(subStart) + ' forwards' : '') + '">' + esc(sub) + '</div>' : '');
      show(el);
    },
    hide() {
      if (!current) return;
      const el = current;
      current = null;
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 450 * S);
    },
  };
}
const OVERLAY_JS = '(' + overlay.toString() + ')();';

// Every pause and the typing delay are scaled by the chosen pace. "instant" is
// for checking a plan without recording it.
const PACES = { relaxed: 1.3, normal: 1, brisk: 0.7, instant: 0.05 };
let pace = 1;
const setPace = name => { pace = PACES[name] || 1; };
const typingDelay = () => Math.round(55 * pace);

const pause = (page, ms) => page.waitForTimeout(Math.round(ms * pace));

// Eased scrolling, the way a person scrolls: one smooth glide over a time that
// suits the distance. Scrolls the page, or the panel the element sits in.
async function scrollBy(page, dy, target = null) {
  await page.evaluate(([dy, el]) => new Promise(done => {
    const scroller = (() => {
      for (let n = el && el.parentElement; n && n !== document.body; n = n.parentElement) {
        const o = getComputedStyle(n).overflowY;
        if ((o === 'auto' || o === 'scroll') && n.scrollHeight > n.clientHeight + 4) return n;
      }
      return document.scrollingElement;
    })();
    const from = scroller.scrollTop;
    const max = scroller.scrollHeight - scroller.clientHeight;
    const to = Math.max(0, Math.min(max, from + dy));
    const ms = Math.min(1400, Math.max(520, 420 + Math.abs(to - from) * 0.7));
    const ease = t => t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    const start = performance.now();
    const step = now => {
      const t = Math.min(1, (now - start) / ms);
      scroller.scrollTop = from + (to - from) * ease(t);
      t < 1 ? requestAnimationFrame(step) : done();
    };
    requestAnimationFrame(step);
  }), [dy, target ? await target.elementHandle() : null]);
  await pause(page, 250);
}

// Smoothly bring an element to the middle of the screen, or near the top for
// one taller than most of the screen. Nothing moves if it is already there.
async function scrollTo(page, locator) {
  const offset = await locator.evaluate(el => {
    const r = el.getBoundingClientRect();
    return r.height > innerHeight * 0.6 ? r.top - innerHeight * 0.15 : r.top + r.height / 2 - innerHeight / 2;
  });
  if (Math.abs(offset) > 40) await scrollBy(page, offset, locator);
}

// Scroll an element into view and move the cursor to its centre.
async function glide(page, locator) {
  await scrollTo(page, locator);
  const box = await locator.boundingBox();
  if (!box) throw new Error('The element moved or disappeared before it could be used.');
  const x = box.x + box.width / 2, y = box.y + box.height / 2;
  const ms = await page.evaluate(([x, y]) => (window.__demoCursor ? window.__demoCursor.move(x, y) : 0), [x, y]);
  // Arrive, then rest a moment on the target, as a person does before clicking.
  await page.waitForTimeout(ms);
  await pause(page, 220);
  return { x, y };
}

async function click(page, locator) {
  const { x, y } = await glide(page, locator);
  await page.evaluate(([x, y]) => window.__demoCursor && window.__demoCursor.click(x, y), [x, y]);
  await locator.click();
}

// An outline around the element being used, dimming the rest of the page, for
// styles with "highlight". Solid colours only.
const HIGHLIGHT_JS = `
(() => {
  if (window.__demoHighlight) return;
  const accent = (window.__demoStyle && window.__demoStyle.accent) || '#e5693d';
  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;z-index:2147483645;pointer-events:none;border-radius:10px;border:3px solid ' + accent + ';box-shadow:0 0 0 200vmax rgba(8,8,7,.5);opacity:0;transition:opacity .35s ease, left .45s ease, top .45s ease, width .45s ease, height .45s ease';
  const attach = () => { if (!box.isConnected && document.body) document.body.appendChild(box); };
  window.__demoHighlight = {
    show(r) {
      attach();
      const pad = 8;
      box.style.left = (r.x - pad) + 'px'; box.style.top = (r.y - pad) + 'px';
      box.style.width = (r.width + pad * 2) + 'px'; box.style.height = (r.height + pad * 2) + 'px';
      box.style.opacity = '1';
    },
    hide() { box.style.opacity = '0'; },
  };
})();`;

module.exports = { CURSOR_JS, OVERLAY_JS, HIGHLIGHT_JS, setPace, typingDelay, pause, glide, click, scrollBy, scrollTo };
