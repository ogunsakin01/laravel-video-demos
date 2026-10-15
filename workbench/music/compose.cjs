// Composes the package's built-in background music: four short pieces that
// loop seamlessly, written by this script, so they are the package's own
// work and free to use in any video (CC0). Plain Node, no packages.
//   node workbench/music/compose.cjs <out-dir>
// Writes calm.wav, upbeat.wav, corporate.wav and cinematic.wav, which are then
// encoded to MP3 into docs/music (see the README there).
const fs = require('fs');
const path = require('path');

const RATE = 44100;
const out = process.argv[2] || path.join(__dirname, 'out');
fs.mkdirSync(out, { recursive: true });

// ---------------------------------------------------------------- Helpers

const hz = n => 440 * Math.pow(2, (n - 69) / 12);            // MIDI note to frequency
const NOTE = { C: 0, 'C#': 1, D: 2, 'D#': 3, E: 4, F: 5, 'F#': 6, G: 7, 'G#': 8, A: 9, 'A#': 10, B: 11 };
const midi = (name, octave) => 12 * (octave + 1) + NOTE[name];

// A seeded random, so the pieces come out the same every time.
let seed = 7;
const rand = () => { seed = (seed * 16807) % 2147483647; return seed / 2147483647; };

// A stereo buffer that wraps around, so tails (reverb, long notes) carry over
// into the start and the piece loops without a seam.
function track(seconds) {
  const frames = Math.round(seconds * RATE);
  return { frames, l: new Float32Array(frames), r: new Float32Array(frames) };
}
function add(t, start, samples, gain, pan = 0) {
  const s0 = Math.round(start * RATE), gl = gain * Math.cos((pan + 1) * Math.PI / 4), gr = gain * Math.sin((pan + 1) * Math.PI / 4);
  for (let i = 0; i < samples.length; i++) {
    const f = (s0 + i) % t.frames;
    t.l[f] += samples[i] * gl;
    t.r[f] += samples[i] * gr;
  }
}

// A plucked string (Karplus-Strong): a burst of noise through a decaying loop.
function pluck(freq, seconds, brightness = 0.5) {
  const n = Math.round(seconds * RATE), period = Math.max(2, Math.round(RATE / freq));
  const ring = new Float32Array(period);
  for (let i = 0; i < period; i++) ring[i] = rand() * 2 - 1;
  const out = new Float32Array(n);
  let idx = 0, last = 0;
  const damp = 0.996 - (1 - brightness) * 0.004;
  for (let i = 0; i < n; i++) {
    const v = ring[idx];
    const next = ring[(idx + 1) % period];
    ring[idx] = damp * (brightness * v + (1 - brightness) * (v + next) / 2);
    out[i] = (v + last) / 2;
    last = v;
    idx = (idx + 1) % period;
  }
  // A short fade, so the note ends without a click.
  for (let i = Math.max(0, n - 600); i < n; i++) out[i] *= (n - i) / 600;
  return out;
}

// A soft electric-piano tone: a few harmonics with a gentle decay.
function keys(freq, seconds) {
  const n = Math.round(seconds * RATE), out = new Float32Array(n);
  for (let i = 0; i < n; i++) {
    const t = i / RATE, env = Math.min(1, t * 200) * Math.exp(-t * 2.2);
    out[i] = env * (Math.sin(2 * Math.PI * freq * t) + 0.35 * Math.sin(4 * Math.PI * freq * t) * Math.exp(-t * 4) + 0.12 * Math.sin(6 * Math.PI * freq * t) * Math.exp(-t * 6));
  }
  for (let i = Math.max(0, n - 600); i < n; i++) out[i] *= (n - i) / 600;
  return out;
}

// A pad or string section: detuned voices, a slow swell and release, softened.
function pad(freqs, seconds, attack = 1.2, release = 1.5, bright = 0.08) {
  const n = Math.round((seconds + release) * RATE), out = new Float32Array(n);
  const voices = freqs.flatMap(f => [f * 0.997, f, f * 1.003]);
  const phase = voices.map(() => rand());
  let lp = 0;
  for (let i = 0; i < n; i++) {
    const t = i / RATE;
    const env = t < attack ? t / attack : t < seconds ? 1 : Math.max(0, 1 - (t - seconds) / release);
    let v = 0;
    voices.forEach((f, k) => {
      const p = (phase[k] + f * t) % 1;
      v += Math.sin(2 * Math.PI * p) * 0.7 + (2 * p - 1) * 0.3;  // mostly sine, a little saw
    });
    lp += bright * (v / voices.length - lp);                  // one-pole low-pass
    out[i] = lp * env * 3;
  }
  return out;
}

