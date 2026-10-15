{{--
  A published video, for use in your own pages:
  <x-video-demo::player slug="post-a-job" />
  It plays the version that suits the viewer's screen (mobile, tablet, desktop
  or TV, whichever were recorded). device="mobile" always plays that version.
  area="private" also finds private videos. Renders nothing if the video isn't published.

  collapsible shows a slim "how-to video" bar that opens into the video and
  can be minimised again, so it fits into a page without taking it over. It
  starts minimised (add open to start it open) and remembers each viewer's
  choice. It takes its colours from your page.

  A video made in other languages plays in the language of the page (the
  app's locale) when there is a version in it. language="fr" picks one;
  :language="false" always plays the video as named.
--}}
@props(['slug', 'area' => 'public', 'device' => null, 'collapsible' => false, 'open' => false, 'language' => null])
@php
  $manifest = app(\Ogunsakin01\VideoDemo\Library\Manifest::class);
  $video = $manifest->find($slug, $area);
  if ($video && $language !== false) {
      $video = $manifest->localized($video, $language ?? app()->getLocale(), $area);
  }
  if ($video) {
      $sources = collect(\Ogunsakin01\VideoDemo\Library\Manifest::sources($video))
          ->map(fn ($s, $key) => [
              'video' => $manifest->url($video, 'video', $key),
              'poster' => $manifest->url($video, 'poster', $key),
              'subtitles' => $manifest->url($video, 'subtitles', $key),
              'width' => $s['width'], 'height' => $s['height'],
          ]);
      $first = $sources[$device] ?? $sources->first();
      // Where the player counts plays, when the app turns that on.
      $stats = \Ogunsakin01\VideoDemo\Library\Stats::enabled() && \Illuminate\Support\Facades\Route::has("video-demo.$area.events")
          ? route("video-demo.$area.events", $video['slug'], false)
          : null;
  }
@endphp
@if ($video)
  @if ($collapsible)
    @include('video-demo::partials.collapsible-player', ['slug' => $slug, 'title' => $video['title'], 'seconds' => $video['duration'] ?? 0, 'open' => $open, 'attributes' => $attributes, 'video' => $video, 'sources' => $sources, 'first' => $first, 'device' => $device])
  @else
    @include('video-demo::partials.player-video', ['attributes' => $attributes, 'video' => $video, 'sources' => $sources, 'first' => $first, 'device' => $device])
  @endif
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
        // Each version has its own subtitles, timed to its steps.
        const track = el.querySelector('track');
        if (track && s.subtitles) track.src = s.subtitles;
        el.style.aspectRatio = s.width + '/' + s.height;
      };
      document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('video[data-vd-sources]').forEach(el => {
          window.videoDemoShow(el, window.videoDemoPick(JSON.parse(el.dataset.vdSources), el.dataset.vdDevice));
        });
        // Counts a play and each quarter reached, once per page view, when the app turns stats on.
        document.querySelectorAll('video[data-vd-stats]').forEach(el => {
          const sent = new Set();
          const send = event => {
            if (sent.has(event) || !navigator.sendBeacon) return;
            sent.add(event);
            const body = new FormData();
            body.append('event', event);
            body.append('_token', el.dataset.vdToken || '');
            navigator.sendBeacon(el.dataset.vdStats, body);
          };
          el.addEventListener('play', () => send('play'));
          el.addEventListener('timeupdate', () => {
            const done = el.duration ? el.currentTime / el.duration : 0;
            [25, 50, 75].forEach(q => { if (done >= q / 100) send(String(q)); });
          });
          el.addEventListener('ended', () => send('100'));
        });
      });
    </script>
  @endonce
@endif
