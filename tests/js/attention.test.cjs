// The rules that pause a video while the viewer is away (resources/embed/attention.js),
// with a fake clock and a fake video. Run with: node --test tests/js
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

// Run as the browser runs it, whatever package.json a folder above says about .js files.
const load = () => {
  const module = { exports: {} };
  new Function('module', fs.readFileSync(path.join(__dirname, '../../resources/embed/attention.js'), 'utf8'))(module);
  return module.exports;
};
const { AttentionTracker, OUT_OF_VIEW_MS } = load();

// A video and the page around it, recording what the tracker asked for.
const setUp = ({ refusePlay = false, pictureInPicture = false } = {}) => {
  const calls = [];
  const events = [];
  const timers = new Map();
  let nextTimer = 1;
  const video = { paused: false, pip: pictureInPicture };

  const tracker = new AttentionTracker({
    play: () => {
      calls.push('play');
      if (refusePlay) return Promise.reject(new Error('NotAllowedError'));
      video.paused = false;
      tracker.played();
      return Promise.resolve();
    },
    pause: () => {
      calls.push('pause');
      video.paused = true;
    },
    playing: () => !video.paused,
    pictureInPicture: () => video.pip,
    emit: (name, detail) => events.push([name, detail]),
    setTimer: (fn, ms) => {
      timers.set(nextTimer, { fn, ms });
      return nextTimer++;
    },
    clearTimer: id => timers.delete(id),
  });

  return {
    tracker,
    video,
    calls,
    events,
    // Run the timers that are due after ms.
    wait(ms) {
      for (const [id, timer] of [...timers]) {
        if (timer.ms <= ms) {
          timers.delete(id);
          timer.fn();
        }
      }
    },
    // The browser delivering the pause event that our own pause() queued.
    deliverPause: () => tracker.paused(),
    // The viewer pressing pause.
    viewerPauses() {
      video.paused = true;
      tracker.paused();
    },
    viewerPlays() {
      video.paused = false;
      tracker.played();
    },
  };
};

// Let the play() promise settle.
const settle = () => new Promise(resolve => setImmediate(resolve));

test('pauses on another tab and plays again on coming back', async () => {
  const t = setUp();
  t.tracker.tabHidden(true);
  t.deliverPause();
  assert.deepStrictEqual(t.calls, ['pause']);
  assert.deepStrictEqual(t.events, [['auto-pause', { reason: 'hidden' }]]);

  t.tracker.tabHidden(false);
  await settle();
  assert.deepStrictEqual(t.calls, ['pause', 'play']);
  assert.deepStrictEqual(t.events.at(-1), ['auto-resume', {}]);
});

test('pauses once out of view for a while, not while scrolling past', async () => {
  const t = setUp();
  t.tracker.viewChanged(false);
  t.wait(OUT_OF_VIEW_MS - 1);
  t.tracker.viewChanged(true);
  t.wait(OUT_OF_VIEW_MS);
  assert.deepStrictEqual(t.calls, []);

  t.tracker.viewChanged(false);
  t.wait(OUT_OF_VIEW_MS);
  t.deliverPause();
  assert.deepStrictEqual(t.events, [['auto-pause', { reason: 'out-of-view' }]]);

  t.tracker.viewChanged(true);
  await settle();
  assert.deepStrictEqual(t.calls, ['pause', 'play']);
});

test('a video the viewer paused stays paused', async () => {
  const t = setUp();
  t.viewerPauses();
  t.tracker.tabHidden(true);
  t.tracker.tabHidden(false);
  await settle();
  assert.deepStrictEqual(t.calls, []);
  assert.deepStrictEqual(t.events, []);
});

test('pausing, seeking or finishing while away means no playing again', async () => {
  for (const act of ['pause', 'seek', 'end']) {
    const t = setUp();
    t.tracker.tabHidden(true);
    t.deliverPause();
    if (act === 'seek') t.tracker.sought();
    // Pausing again and the video ending both reach the tracker as a pause event.
    else t.tracker.paused();
    t.tracker.tabHidden(false);
    await settle();
    assert.deepStrictEqual(t.calls, ['pause'], act);
  }
});

test('the viewer playing again starts watching again', async () => {
  const t = setUp();
  t.viewerPauses();
  t.viewerPlays();
  t.tracker.tabHidden(true);
  t.deliverPause();
  t.tracker.tabHidden(false);
  await settle();
  assert.deepStrictEqual(t.calls, ['pause', 'play']);
});

test('plays again only when both the tab and the view are back', async () => {
  const t = setUp();
  t.tracker.viewChanged(false);
  t.wait(OUT_OF_VIEW_MS);
  t.deliverPause();
  t.tracker.tabHidden(true);
  t.tracker.tabHidden(false);
  await settle();
  assert.deepStrictEqual(t.calls, ['pause']);

  t.tracker.viewChanged(true);
  await settle();
  assert.deepStrictEqual(t.calls, ['pause', 'play']);
});

test('switching tab while the out-of-view wait runs pauses only once', async () => {
  const t = setUp();
  t.tracker.viewChanged(false);
  t.tracker.tabHidden(true);
  t.deliverPause();
  t.wait(OUT_OF_VIEW_MS);
  assert.deepStrictEqual(t.calls, ['pause']);
  assert.deepStrictEqual(t.events, [['auto-pause', { reason: 'hidden' }]]);
});

test('a new file (another version) starts afresh', async () => {
  const t = setUp();
  t.tracker.tabHidden(true);
  t.deliverPause();
  t.tracker.reset();
  t.tracker.tabHidden(false);
  await settle();
  assert.deepStrictEqual(t.calls, ['pause']);
});

test('a video in picture-in-picture keeps playing', () => {
  const t = setUp({ pictureInPicture: true });
  t.tracker.tabHidden(true);
  t.tracker.viewChanged(false);
  t.wait(OUT_OF_VIEW_MS);
  assert.deepStrictEqual(t.calls, []);
});

test('a browser that refuses to play leaves it paused, quietly', async () => {
  const t = setUp({ refusePlay: true });
  t.tracker.tabHidden(true);
  t.deliverPause();
  t.tracker.tabHidden(false);
  await settle();
  assert.deepStrictEqual(t.calls, ['pause', 'play']);
  assert.deepStrictEqual(t.events, [['auto-pause', { reason: 'hidden' }]]);
});

test('a paused video is left alone', async () => {
  const t = setUp();
  t.video.paused = true;
  t.tracker.tabHidden(true);
  t.tracker.tabHidden(false);
  await settle();
  assert.deepStrictEqual(t.calls, []);
});

test('its own timers work the way browsers call them', async () => {
  // Browsers refuse setTimeout called as another object's method ("Illegal invocation").
  const real = globalThis.setTimeout;
  globalThis.setTimeout = function (fn, ms) {
    if (this !== undefined && this !== globalThis) throw new TypeError('Illegal invocation');
    return real(fn, ms);
  };
  try {
    const calls = [];
    let paused = false;
    const tracker = new AttentionTracker({ play: () => {}, pause: () => { calls.push('pause'); paused = true; }, playing: () => !paused, outOfViewMs: 5 });
    tracker.viewChanged(false);
    await new Promise(resolve => real(resolve, 30));
    assert.deepStrictEqual(calls, ['pause']);
  } finally {
    globalThis.setTimeout = real;
  }
});
