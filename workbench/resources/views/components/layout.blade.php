{{-- The demo "host app" (Acme HR), styled after Interstack's dashboard. Solid colours only. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }} · Acme HR</title>
  <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
  <link rel="stylesheet" href="https://fonts.bunny.net/css?family=geist:400,500,600,700|geist-mono:400,500&display=swap">
  <style>
    :root { --bg: #f5f5f5; --card: #fff; --ink: #262626; --strong: #171717; --muted: #666; --faint: #a3a3a3; --line: #e5e5e5; --soft: #fafafa; --ok: #008236; --ok-bg: #ecfdf3; }
    * { box-sizing: border-box; font-family: 'Geist', ui-sans-serif, system-ui, sans-serif; }
    body { margin: 0; background: var(--bg); color: var(--ink); font-size: 14px; line-height: 1.55; -webkit-font-smoothing: antialiased; }
    a { color: inherit; }
    .top { position: sticky; top: 0; z-index: 5; background: #fff; border-bottom: 1px solid var(--line); }
    .top-in { max-width: 1180px; margin: 0 auto; padding: 0 20px; height: 60px; display: flex; align-items: center; gap: 28px; }
    .logo { display: flex; align-items: center; gap: 9px; font-weight: 600; font-size: 15px; letter-spacing: -.01em; color: var(--strong); text-decoration: none; }
    .logo span { width: 28px; height: 28px; border-radius: 7px; background: var(--strong); color: #fff; display: grid; place-items: center; font-size: 13px; font-weight: 700; }
    .nav { display: flex; gap: 4px; }
    .nav a { padding: 6px 10px; border-radius: 6px; text-decoration: none; color: #525252; font-weight: 500; }
    .nav a:hover, .nav a[aria-current] { background: #f5f5f5; color: var(--strong); }
    .spacer { flex: 1; }
    .avatar { width: 32px; height: 32px; border-radius: 50%; background: #e5e5e5; color: #404040; display: grid; place-items: center; font-size: 12px; font-weight: 600; }
    .menu-btn { display: none; border: 1px solid var(--line); background: #fff; border-radius: 8px; width: 36px; height: 36px; place-items: center; cursor: pointer; }
    .menu-btn svg { width: 18px; height: 18px; }
    main { max-width: 1180px; margin: 0 auto; padding: 24px 20px 64px; }
    .hero { position: relative; overflow: hidden; background: #fff; border-radius: 8px; padding: 32px; margin-bottom: 20px; }
    .hero::after { content: ""; position: absolute; top: 0; right: 0; bottom: 0; width: 45%; pointer-events: none; opacity: .5;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='22' height='22'%3E%3Ccircle cx='2' cy='2' r='1' fill='%23d4d4d4'/%3E%3C/svg%3E"); }
    .hero > * { position: relative; z-index: 1; }
    .eyebrow { margin: 0 0 10px; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
    h1 { margin: 0; font-size: 34px; line-height: 1.1; font-weight: 600; letter-spacing: -.03em; color: var(--strong); }
    .lead { margin: 10px 0 0; font-size: 15.5px; color: #525252; max-width: 60ch; }
    .grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 20px; align-items: start; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 24px; }
    .card h2 { margin: 0 0 4px; font-size: 16px; font-weight: 600; letter-spacing: -.01em; color: var(--strong); }
    .card .sub { margin: 0 0 18px; color: var(--muted); font-size: 13.5px; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: 8px; border: 1px solid var(--strong);
      background: var(--strong); color: #fff; font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer; }
    .btn:hover { opacity: .88; }
    .btn-light { background: #fff; color: var(--strong); border-color: var(--line); }
    .btn svg { width: 16px; height: 16px; }
    .field { margin-bottom: 18px; }
    .field label, .label { display: block; font-size: 13.5px; font-weight: 500; color: #404040; margin-bottom: 6px; }
    .field .hint { margin-top: 6px; font-size: 12.5px; color: var(--muted); }
    input[type=text], input[type=search], select, textarea { width: 100%; height: 42px; padding: 0 12px; border: 1px solid #d4d4d4; border-radius: 8px; background: #fff; color: var(--ink); font-size: 14px; }
    textarea { height: auto; min-height: 104px; padding: 10px 12px; resize: vertical; }
    input:focus, select:focus, textarea:focus { outline: none; border-color: var(--strong); box-shadow: 0 0 0 3px rgb(23 23 23 / .08); }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .field .check { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; color: var(--ink); cursor: pointer; }
    .field .check input { margin: 0;  width: 16px; height: 16px; accent-color: var(--strong); }
    .actions { display: flex; gap: 10px; align-items: center; margin-top: 6px; }
    .notice { display: flex; gap: 10px; align-items: center; padding: 12px 14px; border-radius: 8px; background: var(--ok-bg); color: var(--ok); font-weight: 500; margin-top: 18px; }
    .notice svg { width: 18px; height: 18px; flex: none; }
    [hidden] { display: none !important; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 12px; font-weight: 500; color: var(--muted); padding: 0 0 10px; border-bottom: 1px solid var(--line); }
    td { padding: 14px 0; border-bottom: 1px solid #f0f0f0; }
    tr:last-child td { border-bottom: 0; }
    td strong { font-weight: 500; color: var(--strong); }
    .pill { display: inline-block; font-size: 12px; font-weight: 500; padding: 2px 9px; border-radius: 99px; background: #f5f5f5; color: #404040; }
    .muted { color: var(--muted); }
    .tips { margin: 0; padding: 0; list-style: none; }
    .tips li { display: flex; gap: 10px; padding: 10px 0; border-top: 1px solid #f0f0f0; font-size: 13.5px; color: #404040; }
    .tips li:first-child { border-top: 0; padding-top: 0; }
    .tips b { flex: none; width: 22px; height: 22px; border-radius: 6px; background: #f5f5f5; display: grid; place-items: center; font-size: 12px; color: var(--strong); }
    .vd-search { display: flex; gap: 8px; margin-top: 14px; }
    .vd-search input { flex: 1; }
    .vd-search button { height: 42px; padding: 0 14px; border-radius: 8px; border: 1px solid var(--line); background: #fff; font-size: 14px; font-weight: 500; cursor: pointer; }
    .vd-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    @media (max-width: 640px) {
      .nav, .avatar { display: none; }
      .menu-btn { display: grid; }
      .top-in { padding: 0 16px; }
      main { padding: 16px 16px 48px; }
      .hero { padding: 22px; }
      h1 { font-size: 27px; }
      .row { grid-template-columns: 1fr; gap: 0; }
      .card { padding: 18px; border-radius: 12px; }
      .nav.open { display: flex; position: absolute; top: 60px; left: 0; right: 0; flex-direction: column; background: #fff; border-bottom: 1px solid var(--line); padding: 8px 12px 12px; }
    }
  
    /* Dark mode, following the device setting */
    @media (prefers-color-scheme: dark) {
      :root { --bg: #0e0e0d; --card: #171716; --ink: #e7e6e1; --strong: #fafaf8; --muted: #a3a199; --faint: #6f6d66; --line: #2a2926; --soft: #1c1b19; --ok: #5fd08f; --ok-bg: #16281d; color-scheme: dark; }
      .top, .hero, .menu-btn, .btn-light, .vd-search button, input[type=text], input[type=search], select, textarea { background: var(--card); }
      .nav.open { background: var(--card); }
      .nav a, .lead, .field label, .label, .tips li { color: #c9c7c0; }
      .nav a:hover, .nav a[aria-current], .pill, .tips b { background: #232220; color: var(--strong); }
      .pill { color: #c9c7c0; }
      .avatar { background: #2a2926; color: #e7e6e1; }
      .logo span, .btn { color: #0e0e0d; }
      input[type=text], input[type=search], select, textarea { border-color: #34332f; }
      input:focus, select:focus, textarea:focus { box-shadow: 0 0 0 3px rgb(250 250 248 / .12); }
      .tips li { border-top-color: #232220; }
    }
  </style>
</head>
<body>
  <header class="top">
    <div class="top-in">
      <a class="logo" href="/"><span>A</span>Acme HR</a>
      <nav class="nav" id="nav" aria-label="Main">
        <a href="/" @if (request()->is('/')) aria-current="page" @endif>Jobs</a>
        <a href="/jobs/create" @if (request()->is('jobs/create')) aria-current="page" @endif>Post a job</a>
        <a href="/">People</a>
        <a href="/help/videos">Help</a>
      </nav>
      <span class="spacer"></span>
      <button class="menu-btn" type="button" aria-label="Menu" aria-controls="nav" onclick="document.getElementById('nav').classList.toggle('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <span class="avatar" aria-hidden="true">DO</span>
    </div>
  </header>
  <main>
    {{ $slot }}
  </main>
</body>
</html>
