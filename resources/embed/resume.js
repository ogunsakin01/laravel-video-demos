// Remembers where a viewer got to in a video, in their own browser
// (localStorage), and starts there when they come back to it, with
// "Continues from 0:42 · Start over" under the video. Nothing is sent
// anywhere. The Blade player and <video-demo-player> both use it:
//
//   VideoDemoResume.watch(videoElement)
//
// Each file has its own place, so another version of the video (mobile, or
// a new recording) starts at the beginning. A place near the start or the
// end, or older than 30 days, isn't kept.
((root) => {
  // Places this close to the start or the end aren't kept, in seconds.
  const EDGE_SECONDS = 3;
  // How often the place is saved while the video plays, in seconds of video.
  const SAVE_EVERY_SECONDS = 2;
  // A place older than this is forgotten.
  const KEEP_MS = 30 * 24 * 60 * 60 * 1000;

  // The key a video's place is kept under: its file, without a query (a signed address changes).
  const keyFor = src => {
    if (!src) return null;
    try {
      return 'video-demo:at:' + new URL(src, 'http://localhost').pathname;
    } catch {
      return null;
    }
  };

  // Where a video starts, from its saved place: seconds, or null for the beginning.
  const startAt = (saved, duration, now) => {
    if (!saved || typeof saved.t !== 'number' || typeof saved.at !== 'number' || now - saved.at > KEEP_MS) return null;
    if (!(duration > 0) || saved.t < EDGE_SECONDS || saved.t > duration - EDGE_SECONDS) return null;
    return saved.t;
  };

  const minutes = s => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

  // The line under the video. Its colour and font come from the page around it.
  const showNote = (video, seconds, startOver) => {
    const doc = video.ownerDocument;
    const note = doc.createElement('div');
    note.className = 'vd-resume';
    note.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px 10px;align-items:baseline;margin-top:6px;font:inherit;font-size:13px;line-height:1.4';
    const text = doc.createElement('span');
    text.textContent = `Continues from ${minutes(seconds)}`;
    text.style.opacity = '0.75';
    const button = doc.createElement('button');
    button.type = 'button';
    button.textContent = 'Start over';
    button.style.cssText = 'padding:0;border:0;background:none;color:inherit;font:inherit;text-decoration:underline;text-underline-offset:3px;cursor:pointer';
    button.addEventListener('click', startOver);
    note.append(text, button);
    video.after(note);
    return () => note.remove();
  };

  // localStorage can be missing, full or refused (a private window, blocked site data).
  const localStore = () => {
    try {
      return root.localStorage || null;
    } catch {
      return null;
    }
  };
  const read = (storage, key) => {
    try {
      return JSON.parse(storage.getItem(key));
    } catch {
      return null;
    }
  };
  const write = (storage, key, value) => {
    try {
      storage.setItem(key, JSON.stringify(value));
    } catch {}
  };
  const forget = (storage, key) => {
    try {
      storage.removeItem(key);
    } catch {}
  };

  // Watch a video on the page. Returns a function that stops watching it.
  const watch = (video, { storage = localStore(), now = () => Date.now(), note = showNote } = {}) => {
    if (!storage) return () => {};
    let key = null;
    let lastSaved = -Infinity;
    let hideNote = null;
    // Only a video played here changes its place: opening the page and leaving it changes nothing.
    let played = false;
    // Set while our own seek is under way, so its seeking event isn't taken for the viewer's.
    let seekingOurselves = false;

    const clearNote = () => {
      if (hideNote) hideNote();
      hideNote = null;
    };

    const save = () => {
      if (!key || !played) return;
      const t = video.currentTime;
      if (video.ended || t < EDGE_SECONDS || (video.duration > 0 && t > video.duration - EDGE_SECONDS)) {
        forget(storage, key);
      } else {
        write(storage, key, { t: Math.round(t * 10) / 10, at: now() });
      }
      lastSaved = t;
    };

    const startOver = () => {
      clearNote();
      forget(storage, key);
      video.currentTime = 0;
      Promise.resolve().then(() => video.play()).catch(() => {});
    };

    // Once for each file: the first, and another version switched to.
    const restore = () => {
      const next = keyFor(video.currentSrc || video.src);
      if (next === key) return;
      clearNote();
      key = next;
      // With preload="none" the file only loads once the viewer presses play.
      played = !video.paused;
      lastSaved = -Infinity;
      const t = key ? startAt(read(storage, key), video.duration, now()) : null;
      if (t === null) return;
      seekingOurselves = true;
      video.currentTime = t;
      // A server that doesn't answer range requests (PHP's own, say) can't be sought in: start at the beginning, without a note.
      if (Math.abs(video.currentTime - t) > 0.5) {
        seekingOurselves = false;
        return;
      }
      lastSaved = t;
      hideNote = note(video, t, startOver);
    };

    const listeners = [
      ['loadedmetadata', restore],
      ['play', () => { played = true; }],
      ['timeupdate', () => { if (Math.abs(video.currentTime - lastSaved) >= SAVE_EVERY_SECONDS) save(); }],
      ['pause', save],
      ['ended', () => { clearNote(); save(); }],
      // The viewer moving to another place makes the note out of date.
      ['seeking', () => { if (seekingOurselves) seekingOurselves = false; else clearNote(); }],
    ];
    listeners.forEach(([name, fn]) => video.addEventListener(name, fn));
    const view = video.ownerDocument && video.ownerDocument.defaultView;
    if (view) view.addEventListener('pagehide', save);
    if (video.readyState >= 1) restore();

    return () => {
      clearNote();
      listeners.forEach(([name, fn]) => video.removeEventListener(name, fn));
      if (view) view.removeEventListener('pagehide', save);
    };
  };

  const api = { watch, startAt, keyFor, EDGE_SECONDS, SAVE_EVERY_SECONDS, KEEP_MS };
  if (typeof module === 'object' && module && module.exports) {
    module.exports = api;
  } else {
    // The Blade player and embed.js can both be on one page: the first one wins.
    root.VideoDemoResume = root.VideoDemoResume || api;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
