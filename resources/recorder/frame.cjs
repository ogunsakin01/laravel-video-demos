// Reframes a recording for social posts: a square or vertical (9:16) frame
// with the video's title above the app, the app cropped to fit and following
// each step as it happens. Solid colours only. ffmpeg splits the video into
// frames and joins them again; this draws each frame in the browser the
// recorder already uses, so any ffmpeg build works.
//   node frame.cjs <job.json>
// The job gives the frames folder, the output folder, the output and source
// sizes, the frame rate, the words (eyebrow, title, footer), the look (theme,
// accent), and focus: where each step happened, [{ t, x, y, w, h } or { t, wide }].
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const { width: W, height: H, srcWidth: SW, srcHeight: SH, fps } = job;
const MOVE = 0.6, LEAD = 0.25;
const ease = u => u * u * (3 - 2 * u);

// The box the app is shown in, under the title.
const vertical = H > W * 1.2;
const pad = vertical ? 64 : 56;
const area = vertical
  ? { x: pad, y: 600, w: W - pad * 2, h: H - 600 - 260 }
  : { x: pad, y: 268, w: W - pad * 2, h: H - 268 - pad };

// The part of the recording that fills the box: its full height (or width),
// at the box's shape.
const aspect = area.w / area.h;
const crop = aspect < SW / SH ? { w: SH * aspect, h: SH } : { w: SW, h: SW / aspect };

// Where the crop is centred at each moment: on the element a step uses, or the
// middle of the page, easing from one to the next.
const centres = [{ s: 0, x: SW / 2, y: SH / 2 }];
for (const f of job.follow ? (job.focus || []) : []) {
  const c = f.wide || !f.w ? { x: SW / 2, y: SH / 2 } : { x: f.x + f.w / 2, y: f.y + f.h / 2 };
  const last = centres[centres.length - 1];
  if (Math.abs(last.x - c.x) < 8 && Math.abs(last.y - c.y) < 8) continue;
  centres.push({ s: Math.max(last.s + MOVE, f.t - LEAD), ...c });
}
function centreAt(t) {
  let i = 0;
  while (i + 1 < centres.length && centres[i + 1].s <= t) i++;
  const cur = centres[i], prev = centres[i - 1];
  if (!prev) return cur;
  const u = ease(Math.min(1, Math.max(0, (t - cur.s) / MOVE)));
  return { x: prev.x + (cur.x - prev.x) * u, y: prev.y + (cur.y - prev.y) * u };
}

