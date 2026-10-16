// Draws a recording's frames through a virtual camera: zoomed in on each step
// and eased between positions. ffmpeg splits the video into frames and joins
// them again; this runs in between, in the browser the recorder already uses,
// so any ffmpeg build works.
//   node camera.cjs <job.json>
// The job gives the frames folder, the output folder, the frame size and rate,
// and the keyframes: [{ s: start second, z: zoom, x, y: centre }].
const fs = require('fs');
const path = require('path');
const { launch } = require('./browser.cjs');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const { width: W, height: H, fps, keys } = job;
const MOVE = job.move || 0.6;
const ease = u => u * u * (3 - 2 * u);

// Where the camera is at a moment: holding a keyframe, or easing into the next one.
function cameraAt(t) {
  let i = 0;
  while (i + 1 < keys.length && keys[i + 1].s <= t) i++;
  const cur = keys[i], prev = keys[i - 1];
  if (!prev) return cur;
  const u = ease(Math.min(1, Math.max(0, (t - cur.s) / MOVE)));
  return { z: prev.z + (cur.z - prev.z) * u, x: prev.x + (cur.x - prev.x) * u, y: prev.y + (cur.y - prev.y) * u };
}

(async () => {
  const frames = fs.readdirSync(job.framesDir).filter(f => f.endsWith('.jpg')).sort();
  fs.mkdirSync(job.outDir, { recursive: true });
  const browser = await launch(job);
  try {
    const page = await browser.newPage();
    await page.setContent(`<canvas width="${W}" height="${H}"></canvas>`);

    const batch = [];
    const flush = async () => {
      if (!batch.length) return;
      const out = await page.evaluate(async items => {
        const canvas = document.querySelector('canvas');
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        const results = [];
        for (const it of items) {
          const img = new Image();
          img.src = 'data:image/jpeg;base64,' + it.data;
          await img.decode();
          const sw = canvas.width / it.z, sh = canvas.height / it.z;
          const sx = Math.min(Math.max(it.x - sw / 2, 0), canvas.width - sw);
          const sy = Math.min(Math.max(it.y - sh / 2, 0), canvas.height - sh);
          ctx.drawImage(img, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
          results.push(canvas.toDataURL('image/jpeg', 0.93).split(',')[1]);
        }
        return results;
      }, batch.map(b => ({ data: b.data, z: b.z, x: b.x, y: b.y })));
      out.forEach((data, k) => fs.writeFileSync(path.join(job.outDir, batch[k].name), Buffer.from(data, 'base64')));
      batch.length = 0;
    };

    for (let i = 0; i < frames.length; i++) {
      const name = frames[i];
      const cam = cameraAt(i / fps);
      // Frames at full view are copied as they are.
      if (cam.z < 1.005) {
        fs.copyFileSync(path.join(job.framesDir, name), path.join(job.outDir, name));
        continue;
      }
      batch.push({ name, data: fs.readFileSync(path.join(job.framesDir, name)).toString('base64'), ...cam });
      if (batch.length >= 12) await flush();
    }
    await flush();
    fs.writeFileSync(path.join(job.outDir, 'done.json'), JSON.stringify({ frames: frames.length }));
  } finally {
    await browser.close();
  }
  process.exit(0);
})().catch(e => { console.error(String(e.message || e).split('\n')[0]); process.exit(1); });
