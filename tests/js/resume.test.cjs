// Starting a video where the viewer left it (resources/embed/resume.js), with
// a fake video, storage and clock. Run with: node --test tests/js/*.test.cjs
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

// Run as the browser runs it, whatever package.json a folder above says about .js files.
const load = () => {
  const module = { exports: {} };
  new Function('module', fs.readFileSync(path.join(__dirname, '../../resources/embed/resume.js'), 'utf8'))(module);
  return module.exports;
};
const { watch, startAt, keyFor, KEEP_MS } = load();

const DAY = 24 * 60 * 60 * 1000;
const SRC = 'https://app.test/storage/video-demo/post-a-job-v2-desktop.mp4';

// A video element, as far as the script uses one. Seeking fires "seeking" a moment later, as browsers do.
class FakeVideo extends EventTarget {
  constructor(src = SRC) {
    super();
    Object.assign(this, { currentSrc: src, src, duration: 60, readyState: 0, ended: false, paused: true, plays: 0, seekable: true, _t: 0 });
  }
  get currentTime() { return this._t; }
  set currentTime(t) {
    // A file from a server that doesn't answer range requests stays where it is.
    if (!this.seekable) return;
    this._t = t;
    queueMicrotask(() => this.fire('seeking'));
  }
  play() { this.plays++; this.fire('play'); return Promise.resolve(); }
  fire(name) { this.dispatchEvent(new Event(name)); }
  // The video playing on to a place.
  playTo(t) { this._t = t; this.fire('timeupdate'); }
}

class FakeStorage {
  constructor(entries = {}) { this.entries = { ...entries }; }
  getItem(key) { return key in this.entries ? this.entries[key] : null; }
  setItem(key, value) { this.entries[key] = String(value); }
  removeItem(key) { delete this.entries[key]; }
}

const KEY = keyFor(SRC);
const saved = (t, at = 1000) => ({ [KEY]: JSON.stringify({ t, at }) });
const settle = () => new Promise(resolve => setImmediate(resolve));

// Watch a fake video; notes shown are kept in notes, each with its startOver and whether it's still up.
const setUp = ({ entries = {}, now = 1000, video = new FakeVideo() } = {}) => {
  const storage = new FakeStorage(entries);
  const notes = [];
  const stop = watch(video, {
    storage,
    now: () => now,
    note: (_video, seconds, startOver) => {
      const note = { seconds, startOver, shown: true };
      notes.push(note);
      return () => { note.shown = false; };
    },
  });
  return { video, storage, notes, stop };
};

test('a place is kept while playing and on pausing', () => {
  const { video, storage } = setUp();
  video.fire('loadedmetadata');
  video.fire('play');
  video.playTo(1);
  assert.strictEqual(storage.getItem(KEY), null, 'too near the start');
  video.playTo(12.34);
  assert.deepStrictEqual(JSON.parse(storage.getItem(KEY)), { t: 12.3, at: 1000 });
  video.playTo(13);
  assert.strictEqual(JSON.parse(storage.getItem(KEY)).t, 12.3, 'saved every two seconds, not on every update');
  video.fire('pause');
  assert.strictEqual(JSON.parse(storage.getItem(KEY)).t, 13);
});

test('opening the page and leaving it without playing changes nothing', () => {
  const { video, storage } = setUp({ entries: saved(42) });
  video.seekable = false;
  video.fire('loadedmetadata');
  video.fire('pause');
  assert.strictEqual(JSON.parse(storage.getItem(KEY)).t, 42);
});

test('the next time, it starts there, with a note under it', async () => {
  const { video, notes } = setUp({ entries: saved(42) });
  video.fire('loadedmetadata');
  assert.strictEqual(video.currentTime, 42);
  assert.strictEqual(notes.length, 1);
  assert.strictEqual(notes[0].seconds, 42);
  // Our own seek doesn't take the note away.
  await settle();
  assert.strictEqual(notes[0].shown, true);
});

test('a file that can\'t be sought in starts at the beginning, without a note', () => {
  const video = new FakeVideo();
  video.seekable = false;
  const { notes } = setUp({ entries: saved(42), video });
  video.fire('loadedmetadata');
  assert.strictEqual(video.currentTime, 0);
  assert.strictEqual(notes.length, 0);
});

