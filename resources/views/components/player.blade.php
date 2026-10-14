{{--
  A published video, for use in your own pages:
  <x-video-demo::player slug="post-a-job" />
  It plays the version that suits the viewer's screen (mobile, tablet, desktop
  or TV, whichever were recorded). device="mobile" always plays that version.
  area="private" also finds private videos. Renders nothing if the video isn't published.
--}}
@props(['slug', 'area' => 'public', 'device' => null])
@php
  $manifest = app(\Ogunsakin01\VideoDemo\Library\Manifest::class);
  $video = $manifest->find($slug, $area);
  if ($video) {
      $sources = collect(\Ogunsakin01\VideoDemo\Library\Manifest::sources($video))
          ->map(fn ($s, $key) => [
              'video' => $manifest->url($video, 'video', $key),
              'poster' => $manifest->url($video, 'poster', $key),
              'width' => $s['width'], 'height' => $s['height'],
          ]);
      $first = $sources[$device] ?? $sources->first();
  }
@endphp
@if ($video)
  <video {{ $attributes->except('style')->merge(['class' => 'vd-video', 'controls' => true, 'preload' => 'metadata', 'playsinline' => true]) }}
    data-vd-sources='@json($sources)' @if ($device) data-vd-device="{{ $device }}" @endif
    poster="{{ $first['poster'] }}" src="{{ $first['video'] }}" title="{{ $video['title'] }}"
    style="{{ $attributes->get('style', 'width:100%;border-radius:12px;background:#0e0e0d;display:block;max-height:82vh') }};aspect-ratio:{{ $first['width'] }}/{{ $first['height'] }}"></video>
  @once
    <script>
      // Play the recorded version closest to this screen: phone, tablet, desktop or TV.
      window.videoDemoPick = window.videoDemoPick || function (sources, forced) {
        if (forced && sources[forced]) return forced;
        const w = window.innerWidth;
        const wanted = w < 600 ? ['mobile', 'tablet', 'desktop', 'tv'] : w < 1024 ? ['tablet', 'mobile', 'desktop', 'tv'] : w >= 1800 ? ['tv', 'desktop', 'tablet', 'mobile'] : ['desktop', 'tv', 'tablet', 'mobile'];
        return wanted.find(d => sources[d]) || Object.keys(sources)[0];
      };
      window.videoDemoShow = window.videoDemoShow || function (el, device) {
        const s = JSON.parse(el.dataset.vdSources)[device];
        if (!s || el.dataset.vdShowing === device) return;
        el.dataset.vdShowing = device;
        el.poster = s.poster;
        el.src = s.video;
        el.style.aspectRatio = s.width + '/' + s.height;
      };
      document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('video[data-vd-sources]').forEach(el => {
          window.videoDemoShow(el, window.videoDemoPick(JSON.parse(el.dataset.vdSources), el.dataset.vdDevice));
        });
      });
    </script>
  @endonce
@endif