(async () => {
  const frames = fs.readdirSync(job.framesDir).filter(f => f.endsWith('.jpg')).sort();
  fs.mkdirSync(job.outDir, { recursive: true });
  const browser = await chromium.launch(job.channel ? { channel: job.channel } : {});
  try {
    const page = await browser.newPage();
    await page.setContent(`<html><head><link rel="stylesheet" href="https://fonts.bunny.net/css?family=geist:500,700|geist-mono:500&display=swap"></head><body><canvas width="${W}" height="${H}"></canvas></body></html>`, { waitUntil: 'load', timeout: 8000 }).catch(() => {});

    // The title band, drawn once: Geist when it loads, the system font if not.
    await page.evaluate(async ({ job, W, H, area, vertical, pad }) => {
      await Promise.race([document.fonts.load('700 60px Geist').catch(() => {}), new Promise(r => setTimeout(r, 3000))]);
      const dark = job.theme === 'dark';
      const plain = String(job.accent || '').toLowerCase() === '#171717';
      const c = dark
        ? { bg: '#141412', ink: '#fafaf8', muted: '#a3a199', line: '#2e2d29', accent: plain ? '#fafaf8' : job.accent }
        : { bg: '#faf8f3', ink: '#171717', muted: '#6b6f7b', line: '#e5e2dc', accent: job.accent || '#171717' };
      const base = document.createElement('canvas');
      base.width = W; base.height = H;
      const ctx = base.getContext('2d');
      const font = (weight, size, mono) => `${weight} ${size}px ${mono ? "'Geist Mono', ui-monospace, Menlo, monospace" : "'Geist', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"}`;
      ctx.fillStyle = c.bg;
      ctx.fillRect(0, 0, W, H);

      // The words of a line wrapped to the width, at most `max` lines.
      const wrap = (text, width, max) => {
        const lines = [];
        let line = '';
        for (const word of String(text).split(/\s+/)) {
          const next = line ? line + ' ' + word : word;
          if (ctx.measureText(next).width > width && line) { lines.push(line); line = word; } else line = next;
        }
        if (line) lines.push(line);
        if (lines.length > max) { lines.length = max; lines[max - 1] = lines[max - 1].replace(/\s*\S*$/, '') + '…'; }
        return lines;
      };

      let y = vertical ? 170 : 92;
      if (job.eyebrow) {
        ctx.font = font(500, vertical ? 30 : 24, true);
        ctx.fillStyle = c.muted;
        ctx.fillText(job.eyebrow, pad, y);
        y += vertical ? 34 : 22;
      }
      const size = vertical ? 78 : 54;
      ctx.font = font(700, size);
      ctx.fillStyle = c.ink;
      for (const line of wrap(job.title, W - pad * 2, vertical ? 3 : 2)) {
        y += size * 1.08;
        ctx.fillText(line, pad, y);
      }
      // A short accent line under the title.
      ctx.fillStyle = c.accent;
      ctx.fillRect(pad, y + (vertical ? 34 : 22), vertical ? 96 : 72, vertical ? 5 : 4);

      if (vertical && job.footer) {
        ctx.font = font(500, 32);
        ctx.fillStyle = c.muted;
        ctx.fillText(job.footer, pad, H - 120);
      }

      // The box's outline, drawn over each frame.
      window.frameLook = { line: c.line, bg: c.bg };
      window.base = base;
    }, { job, W, H, area, vertical, pad });

    const batch = [];
    const flush = async () => {
      if (!batch.length) return;
      const out = await page.evaluate(async ({ items, area, crop }) => {
        const canvas = document.querySelector('canvas');
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        const r = 18;
        const results = [];
        for (const it of items) {
          const img = new Image();
          img.src = 'data:image/jpeg;base64,' + it.data;
          await img.decode();
          ctx.drawImage(window.base, 0, 0);
          const sx = Math.min(Math.max(it.x - crop.w / 2, 0), img.naturalWidth - crop.w);
          const sy = Math.min(Math.max(it.y - crop.h / 2, 0), img.naturalHeight - crop.h);
          ctx.save();
          ctx.beginPath();
          ctx.roundRect(area.x, area.y, area.w, area.h, r);
          ctx.clip();
          ctx.drawImage(img, sx, sy, crop.w, crop.h, area.x, area.y, area.w, area.h);
          ctx.restore();
          ctx.strokeStyle = window.frameLook.line;
          ctx.lineWidth = 2;
          ctx.beginPath();
          ctx.roundRect(area.x, area.y, area.w, area.h, r);
          ctx.stroke();
          results.push(canvas.toDataURL('image/jpeg', 0.9).split(',')[1]);
        }
        return results;
      }, { items: batch.map(b => ({ data: b.data, x: b.x, y: b.y })), area, crop });
      out.forEach((data, k) => fs.writeFileSync(path.join(job.outDir, batch[k].name), Buffer.from(data, 'base64')));
      batch.length = 0;
    };

    for (let i = 0; i < frames.length; i++) {
      const name = frames[i];
      batch.push({ name, data: fs.readFileSync(path.join(job.framesDir, name)).toString('base64'), ...centreAt(i / fps) });
      if (batch.length >= 12) await flush();
    }
    await flush();
    fs.writeFileSync(path.join(job.outDir, 'done.json'), JSON.stringify({ frames: frames.length }));
  } finally {
    await browser.close();
  }
  process.exit(0);
})().catch(e => { console.error(String(e.message || e).split('\n')[0]); process.exit(1); });
