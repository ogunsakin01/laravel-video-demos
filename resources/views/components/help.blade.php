{{--
  The published videos that show the page it is on, behind a "Videos for this
  page" button. Put it in your layout once and every page lists its own:
  <x-video-demo::help />
  route="jobs.create" or route="/jobs/create" lists another page's videos.
  area="private" also lists private videos; :open="true" starts it open.
  Renders nothing on a page no video shows.
--}}
@props(['route' => null, 'area' => 'public', 'label' => 'Videos for this page', 'open' => false])
@php
  $current = request()->route();
  if ($route !== null && str_starts_with($route, '/')) {
      $current = \Ogunsakin01\VideoDemo\Support\Routes::match($route);
      $name = $current?->getName();
      $uri = $current?->uri() ?? $route;
  } else {
      $name = $route ?? $current?->getName();
      $uri = $route === null ? $current?->uri() : null;
  }
  $videos = app(\Ogunsakin01\VideoDemo\Library\Manifest::class)->forRoute($name, $uri, $area);
  $library = config("video-demo.library.$area.enabled") && \Illuminate\Support\Facades\Route::has("video-demo.$area.index") ? route("video-demo.$area.index") : null;
@endphp
@if ($videos !== [])
  <details {{ $attributes->merge(['class' => 'vd-help']) }}@if ($open) open @endif>
    <summary class="vd-help-button">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M10 8.5v7l6-3.5z" fill="currentColor" stroke="none"/></svg>
      <span>{{ $label }}</span>
      <span class="vd-help-count">{{ count($videos) }}</span>
    </summary>
    <div class="vd-help-panel">
      <ul class="vd-help-list">
        @foreach ($videos as $video)
          <li>
            <details class="vd-help-item" @if ($loop->first && count($videos) === 1) open @endif>
              <summary>
                <span class="vd-help-title">{{ $video['title'] }}</span>
                <span class="vd-help-time">{{ gmdate('i:s', (int) round($video['duration'] ?? 0)) }}</span>
              </summary>
              <x-video-demo::player :slug="$video['slug']" :area="$area" preload="none" style="width:100%;border-radius:8px;background:#0e0e0d;display:block;max-height:70vh" />
            </details>
          </li>
        @endforeach
      </ul>
      @if ($library)
        <a class="vd-help-all" href="{{ $library }}">All videos</a>
      @endif
    </div>
  </details>
  @once
    <style>
      .vd-help { --vdh-card: #ffffff; --vdh-ink: #2b2f38; --vdh-strong: #171717; --vdh-muted: #6b6f7b; --vdh-border: #e5e2dc; --vdh-chip: #f4f2ec;
        display: block; max-width: 640px; font: inherit; color: var(--vdh-ink); }
      @media (prefers-color-scheme: dark) {
        .vd-help { --vdh-card: #1c1c1a; --vdh-ink: #e9e7e1; --vdh-strong: #fafaf8; --vdh-muted: #a3a199; --vdh-border: #2e2d29; --vdh-chip: #242421; }
      }
      .vd-help summary { list-style: none; cursor: pointer; }
      .vd-help summary::-webkit-details-marker { display: none; }
      .vd-help-button { display: inline-flex; align-items: center; gap: 8px; padding: 7px 12px; border: 1px solid var(--vdh-border); border-radius: 999px;
        background: var(--vdh-card); color: var(--vdh-strong); font-size: 14px; font-weight: 500; line-height: 1.2; }
      .vd-help-button:hover { border-color: var(--vdh-muted); }
      .vd-help-button:focus-visible, .vd-help-item > summary:focus-visible { outline: 2px solid var(--vdh-strong); outline-offset: 2px; }
      .vd-help-count { min-width: 20px; padding: 1px 6px; border-radius: 999px; background: var(--vdh-chip); color: var(--vdh-muted); font-size: 12px; text-align: center; }
      .vd-help-panel { margin-top: 8px; padding: 6px 14px 12px; border: 1px solid var(--vdh-border); border-radius: 12px; background: var(--vdh-card); }
      .vd-help-list { list-style: none; margin: 0; padding: 0; }
      .vd-help-list > li + li { border-top: 1px solid var(--vdh-border); }
      .vd-help-item > summary { display: flex; gap: 12px; align-items: baseline; padding: 10px 0; color: var(--vdh-strong); font-size: 14px; }
      .vd-help-item[open] > summary { padding-bottom: 8px; }
      .vd-help-item[open] { padding-bottom: 10px; }
      .vd-help-title { flex: 1; min-width: 0; }
      .vd-help-time { flex: none; color: var(--vdh-muted); font-size: 12px; font-variant-numeric: tabular-nums; }
      .vd-help-all { display: inline-block; margin-top: 8px; color: var(--vdh-muted); font-size: 13px; }
    </style>
    <script>
      // A video stops when its row closes.
      document.addEventListener('toggle', e => {
        if (e.target.matches && e.target.matches('.vd-help-item, .vd-help') && !e.target.open) e.target.querySelectorAll('video').forEach(v => v.pause());
      }, true);
    </script>
  @endonce
@endif
