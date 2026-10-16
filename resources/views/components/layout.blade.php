@props(['title' => null, 'badge' => null, 'home' => null])
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $title ? $title.' · ' : '' }}{{ config('video-demo.ui.name') }}</title>
  @php($favicon = \Ogunsakin01\VideoDemo\Support\Favicon::find())
  <link rel="icon" href="{{ $favicon['href'] }}" @if ($favicon['type']) type="{{ $favicon['type'] }}" @endif>
  @include('video-demo::partials.styles')
</head>
<body class="vd">
  <header class="vd-top">
    <div class="vd-wrap">
      <a class="vd-brand" href="{{ $home ?? url('/') }}">
        @if (config('video-demo.ui.logo'))
          <img src="{{ config('video-demo.ui.logo') }}" alt="">
        @else
          <span class="vd-mark" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4.5v15l12.5-7.5z"/></svg></span>
        @endif
        {{ config('video-demo.ui.name') }}
      </a>
      @if ($badge)<span class="vd-tag">{{ $badge }}</span>@endif
      <span class="vd-spacer"></span>
      @isset($nav)<nav class="vd-nav">{{ $nav }}</nav>@endisset
    </div>
  </header>
  <main class="vd-main">
    <div class="vd-wrap">
      @if (session('status'))<div class="vd-flash" role="status">{{ session('status') }}</div>@endif
      @if (session('error'))<div class="vd-flash vd-flash-error" role="alert">{{ session('error') }}</div>@endif
      {{ $slot }}
    </div>
  </main>
  <script>
    // A "?" tip opens downwards, or upwards when there's no room below in what
    // can be seen (the page, or a sidebar that scrolls on its own).
    document.addEventListener('toggle', e => {
      const tip = e.target;
      if (!tip.matches?.('details.vd-tip') || !tip.open) return;
      const body = tip.querySelector('.vd-tip-body');
      if (!body) return;
      body.classList.remove('vd-tip-up');
      let bottom = innerHeight;
      for (let el = tip.parentElement; el; el = el.parentElement) {
        if (/(auto|scroll|hidden)/.test(getComputedStyle(el).overflowY)) { bottom = Math.min(bottom, el.getBoundingClientRect().bottom); break; }
      }
      const box = body.getBoundingClientRect();
      if (box.bottom > bottom && tip.getBoundingClientRect().top - box.height - 16 > 0) body.classList.add('vd-tip-up');
      else if (box.bottom > bottom) body.scrollIntoView({ block: 'nearest' });
    }, true);
    // Forms marked data-busy-form show their button as busy once sent, so it
    // isn't pressed twice. The pressed button's value is kept as it's disabled.
    document.addEventListener('submit', e => {
      const form = e.target;
      if (!form.matches?.('[data-busy-form]') || e.defaultPrevented) return;
      const button = e.submitter || form.querySelector('button');
      if (!button) return;
      if (button.name) form.append(Object.assign(document.createElement('input'), { type: 'hidden', name: button.name, value: button.value }));
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
    });
  </script>
  {{ $scripts ?? '' }}
</body>
</html>
