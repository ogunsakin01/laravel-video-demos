// A visible cursor that glides to each target before acting, eased scrolling,
// and the caption and title card overlays.
const CURSOR_JS = `
(() => {
  if (window.__demoCursor) return;
  const c = document.createElement('div');
  c.style.cssText = 'position:fixed;z-index:2147483647;left:0;top:0;width:22px;height:22px;margin:-4px 0 0 -4px;pointer-events:none;transition:transform .7s cubic-bezier(.2,.7,.2,1);transform:translate(640px,420px)';
  c.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24"><path d="M4 2l16 9-7 2-3 7z" fill="#111" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>';
  const ring = document.createElement('div');
  ring.style.cssText = 'position:fixed;z-index:2147483646;width:34px;height:34px;margin:-17px 0 0 -17px;border-radius:50%;background:rgba(17,17,17,.18);pointer-events:none;opacity:0;transform:scale(.4);transition:opacity .35s, transform .35s';
  // Positions must be final the moment they are measured
  const st = document.createElement('style');
  st.textContent = 'html,body{scroll-behavior:auto!important}';
  // Phones and tablets have no pointer: show only the tap ring
  if (window.__demoTouch) c.style.display = 'none';
  const add = () => { document.head.appendChild(st); document.body.appendChild(c); document.body.appendChild(ring); };
  document.body ? add() : document.addEventListener('DOMContentLoaded', add);
  // Re-attach after the document is replaced in the same window (page.setContent)
  const attach = () => { if (!c.isConnected && document.body) add(); };
  window.__demoCursor = {
    move(x, y) { attach(); c.style.transform = 'translate(' + x + 'px,' + y + 'px)'; },
    click(x, y) {
      attach();
      ring.style.left = x + 'px'; ring.style.top = y + 'px';
      ring.style.opacity = '1'; ring.style.transform = 'scale(1)';
      setTimeout(() => { ring.style.opacity = '0'; ring.style.transform = 'scale(.4)'; }, 350);
    },
  };
})();`;

// Title cards and captions, in the video's style (window.__demoStyle). Solid
// colours only: no gradients.
const OVERLAY_JS = `
(() => {
  if (window.__demoOverlay) return;
  const style = Object.assign({ theme: 'light', accent: '#171717', captions: 'bottom' }, window.__demoStyle || {});
  const dark = style.theme === 'dark';
  const c = dark
    ? { bg: '#171717', ink: '#fafaf8', muted: 'rgba(250,250,248,.72)', line: 'rgba(255,255,255,.12)', bar: style.accent.toLowerCase() === '#171717' ? '#fafaf8' : style.accent }
    : { bg: '#faf8f3', ink: '#171717', muted: '#5f636e', line: '#e5e2dc', bar: style.accent };
  const font = "'Geist', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
  const root = document.createElement('div');
  // Same layer as the cursor but added later, so cards cover it
  root.style.cssText = 'position:fixed;inset:0;z-index:2147483647;pointer-events:none;font-family:' + font + ';-webkit-font-smoothing:antialiased';
  const esc = s => String(s).replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch]);
  const add = () => {
    document.body.appendChild(root);
    // Geist for cards and captions; the system font shows if it can't load
    if (!document.querySelector('link[data-demo-font]')) {
      const l = document.createElement('link');
      l.rel = 'stylesheet'; l.dataset.demoFont = '';
      l.href = 'https://fonts.bunny.net/css?family=geist:400,500,600&display=swap';
      document.head.appendChild(l);
    }
  };
  document.body ? add() : document.addEventListener('DOMContentLoaded', add);
  let current = null;
  const show = el => {
    // Re-attach after the document is replaced in the same window (page.setContent)
    if (!root.isConnected) document.body.appendChild(root);
    if (current) current.remove();
    current = el;
    root.appendChild(el);
    requestAnimationFrame(() => requestAnimationFrame(() => { el.style.opacity = '1'; el.style.transform = 'none'; }));
  };
  window.__demoOverlay = {
    caption(title, sub) {
      const el = document.createElement('div');
      const edge = style.captions === 'top' ? 'top:28px' : 'bottom:36px';
      const shift = style.captions === 'top' ? '-10px' : '10px';
      el.style.cssText = 'position:absolute;left:50%;' + edge + ';width:max-content;max-width:min(54vw,1000px);min-width:min(320px,88vw);translate:-50% 0;display:flex;gap:14px;align-items:flex-start;padding:16px 20px 16px 18px;border-radius:12px;'
        + 'background:' + c.bg + ';color:' + c.ink + ';border:1px solid ' + c.line + ';box-shadow:0 16px 40px -12px rgba(0,0,0,.28);opacity:0;transform:translateY(' + shift + ');transition:opacity .35s, transform .35s';
      el.innerHTML = '<span style="flex:none;width:3px;align-self:stretch;border-radius:2px;background:' + c.bar + '"></span><div>'
        + '<div style="font-size:clamp(16px,1.5vw,28px);font-weight:600;letter-spacing:-.015em;line-height:1.3">' + esc(title) + '</div>'
        + (sub ? '<div style="margin-top:3px;font-size:clamp(14px,1.18vw,22px);line-height:1.5;color:' + c.muted + '">' + esc(sub) + '</div>' : '') + '</div>';
      show(el);
    },
    card(title, sub) {
      const el = document.createElement('div');
      el.style.cssText = 'position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:56px;'
        + 'background:' + c.bg + ';color:' + c.ink + ';opacity:0;transition:opacity .45s';
      el.innerHTML = '<div style="width:40px;height:3px;border-radius:2px;background:' + c.bar + ';margin-bottom:28px"></div>'
        + '<div style="font-size:clamp(30px,3.6vw,72px);font-weight:600;letter-spacing:-.03em;max-width:min(72vw,1400px);line-height:1.1">' + esc(title) + '</div>'
        + (sub ? '<div style="margin-top:16px;font-size:clamp(16px,1.6vw,30px);line-height:1.5;color:' + c.muted + ';max-width:min(60vw,1100px)">' + esc(sub) + '</div>' : '');
      show(el);
    },
    hide() {
      if (!current) return;
      const el = current;
      current = null;
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 450);
    },
  };
})();`;

// How fast the video moves: every pause and the typing speed scale by this
const PACES = { relaxed: 1.3, normal: 1, brisk: 0.7 };
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

async function glide(page, locator) {
  await scrollTo(page, locator);
  const box = await locator.boundingBox();
  const x = box.x + box.width / 2, y = box.y + box.height / 2;
  await page.evaluate(([x, y]) => window.__demoCursor && window.__demoCursor.move(x, y), [x, y]);
  await pause(page, 800);
  return { x, y };
}

async function click(page, locator) {
  const { x, y } = await glide(page, locator);
  await page.evaluate(([x, y]) => window.__demoCursor && window.__demoCursor.click(x, y), [x, y]);
  await locator.click();
}

module.exports = { CURSOR_JS, OVERLAY_JS, setPace, typingDelay, pause, glide, click, scrollBy, scrollTo };
