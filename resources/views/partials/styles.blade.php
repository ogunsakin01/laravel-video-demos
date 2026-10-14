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
  @media (max-width: 900px) { .vd-split { grid-template-columns: 1fr; } }
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
  .vd-btn[disabled] { opacity: .5; cursor: not-allowed; }
  .vd-btn svg { width: 16px; height: 16px; flex: none; }
  .vd-actions { display: flex; flex-wrap: wrap; gap: 8px; }

  /* Forms */
  .vd label, .vd-label { display: block; font-size: 13px; font-weight: 500; color: var(--vd-strong); margin-bottom: 6px; }
  .vd-optional { font-weight: 400; color: var(--vd-faint-ink); }
  .vd-hint { display: block; font-size: 12.5px; color: var(--vd-muted-ink); margin-top: 6px; }
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
  .vd details summary { cursor: pointer; font-weight: 600; color: var(--vd-strong); list-style: none; display: flex; align-items: center; justify-content: space-between; }
  .vd details summary::-webkit-details-marker { display: none; }
  .vd details summary::after { content: "+"; font: 18px/1 var(--vd-mono); color: var(--vd-muted-ink); }
  .vd details[open] summary::after { content: "−"; }

  /* Media */
  .vd-video { width: 100%; aspect-ratio: 16 / 10; border-radius: var(--vd-radius-lg); background: #0e0e0d; display: block; border: 1px solid var(--vd-border); }
  .vd-empty { text-align: center; padding: 56px 20px; color: var(--vd-muted-ink); }
  .vd-empty strong { display: block; color: var(--vd-strong); font-size: 15px; margin-bottom: 4px; }
  .vd-tile { display: block; text-decoration: none; border-radius: var(--vd-radius-lg); overflow: hidden; background: var(--vd-card); border: 1px solid var(--vd-border); box-shadow: var(--vd-shadow); transition: border-color .15s, box-shadow .15s; }
  .vd-tile:hover { border-color: var(--vd-border-strong); box-shadow: var(--vd-shadow-lg); }
  .vd-tile-media { position: relative; }
  .vd-tile img { width: 100%; aspect-ratio: 16 / 10; object-fit: cover; display: block; background: var(--vd-muted); border-bottom: 1px solid var(--vd-border); }
  .vd-tile-time { position: absolute; right: 10px; bottom: 10px; font: 500 12px/1 var(--vd-mono); padding: 4px 6px; border-radius: 4px; background: rgb(23 23 23 / .82); color: #fff; }
  .vd-tile-body { padding: 12px 14px 14px; }
  .vd-tile-title { font-weight: 600; color: var(--vd-strong); letter-spacing: -.01em; }
  .vd-chapters { list-style: none; padding: 0; margin: 0; }
  .vd-chapters li + li { border-top: 1px solid var(--vd-border); }
  .vd-chapters button, .vd-chapters a { all: unset; box-sizing: border-box; cursor: pointer; display: flex; gap: 12px; width: 100%; padding: 9px 2px; font-size: 13.5px; color: var(--vd-ink); }
  .vd-chapters button:hover, .vd-chapters a:hover { color: var(--vd-strong); text-decoration: underline; text-underline-offset: 3px; }
  .vd-chapters .vd-num { color: var(--vd-faint-ink); font-family: var(--vd-mono); font-size: 12px; line-height: 1.8; }
  .vd-search { display: flex; gap: 8px; width: 100%; max-width: 440px; }
  .vd-search input { flex: 1; }
  .vd-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