test('a video that loads only on play (preload="none") keeps its place too', () => {
  const { video, storage } = setUp();
  video.paused = false;
  video.fire('play');
  video.fire('loadedmetadata');
  video.playTo(20);
  assert.strictEqual(JSON.parse(storage.getItem(KEY)).t, 20);
});

test('a video already loaded starts there at once', () => {
  const video = new FakeVideo();
  video.readyState = 1;
  setUp({ entries: saved(42), video });
  assert.strictEqual(video.currentTime, 42);
});

test('start over goes to the beginning, plays and forgets the place', async () => {
  const { video, notes, storage } = setUp({ entries: saved(42) });
  video.fire('loadedmetadata');
  notes[0].startOver();
  await settle();
  assert.strictEqual(video.currentTime, 0);
  assert.strictEqual(video.plays, 1);
  assert.strictEqual(notes[0].shown, false);
  assert.strictEqual(storage.getItem(KEY), null);
});

test('the viewer moving elsewhere takes the note away', async () => {
  const { video, notes } = setUp({ entries: saved(42) });
  video.fire('loadedmetadata');
  await settle();
  video.currentTime = 5;
  await settle();
  assert.strictEqual(notes[0].shown, false);
});

test('finishing the video forgets the place', () => {
  const { video, storage, notes } = setUp({ entries: saved(42) });
  video.fire('loadedmetadata');
  video.fire('play');
  video._t = 60;
  video.ended = true;
  video.fire('pause');
  video.fire('ended');
  assert.strictEqual(storage.getItem(KEY), null);
  assert.strictEqual(notes[0].shown, false);
});

test('places near the end, old ones and broken ones start at the beginning', () => {
  assert.strictEqual(startAt({ t: 42, at: 0 }, 60, 1000), 42);
  assert.strictEqual(startAt({ t: 58, at: 0 }, 60, 1000), null, 'near the end');
  assert.strictEqual(startAt({ t: 2, at: 0 }, 60, 1000), null, 'near the start');
  assert.strictEqual(startAt({ t: 42, at: 0 }, 60, KEEP_MS + 1), null, 'older than 30 days');
  assert.strictEqual(startAt({ t: 42, at: 0 }, NaN, 1000), null, 'length not known');
  assert.strictEqual(startAt(null, 60, 1000), null);
  assert.strictEqual(startAt({ t: '42' }, 60, 1000), null);

  const { video, notes } = setUp({ entries: { [KEY]: 'not json' } });
  video.fire('loadedmetadata');
  assert.strictEqual(video.currentTime, 0);
  assert.strictEqual(notes.length, 0);
});

test('each file has its own place: another version starts afresh', () => {
  assert.strictEqual(keyFor(SRC + '?X-Amz-Signature=abc'), KEY, 'a signed address is the same file');
  assert.notStrictEqual(keyFor(SRC.replace('desktop', 'mobile')), KEY);
  assert.notStrictEqual(keyFor(SRC.replace('-v2-', '-v3-')), KEY);

  const { video, notes } = setUp({ entries: saved(42) });
  video.fire('loadedmetadata');
  // Switched to the mobile version, which has no place kept.
  video.currentSrc = video.src = SRC.replace('desktop', 'mobile');
  video._t = 0;
  video.fire('loadedmetadata');
  assert.strictEqual(video.currentTime, 0);
  assert.strictEqual(notes[0].shown, false);
  assert.strictEqual(notes.length, 1);
});

test('without storage it does nothing, quietly', () => {
  const video = new FakeVideo();
  const refusing = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('QuotaExceededError'); }, removeItem() { throw new Error('SecurityError'); } };
  watch(video, { storage: refusing, note: () => () => {} });
  video.fire('loadedmetadata');
  video.playTo(30);
  video.fire('pause');
  assert.strictEqual(video.currentTime, 30);
});

test('stopping takes the note away and stops saving', () => {
  const { video, notes, storage, stop } = setUp({ entries: saved(42) });
  video.fire('loadedmetadata');
  video.fire('play');
  stop();
  assert.strictEqual(notes[0].shown, false);
  video.playTo(50);
  video.fire('pause');
  assert.strictEqual(JSON.parse(storage.getItem(KEY)).t, 42);
});
