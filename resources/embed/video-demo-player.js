// <video-demo-player>: a published how-to video in any frontend (Vue, React,
// Svelte, plain HTML), from a Laravel app's video library. Load this script
// from the library, then use the element:
//
//   <script src="https://your-app.com/help/videos/embed.js" defer></script>
//   <video-demo-player slug="post-a-job"></video-demo-player>
//
// It plays the version recorded for the screen it is on (phone, tablet,
// desktop or TV), in the page's language when there is a version in it, with
// subtitles when the video has a voice-over. Attributes: slug (required),
// device (always this version), language (instead of the page's), library
// (the library's address, when the script comes from somewhere else).
// It renders nothing for a video that isn't published, or isn't public.
//
//   <video-demo-help></video-demo-help>
//
// lists the public videos that show the page it is on (path="/jobs/new" for
// another), behind a "Videos for this page" button, and plays the one chosen.
// It renders nothing on a page no video shows.
(() => {
  if (customElements.get('video-demo-player')) return;

  // The library the script was loaded from, such as https://your-app.com/help/videos.
  const script = document.currentScript;
  const defaultLibrary = script && script.src ? script.src.replace(/\/embed\.js(\?.*)?$/, '') : '';

  // The same choice as the Blade player: the version closest to this screen.
  const pick = (sources, forced) => {
    if (forced && sources[forced]) return forced;
    const w = window.innerWidth;
    const wanted = w < 600 ? ['mobile', 'tablet', 'desktop', 'tv'] : w < 1024 ? ['tablet', 'mobile', 'desktop', 'tv'] : w >= 1800 ? ['tv', 'desktop', 'tablet', 'mobile'] : ['desktop', 'tv', 'tablet', 'mobile'];
    return wanted.find(d => sources[d]) || Object.keys(sources)[0];
  };

  class VideoDemoPlayer extends HTMLElement {
    static get observedAttributes() { return ['slug', 'device', 'language', 'library']; }

    connectedCallback() { this.load(); }

    attributeChangedCallback(name, before, after) {
      if (this.isConnected && before !== null && before !== after) this.load();
    }

    async load() {
      const slug = this.getAttribute('slug');
      const library = (this.getAttribute('library') || defaultLibrary).replace(/\/$/, '');
      if (!slug || !library) return;
      const language = this.getAttribute('language') || document.documentElement.lang || '';
      const root = this.shadowRoot || this.attachShadow({ mode: 'open' });

      let video;
      try {
        const res = await fetch(`${library}/${encodeURIComponent(slug)}.json${language ? `?lang=${encodeURIComponent(language)}` : ''}`);
        if (!res.ok) { root.replaceChildren(); return; }
        video = await res.json();
      } catch {
        root.replaceChildren();
        return;
      }

      const device = pick(video.sources, this.getAttribute('device'));
      const source = video.sources[device];
      const style = document.createElement('style');
      style.textContent = ':host{display:block}video{display:block;width:100%;height:auto;border-radius:12px;background:#171717}';
      const el = document.createElement('video');
      el.controls = true;
      el.preload = 'metadata';
      el.playsInline = true;
      el.poster = source.poster || '';
      el.src = source.video;
      el.title = video.title;
      el.setAttribute('aria-label', video.title);
      el.style.aspectRatio = `${source.width}/${source.height}`;
      if (source.subtitles) {
        const track = document.createElement('track');
        track.kind = 'subtitles';
        track.src = source.subtitles;
        track.srclang = video.language || 'en';
        track.label = 'Subtitles';
        el.append(track);
      }
      root.replaceChildren(style, el);
      // A play and each quarter reached, once per page view, when the app counts them.
      if (video.stats && navigator.sendBeacon) {
        const sent = new Set();
        const send = event => {
          if (sent.has(event)) return;
          sent.add(event);
          const body = new FormData();
          body.append('event', event);
          navigator.sendBeacon(video.stats, body);
        };
        el.addEventListener('play', () => send('play'));
        el.addEventListener('timeupdate', () => {
          const done = el.duration ? el.currentTime / el.duration : 0;
          [25, 50, 75].forEach(q => { if (done >= q / 100) send(String(q)); });
        });
        el.addEventListener('ended', () => send('100'));
      }
      this.dispatchEvent(new CustomEvent('video-demo:ready', { detail: video }));
    }
  }

  customElements.define('video-demo-player', VideoDemoPlayer);

  const minutes = s => `${Math.floor((s || 0) / 60)}:${String(Math.round(s || 0) % 60).padStart(2, '0')}`;

  class VideoDemoHelp extends HTMLElement {
    static get observedAttributes() { return ['path', 'label', 'library']; }

    connectedCallback() {
      this.load();
      // Single-page apps change pages without loading one.
      this.onRoute = () => { if (!this.hasAttribute('path')) this.load(); };
      window.addEventListener('popstate', this.onRoute);
    }

    disconnectedCallback() { window.removeEventListener('popstate', this.onRoute); }

    attributeChangedCallback(name, before, after) {
      if (this.isConnected && before !== null && before !== after) this.load();
    }

    async load() {
      const library = (this.getAttribute('library') || defaultLibrary).replace(/\/$/, '');
      if (!library) return;
      const path = this.getAttribute('path') || location.pathname;
      const language = document.documentElement.lang || '';
      const root = this.shadowRoot || this.attachShadow({ mode: 'open' });

      let videos = [];
      try {
        const res = await fetch(`${library}/for-page.json?path=${encodeURIComponent(path)}${language ? `&lang=${encodeURIComponent(language)}` : ''}`);
        videos = res.ok ? (await res.json()).videos : [];
      } catch {}
      if (!videos.length) { root.replaceChildren(); return; }

      const style = document.createElement('style');
      style.textContent = ':host{display:block;font:inherit;color:inherit}details{border:1px solid color-mix(in srgb,currentColor 20%,transparent);border-radius:12px;padding:10px 14px}summary{cursor:pointer;font-weight:600}ul{list-style:none;margin:10px 0 0;padding:0}li+li{border-top:1px solid color-mix(in srgb,currentColor 12%,transparent)}button{all:unset;box-sizing:border-box;cursor:pointer;display:flex;gap:10px;width:100%;padding:8px 2px}button:hover .title,button[aria-pressed=true] .title{text-decoration:underline;text-underline-offset:3px}button:focus-visible{outline:2px solid currentColor;outline-offset:2px}.time{opacity:.65;font-variant-numeric:tabular-nums}video-demo-player{margin-top:10px}';
      const details = document.createElement('details');
      const summary = document.createElement('summary');
      summary.textContent = `${this.getAttribute('label') || 'Videos for this page'} (${videos.length})`;
      const list = document.createElement('ul');
      const stage = document.createElement('div');
      videos.forEach(video => {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-pressed', 'false');
        const time = document.createElement('span');
        time.className = 'time';
        time.textContent = minutes(video.duration);
        const title = document.createElement('span');
        title.className = 'title';
        title.textContent = video.title;
        button.append(time, title);
        button.addEventListener('click', () => {
          list.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
          const player = document.createElement('video-demo-player');
          player.setAttribute('slug', video.slug);
          player.setAttribute('library', library);
          stage.replaceChildren(player);
        });
        item.append(button);
        list.append(item);
      });
      details.append(summary, list, stage);
      root.replaceChildren(style, details);
    }
  }

  customElements.define('video-demo-help', VideoDemoHelp);
})();
