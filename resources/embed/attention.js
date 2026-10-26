// Pauses a playing video when the viewer leaves it, by switching to another
// tab or scrolling it mostly out of view, and plays it again from the same
// place when they come back. It asks for no permissions and sends nothing
// anywhere. The Blade player and <video-demo-player> both use it:
//
//   VideoDemoAttention.watch(videoElement)
//
// It fires video-demo:auto-pause (detail.reason: "hidden" or "out-of-view")
// and video-demo:auto-resume on the video, or on the element given as target.
// A video the viewer paused, sought in or finished is never played again by
// itself; the viewer's next play starts watching again.
((root) => {
  // Below this share of the player on screen, it is out of view.
  const IN_VIEW_RATIO = 0.25;
  // How long it must stay out of view before it pauses, so scrolling past doesn't.
  const OUT_OF_VIEW_MS = 1000;

  // The rules, apart from the page: what the viewer and the page do goes in,
  // play() and pause() come out. The page's own events are wired by watch().
  class AttentionTracker {
    // The default timers are wrapped: browsers refuse setTimeout called as another object's method.
    constructor({ play, pause, playing, pictureInPicture = () => false, emit = () => {}, outOfViewMs = OUT_OF_VIEW_MS, setTimer = (fn, ms) => setTimeout(fn, ms), clearTimer = id => clearTimeout(id) }) {
      Object.assign(this, { play, pause, playing, pictureInPicture, emit, outOfViewMs, setTimer, clearTimer });
      this.hidden = false;
      this.inView = true;
      // Why we paused the video ("hidden" or "out-of-view"), or null when we didn't.
      this.pausedFor = null;
      // Set while our own pause() is under way, so its pause event isn't taken for the viewer's.
      this.pausing = false;
      this.timer = null;
    }

    tabHidden(hidden) {
      this.hidden = hidden;
      hidden ? this.leave('hidden') : this.comeBack();
    }

    viewChanged(inView) {
      if (inView === this.inView) return;
      this.inView = inView;
      this.cancelTimer();
      if (inView) {
        this.comeBack();
      } else {
        this.timer = this.setTimer(() => {
          this.timer = null;
          if (!this.inView) this.leave('out-of-view');
        }, this.outOfViewMs);
      }
    }

    // The video's pause event: ours, or the viewer's (which also covers it ending).
    paused() {
      if (this.pausing) {
        this.pausing = false;
        return;
      }
      this.pausedFor = null;
    }

    // The video's play event: the viewer's, or ours on coming back.
    played() {
      this.pausedFor = null;
    }

    // The viewer moved to another place in the video while it was paused.
    sought() {
      this.pausedFor = null;
    }

    // A new file was loaded (another version, say): start afresh.
    reset() {
      this.cancelTimer();
      this.pausedFor = null;
      this.pausing = false;
    }

    leave(reason) {
      if (this.pausedFor || !this.playing() || this.pictureInPicture()) return;
      this.pausedFor = reason;
      this.pausing = true;
      this.pause();
      this.emit('auto-pause', { reason });
    }

    comeBack() {
      if (!this.pausedFor || this.hidden || !this.inView || this.playing()) return;
      this.pausedFor = null;
      // A browser may refuse to play; the video then stays paused for the viewer to play.
      Promise.resolve()
        .then(() => this.play())
        .then(() => this.emit('auto-resume', {}), () => {});
    }

    cancelTimer() {
      if (this.timer !== null) {
        this.clearTimer(this.timer);
        this.timer = null;
      }
    }
  }

  // Watch a video on the page. Returns a function that stops watching it.
  const watch = (video, { target = video } = {}) => {
    const doc = video.ownerDocument;
    const tracker = new AttentionTracker({
      play: () => video.play(),
      pause: () => video.pause(),
      playing: () => !video.paused && !video.ended,
      // Inside a shadow root (as in <video-demo-player>) the root knows, not the document.
      pictureInPicture: () => video.getRootNode().pictureInPictureElement === video,
      emit: (name, detail) => target.dispatchEvent(new CustomEvent(`video-demo:${name}`, { bubbles: true, composed: true, detail })),
    });

    const onVisibility = () => tracker.tabHidden(doc.hidden);
    const listeners = [
      ['pause', () => tracker.paused()],
      ['play', () => tracker.played()],
      ['seeking', () => tracker.sought()],
      ['emptied', () => tracker.reset()],
    ];
    doc.addEventListener('visibilitychange', onVisibility);
    listeners.forEach(([name, fn]) => video.addEventListener(name, fn));

    let observer = null;
    if (typeof IntersectionObserver === 'function') {
      observer = new IntersectionObserver(entries => entries.forEach(entry => tracker.viewChanged(entry.isIntersecting && entry.intersectionRatio >= IN_VIEW_RATIO)), { threshold: [0, IN_VIEW_RATIO, 1] });
      observer.observe(video);
    }

    return () => {
      tracker.reset();
      doc.removeEventListener('visibilitychange', onVisibility);
      listeners.forEach(([name, fn]) => video.removeEventListener(name, fn));
      if (observer) observer.disconnect();
    };
  };

  const api = { AttentionTracker, watch, IN_VIEW_RATIO, OUT_OF_VIEW_MS };
  if (typeof module === 'object' && module && module.exports) {
    module.exports = api;
  } else {
    // The Blade player and embed.js can both be on one page: the first one wins.
    root.VideoDemoAttention = root.VideoDemoAttention || api;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