function bass(freq, seconds) {
  const n = Math.round(seconds * RATE), out = new Float32Array(n);
  for (let i = 0; i < n; i++) {
    const t = i / RATE, env = Math.min(1, t * 150) * Math.exp(-t * 1.6);
    out[i] = env * (Math.sin(2 * Math.PI * freq * t) + 0.25 * Math.sin(4 * Math.PI * freq * t));
  }
  for (let i = Math.max(0, n - 600); i < n; i++) out[i] *= (n - i) / 600;
  return out;
}

function kick(soft = 1) {
  const n = Math.round(0.35 * RATE), out = new Float32Array(n);
  let p = 0;
  for (let i = 0; i < n; i++) {
    const t = i / RATE, f = 48 + 90 * Math.exp(-t * 30);
    p += f / RATE;
    out[i] = Math.sin(2 * Math.PI * p) * Math.exp(-t * (9 / soft));
  }
  return out;
}

function noise(seconds, decay, tone = 0.5) {
  const n = Math.round(seconds * RATE), out = new Float32Array(n);
  let hp = 0, prev = 0;
  for (let i = 0; i < n; i++) {
    const x = rand() * 2 - 1;
    hp = tone * (hp + x - prev);                              // one-pole high-pass
    prev = x;
    out[i] = hp * Math.exp(-(i / RATE) * decay);
  }
  return out;
}
const hat = () => noise(0.06, 70, 0.9);
const clap = () => noise(0.18, 22, 0.6);

// A gentle stereo reverb: a few feedback delays, applied around the loop.
function reverb(t, mix = 0.25) {
  const taps = [[1557, 0.82], [1617, 0.8], [1491, 0.83], [1422, 0.81]];
  const wl = new Float32Array(t.frames), wr = new Float32Array(t.frames);
  for (const [d, fb] of taps) {
    const buf = new Float32Array(d * 6);
    // Two passes around the loop, so the tail at the end reaches the start.
    for (let pass = 0; pass < 2; pass++) {
      for (let i = 0; i < t.frames; i++) {
        const k = i % buf.length;
        const y = buf[k];
        buf[k] = (t.l[i] + t.r[i]) * 0.5 + y * fb;
        if (pass === 1) { wl[(i + d) % t.frames] += y * 0.25; wr[(i + d * 2) % t.frames] += y * 0.25; }
      }
    }
  }
  for (let i = 0; i < t.frames; i++) { t.l[i] += wl[i] * mix; t.r[i] += wr[i] * mix; }
}

// Bring the piece to a steady loudness, peaking just under full scale.
function finish(t, name) {
  let peak = 0;
  for (let i = 0; i < t.frames; i++) peak = Math.max(peak, Math.abs(t.l[i]), Math.abs(t.r[i]));
  const g = 0.89 / peak, data = Buffer.alloc(t.frames * 4);
  for (let i = 0; i < t.frames; i++) {
    data.writeInt16LE(Math.round(Math.max(-1, Math.min(1, t.l[i] * g)) * 32767), i * 4);
    data.writeInt16LE(Math.round(Math.max(-1, Math.min(1, t.r[i] * g)) * 32767), i * 4 + 2);
  }
  const h = Buffer.alloc(44);
  h.write('RIFF', 0); h.writeUInt32LE(36 + data.length, 4); h.write('WAVE', 8); h.write('fmt ', 12); h.writeUInt32LE(16, 16);
  h.writeUInt16LE(1, 20); h.writeUInt16LE(2, 22); h.writeUInt32LE(RATE, 24); h.writeUInt32LE(RATE * 4, 28); h.writeUInt16LE(4, 32); h.writeUInt16LE(16, 34);
  h.write('data', 36); h.writeUInt32LE(data.length, 40);
  fs.writeFileSync(path.join(out, `${name}.wav`), Buffer.concat([h, data]));
  console.log(`${name}: ${(t.frames / RATE).toFixed(1)}s`);
}

// A chord as MIDI notes: root, then intervals above it.
const chord = (root, octave, intervals) => intervals.map(i => midi(root, octave) + i);
const MAJ7 = [0, 4, 7, 11], MIN7 = [0, 3, 7, 10], MAJ = [0, 4, 7], MIN = [0, 3, 7], ADD9 = [0, 4, 7, 14], SUS2 = [0, 2, 7];

// ---------------------------------------------------------------- The pieces

