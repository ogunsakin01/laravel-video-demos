{{--
  A video folded into a slim bar: "How-to video · 1:02, Post a job, Watch".
  Opening it shows the video; "Minimise" folds it again. It is a <details>
  element, so it works without JavaScript, and its colours come from the page
  around it (currentColor and Canvas), so it suits light and dark pages alike.
  Each viewer's choice is remembered for that video.
  Expects $slug, $title, $seconds, $open, and what player-video needs.
--}}
@php($length = sprintf('%d:%02d', intdiv((int) round($seconds), 60), (int) round($seconds) % 60))
<details class="vdp" data-vd-collapsible="{{ $slug }}" @if ($open) open @endif>
  <summary class="vdp-bar">
    <span class="vdp-play" aria-hidden="true"></span>
    <span class="vdp-text">
      <span class="vdp-kicker">How-to video · {{ $length }}</span>
      <span class="vdp-title">{{ $title }}</span>
    </span>
    <span class="vdp-toggle"><span class="vdp-when-closed">Watch</span><span class="vdp-when-open">Minimise</span></span>
  </summary>
  <div class="vdp-body">
    @include('video-demo::partials.player-video', ['attributes' => $attributes->merge(['style' => 'width:100%;border-radius:8px;background:#0e0e0d;display:block;max-height:70vh']), 'video' => $video, 'sources' => $sources, 'first' => $first, 'device' => $device ?? null])
  </div>
</details>
@once
  <style>
    .vdp { box-sizing: border-box; width: 100%; max-width: 640px; margin: 0 0 16px; border: 1px solid color-mix(in srgb, currentColor 16%, transparent); border-radius: 12px; background: transparent; font: inherit; }
    .vdp *, .vdp *::before { box-sizing: border-box; }
    .vdp-bar { display: flex; align-items: center; gap: 12px; padding: 10px 12px; cursor: pointer; list-style: none; border-radius: 12px; }
    .vdp-bar::-webkit-details-marker { display: none; }
    .vdp-bar:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
    .vdp-play { flex: none; width: 34px; height: 34px; border-radius: 50%; background: currentColor; display: inline-flex; align-items: center; justify-content: center; }
    .vdp-play::before { content: ""; border-left: 10px solid Canvas; border-top: 6px solid transparent; border-bottom: 6px solid transparent; margin-left: 3px; }
    .vdp-text { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 1px; }
    .vdp-kicker { font-size: 12px; opacity: .7; }
    .vdp-title { font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .vdp-toggle { flex: none; font-size: 13px; font-weight: 500; padding: 6px 10px; border-radius: 8px; border: 1px solid color-mix(in srgb, currentColor 22%, transparent); }
    .vdp .vdp-when-open, .vdp[open] .vdp-when-closed { display: none; }
    .vdp[open] .vdp-when-open { display: inline; }
    .vdp-body { padding: 0 12px 12px; }
  </style>
  <script>
    // Remember whether each viewer keeps the video open or minimised.
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('details[data-vd-collapsible]').forEach(el => {
        const key = 'video-demo:open:' + el.dataset.vdCollapsible;
        try {
          const saved = localStorage.getItem(key);
          if (saved !== null) el.open = saved === '1';
        } catch {}
        el.addEventListener('toggle', () => {
          try { localStorage.setItem(key, el.open ? '1' : '0'); } catch {}
          if (!el.open) el.querySelector('video')?.pause();
        });
      });
    });
  </script>
@endonce
