@php
  $accent = config('video-demo.ui.accent') ?: '#171717';
  // The default near-black accent turns light in dark mode; a custom colour is kept.
  $darkAccent = in_array(strtolower($accent), ['#171717', '#000', '#000000'], true) ? '#f5f4ef' : $accent;
  $darkAccentInk = $darkAccent === '#f5f4ef' ? '#171717' : '#ffffff';
@endphp
@if (config('video-demo.ui.font_url'))
  <link rel="preconnect" href="{{ parse_url(config('video-demo.ui.font_url'), PHP_URL_SCHEME).'://'.parse_url(config('video-demo.ui.font_url'), PHP_URL_HOST) }}" crossorigin>
  <link rel="stylesheet" href="{{ config('video-demo.ui.font_url') }}">
@endif
<style>
  .vd {
    --vd-paper: #faf8f3; --vd-card: #ffffff; --vd-muted: #f4f2ec;
    --vd-ink: #2b2f38; --vd-strong: #171717; --vd-muted-ink: #6b6f7b; --vd-faint-ink: #8b8e98;
    --vd-border: #e5e2dc; --vd-border-strong: #d4d1ca;
    --vd-accent: {{ $accent }}; --vd-accent-ink: #ffffff;
    --vd-ok: #008236; --vd-ok-bg: #eaf5ee; --vd-warn: #a65f00; --vd-warn-bg: #fbf3e4; --vd-bad: #b42318; --vd-bad-bg: #fcecea;
    --vd-dot: #d6d3cc;
    --vd-shadow: 0 1px 2px 0 rgb(0 0 0 / .04), 0 1px 2px -1px rgb(0 0 0 / .04);
    --vd-shadow-lg: 0 12px 32px -12px rgb(23 23 23 / .18);
    --vd-radius-sm: 6px; --vd-radius: 8px; --vd-radius-lg: 12px;
    --vd-font: 'Geist', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    --vd-mono: 'Geist Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
    color-scheme: light;
  }
  @media (prefers-color-scheme: dark) {
    .vd {
      --vd-paper: #141412; --vd-card: #1c1c1a; --vd-muted: #242421;
      --vd-ink: #e9e7e1; --vd-strong: #fafaf8; --vd-muted-ink: #a3a199; --vd-faint-ink: #85837c;
      --vd-border: #2e2d29; --vd-border-strong: #3c3b36;
      --vd-accent: {{ $darkAccent }}; --vd-accent-ink: {{ $darkAccentInk }};
      --vd-ok: #5fd08f; --vd-ok-bg: #16281d; --vd-warn: #f0b85a; --vd-warn-bg: #2d2416; --vd-bad: #ff8a7a; --vd-bad-bg: #321a17;
      --vd-dot: #34332e;
      --vd-shadow: 0 1px 2px 0 rgb(0 0 0 / .3);
      --vd-shadow-lg: 0 12px 32px -12px rgb(0 0 0 / .6);
      color-scheme: dark;
    }
  }
  .vd, .vd * { box-sizing: border-box; }
  body.vd { margin: 0; background: var(--vd-paper); color: var(--vd-ink); font: 14px/1.6 var(--vd-font); -webkit-font-smoothing: antialiased; }
  .vd a:not(.vd-btn) { color: inherit; }
  .vd :focus-visible { outline: 2px solid var(--vd-strong); outline-offset: 2px; }
  .vd-wrap { max-width: 1160px; margin: 0 auto; padding: 0 16px; }
  @media (min-width: 640px) { .vd-wrap { padding: 0 24px; } }

  /* Header */
  .vd-top { position: sticky; top: 0; z-index: 10; background: var(--vd-card); border-bottom: 1px solid var(--vd-border); }
  .vd-top .vd-wrap { display: flex; align-items: center; gap: 12px; min-height: 60px; }
  .vd-brand { display: flex; align-items: center; gap: 10px; font-size: 15px; font-weight: 600; letter-spacing: -.01em; color: var(--vd-strong); text-decoration: none; }
  .vd-brand img { height: 26px; }
  .vd-mark { width: 28px; height: 28px; border-radius: var(--vd-radius-sm); background: var(--vd-accent); color: var(--vd-accent-ink); display: grid; place-items: center; }
  .vd-tag { font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: .06em; padding: 3px 8px; border-radius: 99px; border: 1px solid var(--vd-border); color: var(--vd-muted-ink); }
  .vd-spacer { flex: 1; }
  .vd-nav { display: flex; gap: 8px; }
  .vd-brand { white-space: nowrap; min-width: 0; }
  .vd-tag { white-space: nowrap; }
  /* Phones: the name on top, the menu on its own row below, scrolling sideways if it must. */
  @media (max-width: 640px) {
    .vd-top .vd-wrap { flex-wrap: wrap; gap: 0 10px; min-height: 0; padding-top: 10px; }
    .vd-tag { display: none; }
    .vd-brand { font-size: 14px; overflow: hidden; text-overflow: ellipsis; flex: 0 1 auto; }
    .vd-spacer { display: none; }
    .vd-nav { flex: 1 0 100%; min-width: 0; overflow-x: auto; scrollbar-width: none; gap: 4px; padding: 8px 0 10px; margin: 0 -16px; padding-left: 16px; padding-right: 16px; }
    .vd-nav::-webkit-scrollbar { display: none; }
    .vd-nav .vd-btn { min-height: 32px; padding: 0 10px; font-size: 13px; flex: none; white-space: nowrap; }
  }

  /* Page heading strip, with a dot texture on the right (an SVG pattern, no gradients). */
  .vd-main { padding: 24px 0 72px; }
  .vd-hero { position: relative; overflow: hidden; background: var(--vd-card); border: 1px solid var(--vd-border); border-radius: var(--vd-radius-lg);
    padding: 22px 24px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; box-shadow: var(--vd-shadow); }
  .vd-hero::after { content: ""; position: absolute; top: 0; right: 0; bottom: 0; width: 38%; pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='22' height='22'%3E%3Ccircle cx='2' cy='2' r='1' fill='%23000'/%3E%3C/svg%3E");
    opacity: .14; }
  @media (prefers-color-scheme: dark) { .vd-hero::after { filter: invert(1); opacity: .12; } }
  .vd-hero > * { position: relative; z-index: 1; }
  .vd-hero > .vd-hero-art + * { flex: 1 1 260px; min-width: 0; }
  .vd-eyebrow { font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: .08em; color: var(--vd-muted-ink); margin: 0 0 4px; }
  .vd h1 { font-size: 26px; line-height: 1.2; letter-spacing: -.025em; font-weight: 600; color: var(--vd-strong); margin: 0; }
  @media (min-width: 640px) { .vd h1 { font-size: 30px; } }
  .vd h2 { font-size: 15px; font-weight: 600; letter-spacing: -.01em; color: var(--vd-strong); margin: 0 0 12px; }
  .vd-sub { color: var(--vd-muted-ink); margin: 6px 0 0; max-width: 62ch; }

  /* Surfaces */
  .vd-card { background: var(--vd-card); border: 1px solid var(--vd-border); border-radius: var(--vd-radius-lg); padding: 20px; box-shadow: var(--vd-shadow); }
  .vd-card-flush { padding: 0; overflow: hidden; }
  .vd-stack > * + * { margin-top: 16px; }
  .vd-stack-sm > * + * { margin-top: 8px; }
  .vd-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); }
  .vd-split { display: grid; gap: 20px; grid-template-columns: minmax(0, 1fr) 340px; align-items: start; }
  @media (max-width: 900px) { .vd-split { grid-template-columns: minmax(0, 1fr); } }
  /* Stays in view below the top bar while the form beside it scrolls. */
  @media (min-width: 901px) { .vd-sticky { position: sticky; top: 84px; max-height: calc(100vh - 104px); overflow-y: auto; } }
  .vd-divider { border: 0; border-top: 1px solid var(--vd-border); margin: 16px 0; }

  /* Buttons */
  .vd-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 36px; padding: 0 14px; border-radius: var(--vd-radius);
    border: 1px solid var(--vd-border); background: var(--vd-card); color: var(--vd-strong); font: 500 14px/1 var(--vd-font); text-decoration: none; cursor: pointer;
    box-shadow: var(--vd-shadow); transition: background-color .15s, border-color .15s; white-space: nowrap; }
  .vd-btn:hover { background: var(--vd-muted); border-color: var(--vd-border-strong); }
  .vd-btn-primary { background: var(--vd-accent); border-color: var(--vd-accent); color: var(--vd-accent-ink); }
  .vd-btn-primary:hover { background: var(--vd-accent); border-color: var(--vd-accent); opacity: .88; }
  .vd-btn-ghost { background: transparent; border-color: transparent; box-shadow: none; }
  .vd-nav .vd-btn-ghost[aria-current] { background: var(--vd-muted); color: var(--vd-strong); }
  .vd-nav .vd-btn-primary[aria-current] { opacity: 1; }
  .vd-btn-sm { min-height: 30px; padding: 0 10px; font-size: 13px; }
  .vd-btn-block { width: 100%; }
  .vd-btn-danger { color: var(--vd-bad); }
  .vd-btn-danger-solid { background: var(--vd-bad); border-color: var(--vd-bad); color: #fff; }
  .vd-btn-danger-solid:hover { background: color-mix(in srgb, var(--vd-bad) 88%, #000); border-color: transparent; }
  .vd-btn[disabled] { opacity: .5; cursor: not-allowed; }
  .vd-btn svg { width: 16px; height: 16px; flex: none; }
  .vd-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .vd-actions > form { margin: 0; }

  /* Forms */
  .vd label, .vd-label { display: block; font-size: 13px; font-weight: 500; color: var(--vd-strong); margin-bottom: 6px; }
  .vd-optional { font-weight: 400; color: var(--vd-faint-ink); }
  .vd-hint { display: block; font-size: 12.5px; color: var(--vd-muted-ink); margin-top: 6px; }
  .vd-hint.vd-warn-text { color: var(--vd-warn); }
  .vd-hint[hidden] { display: none; }
  .vd-error { display: block; font-size: 12.5px; color: var(--vd-bad); margin-top: 6px; }
  .vd input[type=text], .vd input[type=search], .vd input[type=number], .vd select, .vd textarea {
    width: 100%; min-height: 38px; padding: 8px 12px; border-radius: var(--vd-radius); border: 1px solid var(--vd-border);
    background: var(--vd-card); color: var(--vd-ink); font: 14px/1.5 var(--vd-font); box-shadow: var(--vd-shadow); }
  .vd textarea { min-height: 116px; resize: vertical; }
  .vd input::placeholder, .vd textarea::placeholder { color: var(--vd-faint-ink); }
  .vd input:focus, .vd select:focus, .vd textarea:focus { outline: none; border-color: var(--vd-strong); box-shadow: 0 0 0 3px color-mix(in srgb, var(--vd-strong) 12%, transparent); }
  .vd-row { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
  .vd-field-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
  .vd-field-head label { margin: 0; }
  .vd-segments { display: inline-flex; flex-wrap: wrap; gap: 2px; padding: 3px; border-radius: var(--vd-radius); background: var(--vd-muted); border: 1px solid var(--vd-border); }
  .vd-segment { border: 0; background: transparent; color: var(--vd-muted-ink); font: 500 13px/1 var(--vd-font); padding: 8px 12px; border-radius: var(--vd-radius-sm); cursor: pointer; }
  .vd-segment:hover { color: var(--vd-strong); }
  .vd-segment[aria-pressed=true] { background: var(--vd-card); color: var(--vd-strong); box-shadow: var(--vd-shadow); }
  .vd-toggles { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
  .vd .vd-toggle { position: relative; display: flex; align-items: center; gap: 10px; margin: 0; padding: 10px 12px; border-radius: var(--vd-radius);
    border: 1px solid var(--vd-border); background: var(--vd-card); box-shadow: var(--vd-shadow); cursor: pointer; font-weight: 400; transition: border-color .15s; }
  .vd-toggle:hover { border-color: var(--vd-border-strong); }
  .vd-toggle input { position: absolute; opacity: 0; pointer-events: none; }
  .vd-toggle > svg { width: 20px; height: 20px; flex: none; color: var(--vd-muted-ink); }
  .vd-toggle-text { display: flex; flex-direction: column; min-width: 0; flex: 1; line-height: 1.3; }
  .vd-toggle-name { font-size: 13.5px; font-weight: 500; color: var(--vd-strong); }
  .vd-toggle-size { font-size: 11.5px; color: var(--vd-faint-ink); }
  .vd-switch { position: relative; flex: none; width: 30px; height: 18px; border-radius: 99px; background: var(--vd-border-strong); transition: background-color .15s; }
  .vd-switch::after { content: ""; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / .2); transition: transform .15s; }
  .vd-toggle:has(input:checked) { border-color: var(--vd-strong); }
  .vd-toggle:has(input:checked) > svg { color: var(--vd-strong); }
  .vd-toggle input:checked ~ .vd-switch { background: var(--vd-accent); }
  .vd-toggle input:checked ~ .vd-switch::after { transform: translateX(12px); }
  .vd-toggle:has(input:focus-visible) { outline: 2px solid var(--vd-strong); outline-offset: 2px; }
  .vd-ai-note { font-size: 12.5px; color: var(--vd-muted-ink); }
  .vd-ai-note[data-error] { color: var(--vd-bad); }
  .vd-ai-changes { margin: 8px 0 0; padding: 10px 14px 10px 30px; border-radius: var(--vd-radius); background: var(--vd-muted); font-size: 13px; color: var(--vd-ink); }
  .vd-ai-changes li + li { margin-top: 4px; }
  /* A busy button swaps its icon for a ring spinner. */
  .vd-btn[aria-busy="true"], .vd-btn[aria-busy="true"][disabled] { cursor: progress; opacity: .85; }
  .vd-btn[aria-busy="true"] svg { display: none; }
  .vd-btn[aria-busy="true"]::before { content: ""; flex: none; width: 14px; height: 14px; border-radius: 50%;
    border: 2px solid currentColor; border-right-color: transparent; opacity: .85; animation: vd-spin .7s linear infinite; }
  @keyframes vd-spin { to { transform: rotate(360deg); } }
  @media (prefers-reduced-motion: reduce) { .vd-btn[aria-busy="true"]::before { animation-duration: 2s; } }

  /* Tables */
  .vd-scroll { overflow-x: auto; }
  .vd-table { width: 100%; border-collapse: collapse; }
  .vd-table th, .vd-table td { text-align: left; padding: 12px 16px; border-bottom: 1px solid var(--vd-border); vertical-align: middle; }
  .vd-table th { font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: .06em; color: var(--vd-muted-ink); background: var(--vd-muted); }
  .vd-table tr:last-child td { border-bottom: 0; }
  .vd-table tbody tr:hover { background: color-mix(in srgb, var(--vd-muted) 60%, transparent); }
  .vd-table a { text-decoration: none; font-weight: 500; color: var(--vd-strong); }
  .vd-table a:hover { text-decoration: underline; text-underline-offset: 3px; }

  /* Status badges */
  .vd-status { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500; padding: 3px 9px; border-radius: 99px; white-space: nowrap;
    background: var(--vd-muted); color: var(--vd-muted-ink); }
  .vd-status::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .vd-status-ready { background: var(--vd-ok-bg); color: var(--vd-ok); }
  .vd-status-failed { background: var(--vd-bad-bg); color: var(--vd-bad); }
  .vd-status-stopped { background: var(--vd-muted); color: var(--vd-muted-ink); }
  .vd-status-working { background: var(--vd-warn-bg); color: var(--vd-warn); }
  .vd-status-working::before { animation: vd-pulse 1.2s infinite; }
  @keyframes vd-pulse { 50% { opacity: .25; } }
  .vd-pill { display: inline-block; font-size: 12px; font-weight: 500; padding: 2px 8px; border-radius: 99px; border: 1px solid var(--vd-border); color: var(--vd-muted-ink); }

  /* Text */
  .vd-muted { color: var(--vd-muted-ink); }
  .vd-small { font-size: 13px; }
  .vd-num { font-variant-numeric: tabular-nums; }
  .vd-mono { font-family: var(--vd-mono); }
  .vd-check { width: 36px; padding-right: 0 !important; }
  .vd-check input { width: 16px; height: 16px; margin: 0; accent-color: var(--vd-accent); cursor: pointer; vertical-align: middle; }
  .vd-bulk { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: var(--vd-muted); border-bottom: 1px solid var(--vd-border); }
  .vd-bulk[hidden] { display: none; }
  .vd .vd-check-row { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500; cursor: pointer; }
  .vd .vd-check-row input { width: 16px; height: 16px; margin: 0; accent-color: var(--vd-accent); }
  .vd input[type=range] { accent-color: var(--vd-accent); }
  /* Motion. Pages arrive gently: sections rise into place one after another,
     lists follow item by item, and figures pop when they change. Solid
     colours only. Everything is still for people who ask for less motion. */
  @keyframes vd-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
  @keyframes vd-fade-in { from { opacity: 0; } }
  @keyframes vd-pop { 40% { transform: scale(1.06); } }
  @keyframes vd-draw { to { stroke-dashoffset: 0; } }
  @keyframes vd-float { 50% { transform: translateY(-4px); } }
  .vd-main > .vd-wrap > *, .vd-rise { animation: vd-rise .5s cubic-bezier(.2,.7,.2,1) both; }
  .vd-main > .vd-wrap > :nth-child(2) { animation-delay: .06s; }
  .vd-main > .vd-wrap > :nth-child(3) { animation-delay: .12s; }
  .vd-main > .vd-wrap > :nth-child(n+4) { animation-delay: .18s; }
  .vd-grid > *, .vd-stagger > * { animation: vd-rise .45s cubic-bezier(.2,.7,.2,1) both; }
  .vd-table tbody tr { animation: vd-fade-in .4s ease-out both; }
  @for ($i = 2; $i <= 12; $i++)
  .vd-grid > :nth-child({{ $i }}), .vd-stagger > :nth-child({{ $i }}) { animation-delay: {{ ($i - 1) * 45 }}ms; }
  .vd-table tbody tr:nth-child({{ $i }}) { animation-delay: {{ ($i - 1) * 30 }}ms; }
  @endfor
  .vd-pop { animation: vd-pop .4s cubic-bezier(.2,.7,.2,1); }
  .vd-btn { transition: background-color .15s, border-color .15s, transform .12s, box-shadow .15s; }
  .vd-btn:active:not([disabled]) { transform: translateY(1px) scale(.98); }
  .vd-segment { transition: background-color .15s, color .15s, box-shadow .15s; }
  .vd-lift { transition: transform .2s cubic-bezier(.2,.7,.2,1), box-shadow .2s, border-color .2s; }
  .vd-lift:hover { transform: translateY(-3px); box-shadow: var(--vd-shadow-lg); border-color: var(--vd-border-strong); }
  /* Line art beside page titles, drawing itself on. */
  .vd-hero-art { flex: none; width: 76px; height: 76px; color: var(--vd-strong); animation: vd-float 6s ease-in-out 1.6s infinite; }
  .vd-hero-art :is(path, rect, circle, polyline, line) { stroke-dasharray: 220; stroke-dashoffset: 220; animation: vd-draw 1.4s cubic-bezier(.4,0,.2,1) .15s forwards; }
  .vd-art-accent { color: var(--vd-accent); }
  .vd-hero-art .vd-art-dot { stroke-dasharray: none; stroke-dashoffset: 0; animation: vd-fade-in .6s ease-out 1.2s both; }
  @media (max-width: 640px) { .vd-hero-art { width: 56px; height: 56px; } }
  @media (prefers-reduced-motion: reduce) {
    .vd *, .vd *::before, .vd *::after { animation-duration: .01ms !important; animation-delay: 0s !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
    .vd-hero-art :is(path, rect, circle, polyline, line) { stroke-dashoffset: 0; }
  }

  /* Forms in sections: a number, a title, then a few fields. */
  .vd-section { display: grid; gap: 18px; min-width: 0; }
  .vd-section + .vd-section { border-top: 1px solid var(--vd-border); padding-top: 22px; margin-top: 22px; }
  .vd-section-head { display: flex; align-items: center; gap: 10px; }
  .vd-section-num { flex: none; width: 26px; height: 26px; border-radius: 50%; background: var(--vd-accent); color: var(--vd-accent-ink); display: grid; place-items: center; font: 600 12px/1 var(--vd-font); }
  .vd .vd-section-title { font-size: 16px; margin: 0; }
  .vd-field { position: relative; min-width: 0; }
  /* "?" beside a label: the longer explanation, only when asked for. */
  .vd-label-line { display: flex; align-items: center; gap: 2px; margin-bottom: 6px; }
  .vd .vd-label-line > :is(label, .vd-label) { margin: 0; }
  .vd-tip { display: inline-block; vertical-align: middle; margin-left: 4px; }
  .vd .vd-tip > summary { list-style: none; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; width: 18px; height: 18px; padding: 0; border-radius: 50%; border: 1px solid var(--vd-border-strong);
    font: 600 11px/1 var(--vd-font); color: var(--vd-muted-ink); cursor: pointer; transition: background-color .15s, color .15s; }
  .vd .vd-tip > summary::-webkit-details-marker { display: none; }
  /* No open/closed mark: the circle is the whole button, open or not. */
  .vd details.vd-tip > summary::after, .vd details.vd-tip[open] > summary::after { content: none; }
  /* A tip that would open past the bottom of what's visible opens upwards. */
  .vd-tip-body.vd-tip-up { bottom: 100%; margin: 0 0 8px; }
  /* Keeps a "?" on the line of the words it explains. */
  .vd-tip-with { white-space: nowrap; }
  .vd .vd-tip[open] > summary, .vd .vd-tip > summary:hover { background: var(--vd-strong); border-color: var(--vd-strong); color: var(--vd-card); }
  .vd-tip-body { position: absolute; left: 0; right: 0; z-index: 6; margin-top: 8px; padding: 12px 14px; border-radius: var(--vd-radius); background: var(--vd-strong); color: var(--vd-card);
    font: 400 12.5px/1.55 var(--vd-font); box-shadow: var(--vd-shadow-lg); animation: vd-rise .2s ease-out both; max-width: 520px; white-space: normal; text-align: left; }
  .vd .vd-tip-body code { color: inherit; background: color-mix(in srgb, currentColor 16%, transparent); border-color: transparent; }
  /* The field with an open tip sits above the fields after it, which can be
     layers of their own while they fade in. */
  .vd-more[open] > :has(.vd-tip[open]), .vd .vd-field:has(.vd-tip[open]) { position: relative; z-index: 7; }
  .vd-toggle.vd-toggle-wide { padding: 12px 14px; }
  .vd-more { border: 1px dashed var(--vd-border-strong); border-radius: var(--vd-radius-lg); padding: 0 16px; }
  .vd .vd-more > summary { list-style: none; display: flex; align-items: center; gap: 8px; padding: 13px 0; cursor: pointer; font-weight: 500; color: var(--vd-strong); }
  .vd .vd-more > summary::-webkit-details-marker { display: none; }
  .vd .vd-more > summary::after { content: ""; margin-left: auto; width: 8px; height: 8px; border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor; transform: rotate(45deg); transition: transform .2s; }
  .vd .vd-more[open] > summary::after { transform: rotate(-135deg); }
  .vd .vd-more > summary small { font-weight: 400; color: var(--vd-faint-ink); }
  @media (max-width: 640px) { .vd .vd-more > summary small { display: none; } }
  .vd-more[open] { padding-bottom: 16px; }
  .vd-more[open] > :not(summary) { animation: vd-rise .3s ease-out both; }
  .vd-more > * + :not(summary) { margin-top: 16px; }
  /* A style's look, previewed beside its menu. */
  .vd-swatch { display: flex; align-items: center; gap: 10px; margin-top: 8px; padding: 8px 10px; border-radius: var(--vd-radius); background: var(--vd-muted); font-size: 12.5px; color: var(--vd-muted-ink); }
  .vd-swatch-frame { flex: none; width: 46px; height: 30px; border-radius: 5px; border: 1px solid var(--vd-border-strong); display: grid; place-items: end start; padding: 4px; transition: background-color .25s; }
  .vd-swatch-frame span { display: block; width: 22px; height: 5px; border-radius: 3px; transition: background-color .25s; }
  /* The live summary of the video being set up. */
  .vd-recipe { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; font-size: 13px; }
  .vd-recipe li { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .vd-recipe svg { flex: none; width: 16px; height: 16px; color: var(--vd-muted-ink); }
  .vd-recipe span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--vd-strong); }
  .vd-recipe span.vd-changed { animation: vd-pop .35s ease-out; }
  /* Empty states: line art and a sentence. */
  .vd-empty-art { display: block; margin: 0 auto 12px; width: 72px; height: 72px; color: var(--vd-strong); }
  .vd-empty-art :is(path, rect, circle) { stroke-dasharray: 220; stroke-dashoffset: 220; animation: vd-draw 1.4s ease-out .2s forwards; }
  .vd-empty-art .vd-art-dot { stroke-dasharray: none; stroke-dashoffset: 0; }
  /* The full error view, laid out like Laravel's error page. */
  .vd-modal { width: min(1100px, calc(100vw - 32px)); max-height: calc(100dvh - 48px); padding: 0; border: 1px solid var(--vd-border); border-radius: var(--vd-radius-lg);
    background: var(--vd-paper); color: var(--vd-ink); box-shadow: var(--vd-shadow-lg); overflow: auto; }
  .vd-modal::backdrop { background: rgb(23 23 23 / .55); }
  .vd-modal-head { position: sticky; top: 0; z-index: 1; display: flex; gap: 16px; justify-content: space-between; align-items: flex-start; flex-wrap: wrap;
    padding: 20px 24px; background: var(--vd-card); border-bottom: 1px solid var(--vd-border); }
  .vd-modal-head > div:first-child { flex: 1 1 480px; }
  .vd-modal-body { padding: 20px 24px 28px; display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; }
  .vd-error-class { font: 500 13px/1.4 var(--vd-mono); color: var(--vd-muted-ink); margin: 0; overflow-wrap: anywhere; }
  .vd .vd-error-message { font-size: 20px; line-height: 1.35; letter-spacing: -.015em; color: var(--vd-bad); margin: 4px 0 6px; overflow-wrap: anywhere; }
  .vd-error-app-message { font-size: 16px; font-weight: 600; color: var(--vd-strong); margin: 4px 0; overflow-wrap: anywhere; }
  .vd-error-file { font: 13px/1.4 var(--vd-mono); color: var(--vd-ink); margin: 0; overflow-wrap: anywhere; }
  .vd-error-pills { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0 0; }
  .vd-error-pills span { font: 12px/1 var(--vd-mono); padding: 5px 8px; border-radius: 99px; background: var(--vd-muted); color: var(--vd-muted-ink); }
  .vd-error-section { background: var(--vd-card); border: 1px solid var(--vd-border); border-radius: var(--vd-radius-lg); padding: 16px 18px; min-width: 0; }
  .vd-error-section h3 { font-size: 14px; font-weight: 600; color: var(--vd-strong); margin: 0 0 10px; }
  .vd-error-section summary { cursor: pointer; }
  .vd-error-label { font-size: 12px; font-weight: 500; color: var(--vd-muted-ink); margin: 0; }
  .vd-error-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
  .vd-error-shot { display: block; border: 1px solid var(--vd-border); border-radius: var(--vd-radius); overflow: hidden; background: var(--vd-muted); }
  .vd-error-shot img { display: block; width: 100%; height: auto; }
  .vd-error-context { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
  .vd-kv { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 16px; margin: 0; font-size: 13px; }
  .vd-kv dt { color: var(--vd-muted-ink); }
  .vd-kv dd { margin: 0; color: var(--vd-strong); overflow-wrap: anywhere; }
  .vd-error-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 13px; }
  .vd .vd-error-list code { font: 12px/1.5 var(--vd-mono); overflow-wrap: anywhere; background: none; border: 0; padding: 0; color: var(--vd-ink); }
  .vd-browser-log li { display: grid; grid-template-columns: 84px 44px minmax(0, 1fr); gap: 8px; align-items: baseline; }
  .vd-log-kind { font: 500 11px/1.6 var(--vd-mono); text-transform: uppercase; color: var(--vd-muted-ink); }
  .vd-log-error, .vd-log-exception, .vd-log-failed { color: var(--vd-bad); }
  .vd-log-warning { color: var(--vd-warn); }
  .vd-check-shot { display: block; max-width: 520px; border: 1px solid var(--vd-border); border-radius: var(--vd-radius); overflow: hidden; }
  .vd-check-shot img { display: block; width: 100%; height: auto; }
  .vd-spots-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
  .vd-spots-intro { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .vd-spots-intro li { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border: 1px solid var(--vd-border); border-radius: var(--vd-radius); background: var(--vd-muted); font-size: 13px; line-height: 1.5; color: var(--vd-ink); }
  .vd-spots-intro li > span, .vd-howto > li::before { flex: none; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--vd-strong); color: var(--vd-paper); font: 600 12px/1 var(--vd-font); }
  .vd-spots-intro strong { display: block; color: var(--vd-strong); font-weight: 600; }
  @media (max-width: 720px) { .vd-spots-intro { grid-template-columns: minmax(0, 1fr); } }
  .vd-stages { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; font-size: 13px; color: var(--vd-faint-ink); }
  .vd-stages li { display: flex; gap: 8px; align-items: center; }
  .vd-stages li::before { content: ""; flex: none; width: 8px; height: 8px; border-radius: 50%; border: 1.5px solid currentColor; }
  .vd-stages li.is-done { color: var(--vd-muted-ink); }
  .vd-stages li.is-done::before { background: var(--vd-ok); border-color: var(--vd-ok); }
  .vd-stages li.is-now { color: var(--vd-strong); font-weight: 500; }
  .vd-stages li.is-now::before { background: var(--vd-accent); border-color: var(--vd-accent); }
  .vd-spot { display: grid; gap: 14px; padding: 16px; border: 1px solid var(--vd-border); border-radius: var(--vd-radius-lg); background: var(--vd-card); }
  .vd-spot-head { display: flex; gap: 12px; align-items: flex-start; }
  .vd-spot-head h3 { margin: 0; font-size: 15px; line-height: 1.35; color: var(--vd-strong); }
  .vd-spot-num { flex: none; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--vd-strong); color: var(--vd-paper); font: 600 13px/1 var(--vd-font); }
  .vd-spot-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
  .vd-spot-meta a.vd-pill { text-decoration: none; color: var(--vd-ink); }
  .vd-pill.vd-pill-ok { background: var(--vd-ok-bg); color: var(--vd-ok); border-color: transparent; }
  .vd-pill.vd-pill-warn { background: var(--vd-warn-bg); color: var(--vd-warn); border-color: transparent; }
  .vd-spot-preview { display: grid; gap: 10px; }
  .vd-spot-tools { display: flex; flex-wrap: wrap; gap: 8px; }
  .vd-frame { margin: 0; border: 1px solid var(--vd-border-strong); border-radius: var(--vd-radius); overflow: hidden; background: var(--vd-muted); }
  .vd-frame[hidden] { display: none; }
  .vd-frame-mobile, .vd-frame-tablet { max-width: 300px; margin: 0 auto; width: 100%; }
  .vd-frame-bar { display: flex; align-items: center; gap: 5px; padding: 7px 10px; border-bottom: 1px solid var(--vd-border); background: var(--vd-card); }
  .vd-frame-bar i { width: 8px; height: 8px; border-radius: 50%; background: var(--vd-border-strong); }
  .vd-frame-bar span { margin-left: 6px; font: 11px/1 var(--vd-mono); color: var(--vd-muted-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .vd-frame img { display: block; width: 100%; height: auto; }
  .vd-howto { list-style: none; counter-reset: vd-howto; margin: 0; padding: 0; display: grid; gap: 12px; }
  .vd-howto > li { counter-increment: vd-howto; position: relative; padding-left: 34px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .vd-howto > li::before { content: counter(vd-howto); position: absolute; left: 0; top: 0; }
  .vd-howto-title { flex: 1 1 200px; min-width: 0; font-size: 14px; font-weight: 500; color: var(--vd-strong); line-height: 22px; overflow-wrap: anywhere; }
  .vd-howto > li > pre, .vd-howto > li > .vd-hint { flex-basis: 100%; min-width: 0; margin: 0; }
  .vd-spot, .vd-spot-preview, .vd-howto > li { min-width: 0; }
  .vd-diff { margin: 0; padding: 8px 0; background: var(--vd-muted); border: 1px solid var(--vd-border); border-radius: var(--vd-radius); overflow: auto; font: 12px/1.65 var(--vd-mono); color: var(--vd-muted-ink); max-width: 100%; }
  .vd-diff > span { display: block; padding: 0 12px; white-space: pre; }
  .vd-diff b { display: inline-block; min-width: 3.2em; font-weight: 400; color: var(--vd-faint-ink); user-select: none; }
  .vd-diff .is-added { background: var(--vd-ok-bg); color: var(--vd-strong); }
  .vd-diff .is-added b { color: var(--vd-ok); font-weight: 600; }
  .vd-code { margin: 0; padding: 10px 0; background: var(--vd-muted); border: 1px solid var(--vd-border); border-radius: var(--vd-radius); overflow: auto;
    font: 12px/1.6 var(--vd-mono); color: var(--vd-ink); max-width: 100%; }
  .vd-code > span { display: block; padding: 0 12px; white-space: pre; }
  .vd-code b { display: inline-block; min-width: 3.5em; font-weight: 400; color: var(--vd-faint-ink); user-select: none; }
  .vd-code .vd-code-hit { background: var(--vd-bad-bg); color: var(--vd-strong); }
  .vd-code .vd-code-hit b { color: var(--vd-bad); }
  .vd-code-plain { padding: 10px 12px; white-space: pre-wrap; overflow-wrap: anywhere; }
  .vd-frames { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: minmax(0, 1fr); gap: 6px; }
  .vd-frames > li { min-width: 0; }
  .vd-frame { min-width: 0; border: 1px solid var(--vd-border); border-radius: var(--vd-radius); background: var(--vd-card); overflow: hidden; }
  .vd-frame > summary { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; padding: 8px 12px; cursor: pointer; font-size: 13px; }
  .vd-frame[open] > summary { border-bottom: 1px solid var(--vd-border); }
  .vd-frame > .vd-code { border: 0; border-radius: 0 0 var(--vd-radius) var(--vd-radius); }
  .vd-frame > .vd-frames { padding: 6px 12px 10px; }
  .vd-frame-vendor { background: var(--vd-muted); }
  .vd-frame-vendor > summary { color: var(--vd-muted-ink); }
  .vd-frame-call { font: 500 12px/1.5 var(--vd-mono); color: var(--vd-strong); overflow-wrap: anywhere; min-width: 0; }
  .vd-frame-file { font: 12px/1.5 var(--vd-mono); color: var(--vd-muted-ink); overflow-wrap: anywhere; min-width: 0; }
  .vd-frame-line { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 12px; font-size: 12px; padding: 3px 0; border-top: 1px solid var(--vd-border); }
  .vd-frame-line:first-child { border-top: 0; }
  @media (max-width: 720px) {
    .vd-modal { width: 100vw; max-width: 100vw; height: 100dvh; max-height: 100dvh; margin: 0; border-radius: 0; border: 0; }
    .vd-modal-head, .vd-modal-body { padding-left: 16px; padding-right: 16px; }
    .vd-modal-head { position: static; }
    .vd .vd-error-message { font-size: 17px; }
    .vd-error-grid { grid-template-columns: minmax(0, 1fr); }
    .vd-browser-log li { grid-template-columns: 72px minmax(0, 1fr); }
    .vd-browser-log li > span:nth-child(2) { display: none; }
  }
  .vd-progress { margin-top: 14px; display: grid; gap: 6px; max-width: 520px; }
  .vd-progress-track { height: 8px; border-radius: 999px; background: var(--vd-muted); border: 1px solid var(--vd-border); overflow: hidden; }
  .vd-progress-track span { display: block; height: 100%; background: var(--vd-accent); border-radius: 999px; transition: width .6s ease; }
  @media (prefers-reduced-motion: reduce) { .vd-progress-track span { transition: none; } }
  .vd-combo { position: relative; }
  .vd-combo-list { position: absolute; z-index: 20; left: 0; right: 0; top: calc(100% + 4px); max-height: 300px; overflow-y: auto; margin: 0; padding: 4px; list-style: none;
    background: var(--vd-card); border: 1px solid var(--vd-border-strong); border-radius: var(--vd-radius); box-shadow: var(--vd-shadow-lg); }
  .vd-combo-list li { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border-radius: var(--vd-radius-sm); cursor: pointer; min-width: 0; }
  .vd-combo-list li[aria-selected="true"], .vd-combo-list li[role="option"]:hover { background: var(--vd-muted); }
  .vd-combo-title { font-size: 14px; color: var(--vd-strong); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .vd-combo-path { font: 12.5px/1.4 var(--vd-mono); color: var(--vd-muted-ink); overflow-wrap: anywhere; }
  .vd-combo-list .vd-combo-empty { flex-direction: row; align-items: center; gap: 8px; cursor: default; color: var(--vd-muted-ink); font-size: 13px; }
  .vd-combo-spinner { flex: none; width: 12px; height: 12px; border-radius: 50%; border: 2px solid currentColor; border-right-color: transparent; animation: vd-spin .7s linear infinite; }
  .vd-link { background: none; border: 0; padding: 0; font: inherit; color: var(--vd-strong); text-decoration: underline; cursor: pointer; }
  .vd-code { margin: 6px 0 0; padding: 10px 12px; background: var(--vd-muted); border: 1px solid var(--vd-border); border-radius: 8px; font: 12.5px/1.5 var(--vd-mono); white-space: pre-wrap; overflow-wrap: anywhere; color: var(--vd-strong); }
  .vd-code code { background: none; border: 0; padding: 0; font: inherit; color: inherit; }
  .vd-issues { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
  .vd-issues li { display: grid; gap: 2px; }
  .vd-issues .vd-detail { font: 12.5px/1.5 var(--vd-mono); overflow-wrap: anywhere; opacity: .9; }
  .vd-figure { font-size: 30px; font-weight: 600; letter-spacing: -.03em; color: var(--vd-strong); font-variant-numeric: tabular-nums; line-height: 1.15; }
  .vd code { font: 12px/1.4 var(--vd-mono); background: var(--vd-muted); border: 1px solid var(--vd-border); padding: 1px 5px; border-radius: 4px; }
  .vd-stat { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; padding: 7px 0; border-top: 1px solid var(--vd-border); }
  .vd-stat:first-of-type { border-top: 0; }
  .vd-stat dt { color: var(--vd-muted-ink); }
  .vd-stat dd { margin: 0; font-weight: 500; color: var(--vd-strong); text-align: right; }

  /* Notices */
  .vd-flash { padding: 12px 16px; border-radius: var(--vd-radius); margin-bottom: 16px; background: var(--vd-card); border: 1px solid var(--vd-border); box-shadow: var(--vd-shadow); }
  .vd-flash-error { background: var(--vd-bad-bg); border-color: transparent; color: var(--vd-bad); }
  .vd-flash-warn { background: var(--vd-warn-bg); border-color: transparent; color: var(--vd-warn); }
  .vd-star { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .vd-star[hidden] { display: none; }
  .vd-star > span:first-child { flex: 1 1 280px; }
  .vd-star-actions { display: flex; gap: 8px; }
  .vd-setup ul { margin: 8px 0 10px; padding-left: 18px; }
  .vd-setup li { padding: 3px 0; }
  .vd-setup-fix { display: block; color: var(--vd-ink); font-size: 13.5px; }
  .vd-setup-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .vd-lost { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .vd-lost span { flex: 1 1 280px; }

  /* Lists */
  .vd-log { margin: 0; padding: 0; list-style: none; font-size: 13px; max-height: 340px; overflow: auto; }
  .vd-log li { padding: 8px 0; border-bottom: 1px solid var(--vd-border); display: flex; gap: 12px; }
  .vd-log li:last-child { border-bottom: 0; }
  .vd-log time { color: var(--vd-faint-ink); font: 12px/1.6 var(--vd-mono); flex: none; }
  .vd-log span, .vd-log a { min-width: 0; overflow-wrap: anywhere; }
  .vd-log a { text-decoration: none; }
  .vd-log a:hover { text-decoration: underline; text-underline-offset: 3px; }
  .vd-steps { margin: 12px 0 0; padding: 0; list-style: none; counter-reset: step; font-size: 13px; }
  .vd-steps li { counter-increment: step; display: grid; grid-template-columns: 28px 64px minmax(0, 1fr); gap: 8px; padding: 8px 0; border-top: 1px solid var(--vd-border); }
  .vd-steps li::before { content: counter(step); color: var(--vd-faint-ink); font: 12px/1.7 var(--vd-mono); }
  .vd-steps code { justify-self: start; }
  /* The plan editor */
  .vd-plan-steps { margin: 0; padding: 0; list-style: none; display: grid; gap: 10px; }
  .vd-plan-step { display: grid; gap: 10px; padding: 12px 14px; border: 1px solid var(--vd-border); border-radius: var(--vd-radius); background: var(--vd-paper); }
  .vd-plan-step-head { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .vd-plan-step-num { flex: none; min-width: 18px; color: var(--vd-faint-ink); font: 12px/1 var(--vd-mono); }
  .vd-plan-step-sum { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--vd-muted-ink); }
  .vd-plan-step-tools { flex: none; display: flex; gap: 2px; }
  .vd-plan-step-tools .vd-btn { padding: 4px 6px; }
  .vd-plan-step-fields { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); }
  .vd .vd-plan-step-more > summary { justify-content: flex-start; gap: 6px; font-weight: 500; color: var(--vd-muted-ink); }
  .vd .vd-plan-step-more > summary::after { font-size: 14px; }
  .vd-plan-step-more[open] > .vd-plan-step-fields { margin-top: 10px; }
  .vd-plan-target { grid-column: 1 / -1; margin: 0; padding: 10px 12px; border: 1px dashed var(--vd-border-strong); border-radius: var(--vd-radius); display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); }
  .vd-plan-target legend { padding: 0 4px; color: var(--vd-muted-ink); }
  .vd a.vd-segment { display: inline-block; text-decoration: none; }
  /* How a video is watched */
  .vd-watch { display: grid; gap: 6px; }
  .vd-watch[hidden] { display: none; }
  .vd-files { list-style: none; margin: 0; padding: 0; }
  .vd-files li { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; padding: 10px 2px; font-size: 13.5px; }
  .vd-files li + li { border-top: 1px solid var(--vd-border); }
  .vd-files .vd-num { min-width: 40px; color: var(--vd-faint-ink); font-family: var(--vd-mono); font-size: 12px; }
  .vd-files form { margin: 0; }
  .vd-watch-row { display: grid; grid-template-columns: 112px minmax(0, 1fr) 48px; gap: 10px; align-items: center; }
  .vd-watch-track { height: 8px; border-radius: 99px; background: var(--vd-muted); overflow: hidden; }
  .vd-watch-track span { display: block; height: 100%; width: 0; border-radius: 99px; background: var(--vd-accent); transition: width .5s ease; }
  .vd-watch-row [data-count] { text-align: right; color: var(--vd-muted-ink); }
  .vd details > summary { cursor: pointer; font-weight: 600; color: var(--vd-strong); list-style: none; display: flex; align-items: center; justify-content: space-between; }
  .vd details > summary::-webkit-details-marker { display: none; }
  .vd details > summary::after { content: "+"; font: 18px/1 var(--vd-mono); color: var(--vd-muted-ink); }
  .vd details[open] > summary::after { content: "−"; }
  /* A video page's sections fold to their title and a one-line summary. */
  .vd .vd-fold { padding: 0; }
  .vd .vd-fold > summary { gap: 16px; padding: 16px 20px; border-radius: var(--vd-radius-lg); }
  .vd .vd-fold > summary:hover h2 { text-decoration: underline; text-underline-offset: 3px; text-decoration-thickness: 1px; }
  .vd .vd-fold > summary:focus-visible { outline: 2px solid var(--vd-strong); outline-offset: -2px; }
  .vd .vd-fold > summary h2 { margin: 0; }
  .vd-fold-title { min-width: 0; }
  .vd-fold-hint { display: block; margin-top: 3px; font-size: 13px; font-weight: 400; color: var(--vd-muted-ink); overflow-wrap: anywhere; }
  .vd-fold-hint[data-tone="ok"] { color: var(--vd-ok); }
  .vd-fold-hint[data-tone="bad"] { color: var(--vd-bad); }
  .vd-fold-body { padding: 0 20px 20px; min-width: 0; }
  .vd-fold[open] > summary .vd-fold-hint[data-closed-only] { display: none; }
  .vd-fold[open] > .vd-fold-body { animation: vd-rise .25s ease-out both; }
  /* A spot folds the same way, inside its section. */
  .vd .vd-spot { display: block; padding: 0; }
  .vd .vd-spot > summary { padding: 14px 16px; gap: 12px; align-items: flex-start; border-radius: var(--vd-radius-lg); font-weight: 400; }
  .vd .vd-spot > summary::after { margin-top: 4px; }
  .vd-spot-body { display: grid; gap: 14px; padding: 0 16px 16px; min-width: 0; }
  .vd-spots-add { display: grid; gap: 8px; padding: 14px 16px; border-radius: var(--vd-radius-lg); background: var(--vd-paper); border: 1px solid var(--vd-border); min-width: 0; }
  .vd-spots-add-row { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; min-width: 0; }
  .vd-spots-add-row pre { flex: 1 1 260px; min-width: 0; margin: 0; }
  /* The sidebar's settings, under the cost. */
  .vd .vd-settings { margin-top: 12px; border-top: 1px solid var(--vd-border); }
  .vd .vd-settings > summary { padding: 10px 0 0; font-size: 13px; font-weight: 500; color: var(--vd-muted-ink); }

  /* Media */
  .vd-video { width: 100%; aspect-ratio: 16 / 10; border-radius: var(--vd-radius-lg); background: #0e0e0d; display: block; border: 1px solid var(--vd-border); }
  .vd-empty { text-align: center; padding: 56px 20px; color: var(--vd-muted-ink); }
  .vd-empty strong { display: block; color: var(--vd-strong); font-size: 15px; margin-bottom: 4px; }
  .vd-tile { display: block; text-decoration: none; border-radius: var(--vd-radius-lg); overflow: hidden; background: var(--vd-card); border: 1px solid var(--vd-border); box-shadow: var(--vd-shadow); transition: border-color .2s, box-shadow .2s, transform .2s cubic-bezier(.2,.7,.2,1); }
  .vd-tile:hover { border-color: var(--vd-border-strong); box-shadow: var(--vd-shadow-lg); }
  .vd-tile-media { position: relative; }
  .vd-tile img { width: 100%; aspect-ratio: 16 / 10; object-fit: cover; display: block; background: var(--vd-muted); border-bottom: 1px solid var(--vd-border); }
  .vd-tile-time { position: absolute; right: 10px; bottom: 10px; font: 500 12px/1 var(--vd-mono); padding: 4px 6px; border-radius: 4px; background: rgb(23 23 23 / .82); color: #fff; }
  .vd-tile-body { padding: 12px 14px 14px; }
  .vd-tile-title { font-weight: 600; color: var(--vd-strong); letter-spacing: -.01em; }
  .vd-chapters { list-style: none; padding: 0; margin: 0; }
  .vd-guide h3 { font-size: 14px; margin: 18px 0 6px; }
  .vd-guide ol { margin: 0; padding-left: 22px; }
  .vd-guide li { padding: 4px 0; line-height: 1.55; }
  .vd-guide li::marker { color: var(--vd-muted-ink); font-variant-numeric: tabular-nums; }
  .vd-guide-note { margin: 8px 0; padding: 8px 12px; border-left: 3px solid var(--vd-border); color: var(--vd-muted-ink); font-size: 14px; line-height: 1.55; }
  .vd-guide-note strong { color: var(--vd-ink); }
  .vd-chapters li + li { border-top: 1px solid var(--vd-border); }
  .vd-chapters button, .vd-chapters a { all: unset; box-sizing: border-box; cursor: pointer; display: flex; gap: 12px; width: 100%; padding: 9px 2px; font-size: 13.5px; color: var(--vd-ink); }
  .vd-chapters button:hover, .vd-chapters a:hover { color: var(--vd-strong); text-decoration: underline; text-underline-offset: 3px; }
  .vd-chapters .vd-num { color: var(--vd-faint-ink); font-family: var(--vd-mono); font-size: 12px; line-height: 1.8; }
  .vd-search { display: flex; gap: 8px; width: 100%; max-width: 440px; }
  .vd-search input { flex: 1; }
  .vd-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