// Calm: a warm pad under a slow plucked arpeggio. 72 bpm, 8 bars.
function calm() {
  const beat = 60 / 72, bar = beat * 4, prog = [['C', MAJ7], ['A', MIN7], ['F', MAJ7], ['G', ADD9]];
  const t = track(bar * 16);
  for (let b = 0; b < 16; b++) {
    const [root, q] = prog[b % 4], start = b * bar;
    add(t, start, pad(chord(root, 3, q).map(hz), bar, 1.4, 1.8, 0.06), 0.5);
    const notes = chord(root, 4, q);
    [0, 1, 2, 3, 2, 1, 3, 2].forEach((k, s) => add(t, start + s * beat / 2, pluck(hz(notes[k % notes.length]), 2.5, 0.35), 0.35, s % 2 ? 0.4 : -0.4));
    add(t, start, bass(hz(midi(root, 2)), bar), 0.4);
  }
  reverb(t, 0.35);
  return t;
}

// Upbeat: bright plucks, bass, kick, hats and claps. 118 bpm.
function upbeat() {
  const beat = 60 / 118, bar = beat * 4, prog = [['G', MAJ], ['D', MAJ], ['E', MIN], ['C', ADD9]];
  const t = track(bar * 16);
  for (let b = 0; b < 16; b++) {
    const [root, q] = prog[b % 4], start = b * bar, notes = chord(root, 4, q);
    add(t, start, pad(chord(root, 3, q).map(hz), bar, 0.3, 0.5, 0.1), 0.22);
    for (let s = 0; s < 8; s++) {
      if (s === 3 || s === 7) continue;
      add(t, start + s * beat / 2, pluck(hz(notes[(s * 2) % notes.length] + (s % 3 === 2 ? 12 : 0)), 0.8, 0.7), 0.3, s % 2 ? 0.5 : -0.5);
    }
    [0, 1.5, 2, 3.5].forEach(o => add(t, start + o * beat, bass(hz(midi(root, 2)), beat * 0.9), 0.55));
    for (let k = 0; k < 4; k++) add(t, start + k * beat, kick(), 0.8);
    [1, 3].forEach(k => add(t, start + k * beat, clap(), 0.35));
    for (let k = 0; k < 8; k++) add(t, start + k * beat / 2, hat(), k % 2 ? 0.22 : 0.12, 0.3);
  }
  reverb(t, 0.18);
  return t;
}

// Corporate: piano-like chords on the off-beats, bass and light drums. 100 bpm.
function corporate() {
  const beat = 60 / 100, bar = beat * 4, prog = [['D', ADD9], ['B', MIN7], ['G', MAJ7], ['A', SUS2]];
  const t = track(bar * 16);
  for (let b = 0; b < 16; b++) {
    const [root, q] = prog[b % 4], start = b * bar, notes = chord(root, 4, q);
    for (let s = 0; s < 8; s++) {
      const at = start + s * beat / 2;
      if (s % 2 === 1 || s === 0) notes.forEach((n, k) => add(t, at + k * 0.008, keys(hz(n), beat * 1.5), 0.18, (k - 1.5) * 0.2));
    }
    add(t, start, pad(chord(root, 3, q).map(hz), bar, 0.8, 1.2, 0.05), 0.25);
    [0, 2, 2.5].forEach(o => add(t, start + o * beat, bass(hz(midi(root, 2)), beat * 1.4), 0.5));
    [0, 2].forEach(k => add(t, start + k * beat, kick(1.3), 0.6));
    for (let k = 0; k < 8; k++) add(t, start + k * beat / 2, hat(), k % 2 ? 0.14 : 0.08, -0.2);
    add(t, start + 3 * beat, clap(), 0.18);
  }
  reverb(t, 0.25);
  return t;
}

// Cinematic: swelling strings, low pulses and soft drums, in D minor. 80 bpm.
function cinematic() {
  const beat = 60 / 80, bar = beat * 4, prog = [['D', MIN], ['A#', MAJ], ['F', MAJ], ['C', MAJ]];
  const t = track(bar * 16);
  for (let b = 0; b < 16; b++) {
    const [root, q] = prog[b % 4], start = b * bar;
    add(t, start, pad(chord(root, 3, q).map(hz), bar, 2.2, 2.2, 0.05), 0.55, -0.2);
    add(t, start, pad(chord(root, 4, q).map(hz), bar, 2.6, 2.4, 0.035), 0.3, 0.3);
    for (let k = 0; k < 8; k++) add(t, start + k * beat / 2, bass(hz(midi(root, 1) + 12), beat * 0.45), 0.35);
    if (b >= 4) {
      add(t, start, kick(1.8), 0.7);
      add(t, start + 2.5 * beat, kick(1.8), 0.45);
    }
    if (b % 4 === 3) add(t, start + 3.5 * beat, noise(0.9, 3, 0.3), 0.12);
  }
  reverb(t, 0.45);
  return t;
}

for (const [name, make] of Object.entries({ calm, upbeat, corporate, cinematic })) finish(make(), name);
