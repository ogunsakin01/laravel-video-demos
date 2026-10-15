// Mixes a video's sound track: the voice-over clips, each at its moment, over
// background music that loops to the video's length, fades in and out, and
// drops while the voice speaks. Plain Node, so it works with any ffmpeg build.
//   node mix.cjs <job.json>
// The job: { seconds, out, clips: [{ file, at }], music: { file, volume } | null }.
// Every input is a 16-bit stereo WAV at 44.1 kHz (ffmpeg converts them first).
// Writes result.json next to the job: { ok, error }.
const fs = require('fs');
const path = require('path');

const RATE = 44100;
const DUCK = 0.25;      // music volume while the voice speaks, as a share of its own
const ATTACK = 0.25;    // seconds for the music to drop before a line
const RELEASE = 0.6;    // seconds for it to come back after
const FADE_IN = 1.0;
const FADE_OUT = 2.0;
const VOICE_PEAK = 29000; // voices are brought up to this peak, so a quiet voice still sits above the music

// Read the samples of a 16-bit stereo WAV, finding its data among other chunks.
function readWav(file) {
  const b = fs.readFileSync(file);
  if (b.toString('ascii', 0, 4) !== 'RIFF' || b.toString('ascii', 8, 12) !== 'WAVE') throw new Error(`${path.basename(file)} is not a WAV file.`);
  let i = 12, fmt = null;
  while (i + 8 <= b.length) {
    const id = b.toString('ascii', i, i + 4), size = b.readUInt32LE(i + 4), body = i + 8;
    if (id === 'fmt ') fmt = { channels: b.readUInt16LE(body + 2), rate: b.readUInt32LE(body + 4), bits: b.readUInt16LE(body + 14) };
    if (id === 'data') {
      if (!fmt || fmt.channels !== 2 || fmt.rate !== RATE || fmt.bits !== 16) throw new Error(`${path.basename(file)} is not 16-bit stereo at 44.1 kHz.`);
      const end = Math.min(b.length, body + size);
      return new Int16Array(b.buffer.slice(b.byteOffset + body, b.byteOffset + end - ((end - body) % 4)));
    }
    i = body + size + (size % 2);
  }
  throw new Error(`${path.basename(file)} has no sound data.`);
}

function writeWav(file, samples) {
  const data = Buffer.alloc(samples.length * 2);
  for (let k = 0; k < samples.length; k++) data.writeInt16LE(Math.max(-32768, Math.min(32767, Math.round(samples[k]))), k * 2);
  const head = Buffer.alloc(44);
  head.write('RIFF', 0); head.writeUInt32LE(36 + data.length, 4); head.write('WAVE', 8);
  head.write('fmt ', 12); head.writeUInt32LE(16, 16); head.writeUInt16LE(1, 20); head.writeUInt16LE(2, 22);
  head.writeUInt32LE(RATE, 24); head.writeUInt32LE(RATE * 4, 28); head.writeUInt16LE(4, 32); head.writeUInt16LE(16, 34);
  head.write('data', 36); head.writeUInt32LE(data.length, 40);
  fs.writeFileSync(file, Buffer.concat([head, data]));
}

const jobFile = process.argv[2];
const finish = result => { fs.writeFileSync(path.join(path.dirname(jobFile), 'result.json'), JSON.stringify(result)); process.exit(0); };

try {
  const job = JSON.parse(fs.readFileSync(jobFile, 'utf8'));
  const frames = Math.max(1, Math.ceil(job.seconds * RATE));
  const mix = new Float32Array(frames * 2);
  // How loud the music may be at each frame: 1, or lower while the voice speaks.
  const room = new Float32Array(frames).fill(1);

  for (const clip of job.clips) {
    const s = readWav(clip.file);
    const start = Math.max(0, Math.round(clip.at * RATE));
    const length = s.length / 2;
    let peak = 1;
    for (let k = 0; k < s.length; k++) peak = Math.max(peak, Math.abs(s[k]));
    const gain = Math.min(4, VOICE_PEAK / peak);
    for (let f = 0; f < length && start + f < frames; f++) {
      mix[(start + f) * 2] += s[f * 2] * gain;
      mix[(start + f) * 2 + 1] += s[f * 2 + 1] * gain;
    }
    // Duck the music around the line, easing down before it and back up after.
    const from = Math.max(0, start - ATTACK * RATE), to = Math.min(frames, start + length + RELEASE * RATE);
    for (let f = from; f < to; f++) {
      const t = f < start ? (start - f) / (ATTACK * RATE) : f > start + length ? (f - start - length) / (RELEASE * RATE) : 0;
      room[f] = Math.min(room[f], DUCK + (1 - DUCK) * Math.min(1, t));
    }
  }

  if (job.music) {
    const m = readWav(job.music.file);
    const length = m.length / 2;
    if (length > 0) {
      for (let f = 0; f < frames; f++) {
        const fade = Math.min(1, f / (FADE_IN * RATE), (frames - f) / (FADE_OUT * RATE));
        const gain = job.music.volume * room[f] * fade;
        const src = f % length;
        mix[f * 2] += m[src * 2] * gain;
        mix[f * 2 + 1] += m[src * 2 + 1] * gain;
      }
    }
  }

  writeWav(job.out, mix);
  finish({ ok: true });
} catch (e) {
  finish({ ok: false, error: String(e.message || e).split('\n')[0] });
}
