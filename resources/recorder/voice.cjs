// Speaks a video's voice-over lines with a local voice (Kokoro), on this
// machine, at no cost. The model is loaded once for all the lines.
//   node voice.cjs <job.json>
// The job gives the voice, where the model is cached, and the lines:
// { voice, cacheDir, lines: [{ text, out }] }. Each line is written as a WAV
// file to its "out". Writes result.json next to the job: { ok, error }.
// With { warmup: true } it only downloads the model, for the install command.
const fs = require('fs');
const path = require('path');

const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const resultFile = path.join(path.dirname(process.argv[2]), 'result.json');
// Node exits by itself once done: calling process.exit() while the speech
// engine's threads are still running crashes it ("mutex lock failed").
const finish = result => { fs.writeFileSync(resultFile, JSON.stringify(result)); };

(async () => {
  let KokoroTTS, env;
  try {
    ({ KokoroTTS } = require('kokoro-js'));
    ({ env } = require('@huggingface/transformers'));
  } catch {
    return finish({ ok: false, error: 'The local voice is not installed. Run php artisan video-demo:install-voice.' });
  }

  // Outside the project, so the model is never committed and is shared by every project.
  env.cacheDir = job.cacheDir;
  const tts = await KokoroTTS.from_pretrained('onnx-community/Kokoro-82M-v1.0-ONNX', { dtype: 'q8', device: 'cpu' });
  try {
    if (job.warmup) return finish({ ok: true, voices: Object.keys(tts.voices) });
    if (!tts.voices[job.voice]) return finish({ ok: false, error: `There is no local voice [${job.voice}].` });

    for (const line of job.lines) {
      const audio = await tts.generate(line.text, { voice: job.voice });
      fs.mkdirSync(path.dirname(line.out), { recursive: true });
      audio.save(line.out);
    }
    finish({ ok: true });
  } finally {
    await tts.model.dispose();
  }
})().catch(e => finish({ ok: false, error: String(e.message || e).split('\n')[0] }));
