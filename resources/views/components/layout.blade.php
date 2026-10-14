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
  {{ $scripts ?? '' }}
</body>
</html>
