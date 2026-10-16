// Turns a video's frames into an animated GIF, for READMEs, changelogs, emails
// and chat, which don't play videos. ffmpeg splits the video into frames; this
// scales them and encodes the GIF with gifenc in the browser the recorder
// already uses, so any ffmpeg build works.
//   node gif.cjs <job.json>
// The job gives the frames folder, the GIF to write, the frame rate and the
// width. Writes done.json next to the GIF: its size and frame count.
const fs = require('fs');
const path = require('path');
const { launch } = require('./browser.cjs');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
// gifenc is a CommonJS file; it runs in the page as a plain script.
const GIFENC = fs.readFileSync(require.resolve('gifenc'), 'utf8');

(async () => {
  const frames = fs.readdirSync(job.framesDir).filter(f => f.endsWith('.jpg')).sort();
  if (!frames.length) throw new Error('The video has no frames.');
  const browser = await launch(job);
  try {
    const page = await browser.newPage();
    await page.setContent('<canvas></canvas>');
    await page.addScriptTag({ content: 'var module = { exports: {} }, exports = module.exports;\n' + GIFENC + '\nwindow.gifenc = module.exports;' });
    await page.evaluate(({ width, fps }) => {
      window.gif = window.gifenc.GIFEncoder();
      window.size = { width, delay: Math.round(1000 / fps) };
    }, { width: job.width, fps: job.fps });

    for (let i = 0; i < frames.length; i += 10) {
      const batch = frames.slice(i, i + 10).map(name => fs.readFileSync(path.join(job.framesDir, name)).toString('base64'));
      await page.evaluate(async items => {
        const canvas = document.querySelector('canvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const { quantize, applyPalette } = window.gifenc;
        for (const data of items) {
          const img = new Image();
          img.src = 'data:image/jpeg;base64,' + data;
          await img.decode();
          const w = Math.min(window.size.width, img.naturalWidth);
          const h = Math.round(img.naturalHeight * w / img.naturalWidth);
          if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
          ctx.imageSmoothingQuality = 'high';
          ctx.drawImage(img, 0, 0, w, h);
          const rgba = ctx.getImageData(0, 0, w, h).data;
          // Each frame gets its own 256 colours, so captions and screenshots stay crisp.
          const palette = quantize(rgba, 256);
          window.gif.writeFrame(applyPalette(rgba, palette), w, h, { palette, delay: window.size.delay });
        }
      }, batch);
    }

    // The finished GIF comes back in pieces, as base64.
    const length = await page.evaluate(() => { window.gif.finish(); window.bytes = window.gif.bytes(); return window.bytes.length; });
    const out = fs.openSync(job.out, 'w');
    for (let at = 0; at < length; at += 4 * 1024 * 1024) {
      const piece = await page.evaluate(([from, to]) => {
        let s = '';
        const part = window.bytes.subarray(from, to);
        for (let k = 0; k < part.length; k += 0x8000) s += String.fromCharCode.apply(null, part.subarray(k, k + 0x8000));
        return btoa(s);
      }, [at, Math.min(length, at + 4 * 1024 * 1024)]);
      fs.writeSync(out, Buffer.from(piece, 'base64'));
    }
    fs.closeSync(out);
    fs.writeFileSync(path.join(path.dirname(job.out), 'done.json'), JSON.stringify({ frames: frames.length, bytes: length }));
  } finally {
    await browser.close();
  }
  process.exit(0);
})().catch(e => { console.error(String(e.message || e).split('\n')[0]); process.exit(1); });
